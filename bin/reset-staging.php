<?php
declare(strict_types=1);
/** Staging-only destructive maintenance. NEVER copy to public_html. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app_private/api.php';
function sf_reset_find_admin(array $s,string $selector):array{
    $selector=strtolower(trim($selector));$matches=[];
    if($selector==='')throw new RuntimeException('Administrator identifier required');
    foreach($s['users']??[] as $u){
        $email=strtolower((string)($u['email']??''));
        $login=strtolower((string)($u['username']??$u['login']??''));
        if($email===$selector||explode('@',$email)[0]===$selector||$login===$selector)$matches[]=$u;
    }
    if(count($matches)!==1)throw new RuntimeException('Preserved account must match exactly one user');
    $admin=$matches[0];
    if(empty($admin['active'])||empty($admin['password_hash']))throw new RuntimeException('Preserved account must be active');
    $hasRole=false;
    foreach($s['memberships']??[] as $m)if(($m['user_id']??null)===$admin['id']&&($m['role']??'')==='Admin'&&sf_staff_enabled($m)){$hasRole=true;break;}
    if(!$hasRole)throw new RuntimeException('Preserved account is not an enabled admin');
    return $admin;
}
function sf_reset_snapshot_hash(array $s):string{
    // Fingerprint exactly what the operator previews. Ignore GUID backfills
    // performed when the store is opened for an exclusive write.
    $normalize=static function(mixed $v)use(&$normalize):mixed{
        if(!is_array($v))return $v;
        foreach($v as $k=>$item){
            if($k==='guid'){unset($v[$k]);continue;}
            $v[$k]=$normalize($item);
        }
        return $v;
    };
    return hash('sha256',json_encode($normalize($s),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
function sf_reset_preview(array $s,string $selector):array{
    $admin=sf_reset_find_admin($s,$selector);$counts=[];
    foreach($s as $k=>$v)if(is_array($v))$counts[$k]=count($v);
    ksort($counts);
    return ['keep_id'=>$admin['id'],'keep_email'=>$admin['email'],'fingerprint'=>sf_reset_snapshot_hash($s),'counts'=>$counts,
        'result'=>'One retained admin, one new empty school, no other users/records/sessions'];
}
function sf_reset_replace(array &$s,string $selector):array{
    $admin=sf_reset_find_admin($s,$selector);$new=SchoolFlowStore::blank();
    $new['counters']=$s['counters']??[]; // Avoid recycling external numeric IDs.
    $new['users']=[$admin];
    $sid=sf_id($new,'schools');
    $new['schools']=[['id'=>$sid,'guid'=>sf_uuid(),'name'=>'Administration (Clean Workspace)','created_at'=>sf_time()]];
    $new['memberships']=[['school_id'=>$sid,'user_id'=>$admin['id'],'role'=>'Admin','enabled'=>true]];
    sf_event($new,$sid,null,$admin['id'],'staging_reset','Staging records reset; existing administrator retained');
    $s=$new;
    return ['school_id'=>$sid,'admin_id'=>$admin['id']];
}
function sf_reset_run(SchoolFlowStore $store,string $dir,string $selector,string $expected):array{
    return $store->transaction(static function(array &$s)use($dir,$selector,$expected):array{
        $preview=sf_reset_preview($s,$selector);
        if(!hash_equals($preview['fingerprint'],$expected))throw new RuntimeException('Store changed since preview; reset cancelled');
        $backup=$dir.'/backup-before-stage-reset-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        if(!mkdir($backup,0700))throw new RuntimeException('Backup directory creation failed');
        foreach(['store.json','messaging.key','public-form.key'] as $filename){
            $from=$dir.'/'.$filename;
            if(!is_file($from)){
                if($filename==='store.json')throw new RuntimeException('Store missing; no reset');
                continue;
            }
            $to=$backup.'/'.$filename;
            if(!copy($from,$to)||!chmod($to,0600)||
                !hash_equals((string)hash_file('sha256',$from),(string)hash_file('sha256',$to)))
                throw new RuntimeException('Backup verification failed; reset cancelled');
        }
        $result=sf_reset_replace($s,$selector);
        return $result+['backup_directory'=>$backup,'previous_counts'=>$preview['counts']];
    },true);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==realpath(__FILE__))return;
$args=getopt('',['data-dir:','keep:','expected-sha256:','confirm:','execute']);
$dir=realpath((string)($args['data-dir']??''));$keep=(string)($args['keep']??'');
try{
    if($dir===false||!preg_match('~(?:^|/)private_html/scrm/data$~',$dir)||!is_file($dir.'/store.json'))
        throw new RuntimeException('Specify an existing staging /private_html/scrm/data path');
    if($keep==='')throw new RuntimeException('--keep=in.stg.adm is required');
    $store=new SchoolFlowStore($dir);
    if(!isset($args['execute'])){
        $preview=$store->transaction(static fn(array $s)=>sf_reset_preview($s,$keep));
        echo json_encode($preview,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if(getenv('SCHOOLFLOW_STAGING_RESET')!=='stage.digiti.in')
        throw new RuntimeException('Set SCHOOLFLOW_STAGING_RESET=stage.digiti.in');
    if(trim((string)@file_get_contents($dir.'/.staging-reset-authorization'))!=='stage.digiti.in')
        throw new RuntimeException('Staging operator authorization marker missing');
    if(($args['confirm']??null)!=='RESET-STAGING-KEEP-ADMIN')
        throw new RuntimeException('Explicit reset confirmation missing');
    $sha=strtolower((string)($args['expected-sha256']??''));
    if(!preg_match('/^[0-9a-f]{64}$/D',$sha))throw new RuntimeException('Supply the current preview fingerprint');
    echo json_encode(sf_reset_run($store,$dir,$keep,$sha),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Reset stopped: ".$e->getMessage()."\n");exit(2);}
