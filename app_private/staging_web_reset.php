<?php
declare(strict_types=1);
/**
 * Browser-only, one-time staging reset. Registered within authenticated API
 * transaction, never as a standalone unauthenticated maintenance endpoint.
 * No reset operation is available on production hostnames.
 */
const SF_WEB_STAGE_HOST = 'stage.digiti.in';
const SF_WEB_STAGE_KEEP = 'in.stg.adm';
const SF_WEB_STAGE_CONFIRM = 'RESET STAGING KEEP IN.STG.ADM';

function sf_web_stage_keeper(array $user): bool {
    $email=strtolower(trim((string)($user['email']??'')));
    $login=strtolower(trim((string)($user['username']??$user['login']??'')));
    return ($email!=='' && explode('@',$email,2)[0]===SF_WEB_STAGE_KEEP)
        || $login===SF_WEB_STAGE_KEEP;
}
function sf_web_stage_permit(array $s,array $user): void {
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    if($host!==SF_WEB_STAGE_HOST)sf_fail(404,'Not found');
    if(!sf_web_stage_keeper($user) || empty($user['active']))sf_fail(403,'This operation requires the preserved staging administrator');
    $admins=0;
    foreach($s['users']??[] as $candidate)if(sf_web_stage_keeper($candidate))$admins++;
    if($admins!==1)sf_fail(409,'The administrator identity is not unique; reset is blocked');
    $role=false;
    foreach($s['memberships']??[] as $m)if(
        ($m['user_id']??null)===$user['id']&&($m['role']??'')==='Admin'&&sf_staff_enabled($m) &&
        sf_find($s['schools']??[],(int)($m['school_id']??0))!==null
    ){$role=true;break;}
    if(!$role)sf_fail(403,'An enabled school administrator membership is required');
}
function sf_web_stage_fingerprint(array $s):string{
    // GUID backfill during exclusive transactions must not invalidate preview.
    $normalise=static function(mixed $v)use(&$normalise):mixed{
        if(!is_array($v))return $v;
        foreach($v as $k=>$item){
            if($k==='guid'){unset($v[$k]);continue;}
            $v[$k]=$normalise($item);
        }
        return $v;
    };
    return hash('sha256',json_encode($normalise($s),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
}
function sf_web_stage_preview(array $s,array $user):array{
    sf_web_stage_permit($s,$user);
    $countKeys=['users','schools','memberships','leads','parent_profiles','sites','group_sites',
        'tasks','calls','visits','applications','admission_documents','message_attempts','notification_queue',
        'sessions','audit_logs','public_chat_settings','public_counsellor_topics'];
    $counts=[];foreach($countKeys as $k)$counts[$k]=count($s[$k]??[]);
    return ['allowed'=>true,'keep_email'=>$user['email']??'','keep_user_id'=>$user['id'],
        'fingerprint'=>sf_web_stage_fingerprint($s),'counts'=>$counts,
        'notice'=>'Existing schools and every other account will be deleted. One new empty Admin workspace is created. All sessions are revoked.'];
}
function sf_web_stage_backup(string $dir):string{
    $source=$dir.'/store.json';
    if(!is_file($source)||!is_readable($source))sf_fail(503,'Private data store is unavailable; reset cancelled');
    if(!is_dir($dir)||!is_writable($dir))sf_fail(503,'Private data directory is unavailable');
    $base='backup-before-web-reset-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
    $folder=$dir.'/'.$base;
    if(!@mkdir($folder,0700))sf_fail(503,'Private backup directory could not be created');
    foreach(['store.json','messaging.key','public-form.key'] as $file){
        $origin=$dir.'/'.$file;
        if(!is_file($origin)){
            if($file==='store.json')sf_fail(503,'Data store is missing; reset cancelled');
            continue;
        }
        $copy=$folder.'/'.$file;
        if(!@copy($origin,$copy)||!@chmod($copy,0600)||
            !hash_equals((string)hash_file('sha256',$origin),(string)hash_file('sha256',$copy)))
            sf_fail(503,'Private backup verification failed; reset cancelled');
    }
    return $base;
}
function sf_web_stage_execute(array &$s,array $user,array $payload,SchoolFlowStore $store):array{
    $preview=sf_web_stage_preview($s,$user);
    if(($payload['confirmation']??null)!==SF_WEB_STAGE_CONFIRM)
        sf_fail(422,'Type the full staging reset confirmation exactly');
    $fingerprint=$payload['fingerprint']??null;
    if(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)
        ||!hash_equals($preview['fingerprint'],$fingerprint))
        sf_fail(409,'Staging records changed. Refresh the preview and try again');
    $password=$payload['password']??null;
    if(!is_string($password)||$password===''||strlen($password)>1024||
        !password_verify($password,(string)($user['password_hash']??'')))
        sf_fail(403,'Current administrator password was not verified');
    // Session cannot be resumed after the reset.
    $base=sf_web_stage_backup($store->dataDirectory());
    $new=SchoolFlowStore::blank();
    $new['counters']=$s['counters']??[];
    // Never reuse a school identifier from an existing source installation.
    $maxSchool=0;
    foreach($s['schools']??[] as $school)$maxSchool=max($maxSchool,(int)($school['id']??0));
    $new['counters']['schools']=max((int)($new['counters']['schools']??0),$maxSchool);
    $new['users']=[$user];
    $schoolId=sf_id($new,'schools');
    $new['schools']=[['id'=>$schoolId,'guid'=>sf_uuid(),
       'name'=>'Administration (Clean Workspace)','created_at'=>sf_time()]];
    $new['memberships']=[['school_id'=>$schoolId,'user_id'=>$user['id'],
       'role'=>'Admin','enabled'=>true,'guid'=>sf_uuid()]];
    sf_event($new,$schoolId,null,(int)$user['id'],'staging_web_reset','Old staging records cleared; administrator retained');
    $s=$new;
    return [200,['reset_complete'=>true,'kept_account'=>$user['email']??'',
        'new_school_id'=>$schoolId,'private_backup_created'=>true,
        'backup_reference'=>$base,'sessions_revoked'=>true]];
}
function sf_web_stage_handle(array &$s,array $user,string $method,array $payload,SchoolFlowStore $store):array{
    if($method==='GET')return [200,sf_web_stage_preview($s,$user)];
    if($method==='POST')return sf_web_stage_execute($s,$user,$payload,$store);
    sf_fail(405,'Method not allowed');
}
