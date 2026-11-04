<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-lifecycle-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function lc(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Lifecycle: '.$message);}
function lc_api(string $method,string $path,array $payload=[],string $auth='admin',
    string $idem=''):array{
    global $store;
    try{return sf_handle($method,$path,$payload,
        ['authorization'=>'Bearer '.$auth,'origin'=>'https://school.test',
            'idempotency-key'=>$idem],'198.51.100.21',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void{
        foreach(['School A','School B','Other School'] as $name)
            $s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name,'created_at'=>sf_time()];
        foreach([['admin',1,'Admin'],['admin',2,'Admin'],
                 ['staff',1,'Counsellor'],['outside',3,'Admin']] as [$key,$sid,$role]){
            $id=['admin'=>1,'staff'=>2,'outside'=>3][$key];
            if(!sf_find($s['users'],$id))$s['users'][]=[
                'id'=>$id,'name'=>$key,'email'=>$key.'@example.invalid','active'=>true];
            $s['memberships'][]=['user_id'=>$id,'school_id'=>$sid,'role'=>$role];
        }
        foreach(['admin','staff','outside'] as $i=>$token)
            $s['sessions'][]=['user_id'=>$i+1,'token_hash'=>sf_hash($token),
                'expires_at'=>time()+3600];
        $s['counters']['users']=3;
    },true);
    [$created,$group]=lc_api('POST','/api/group-sites',[
        'name'=>'School Group','domain'=>'school.test','school_ids'=>[1,2]]);
    lc($created===201,'create two-school public group');
    $gid=$group['id'];$form=$group['guid'];$uri='/api/group-sites/'.$gid;
    [$cs,$config]=lc_api('GET','/api/public/forms/'.$form.'/config');
    lc($cs===200 && $config['form_surfaces']===
        ['inline_enabled'=>true,'group_popup_enabled'=>true,'detailed_popup_enabled'=>true],
        'old group embeds remain enabled by default');
    lc(lc_api('PATCH',$uri.'/form-surfaces',['group_popup_enabled'=>false],'staff')[0]===403,
        'non-admin cannot disable group popup');
    lc(lc_api('PATCH',$uri.'/form-surfaces',['fake_enabled'=>false])[0]===422,
        'unknown setting rejected');
    lc(lc_api('PATCH',$uri.'/form-surfaces',['inline_enabled'=>0])[0]===422,
        'nonboolean controls rejected');
    [$upd,$cfg]=lc_api('PATCH',$uri.'/form-surfaces',[
        'inline_enabled'=>false,'group_popup_enabled'=>false]);
    lc($upd===200&&!$cfg['inline_enabled']&&!$cfg['group_popup_enabled']&&
       $cfg['detailed_popup_enabled'],'inline and group popup controlled independently');
    lc(lc_api('GET','/api/public/forms/'.$form.'/config')[1]['form_surfaces']===$cfg,
        'public configuration reflects disabled form surfaces');
    lc(lc_api('PATCH',$uri.'/form-surfaces',['inline_enabled'=>true])[0]===200,
        'inline embed can be restored without changing popup');
    $payload=['student_name'=>'Test Child','parent_name'=>'Test Guardian','phone'=>'9876543210',
        'grade'=>'Nursery','consent'=>true,'school_code'=>'campus-1'];
    [$leadCode,$lead]=lc_api('POST','/api/v1/inbound-leads',$payload,
        $group['api_key'],'group-test-00001');
    lc($leadCode===201,'original group can receive lead');
    $snap=$store->transaction(static fn(array $s):array=>$s);
    $sourceId=(int)$snap['leads'][0]['site_id'];
    lc(lc_api('DELETE',$uri,['confirm_guid'=>'wrong'])[0]===422,
        'group cannot be removed without exact public GUID');
    lc(lc_api('DELETE',$uri,['confirm_guid'=>$form],'staff')[0]===403,
        'non-admin cannot remove group');
    lc(lc_api('DELETE',$uri,['confirm_guid'=>$form],'outside')[0]===404,
        'foreign-school admin cannot remove group');
    [$removed,$res]=lc_api('DELETE',$uri,['confirm_guid'=>$form]);
    lc($removed===200&&$res['removed']&&$res['historical_leads_retained']===1,
        'remove form with accurate historical data count');
    lc(lc_api('GET','/api/group-sites')[1]===[],'deleted group not listed');
    lc(lc_api('GET','/api/public/forms/'.$form.'/config')[0]===403,
        'removed public form immediately denies config');
    lc(lc_api('POST','/api/v1/inbound-leads',$payload,
        $group['api_key'],'group-test-00002')[0]===401,
        'old group private API credential revoked');
    lc(lc_api('DELETE',$uri,['confirm_guid'=>$form])[0]===404,
        'removed group cannot be accessed again');
    $snapshot=$store->transaction(static fn(array $s):array=>$s);
    lc(count($snapshot['leads'])===1&&
       count(array_filter($snapshot['sites'],
           fn($s)=>$s['id']===$sourceId&&!empty($s['removed_at'])&&!$s['enabled']))===1,
       'referenced source remains inactive for attribution');
    lc(count($snapshot['audit_logs']??[])>0,'append-only audit retained');
    lc(lc_api('GET','/api/schools/1/sites')[1]===[],
        'deleted group route hidden from school websites list');
    lc(count(lc_api('GET','/api/schools/1/leads')[1])===1,
        'historic lead still visible in school inbox');
    lc(lc_api('GET','/api/schools/1/leads')[1][0]['source_name']!=='',
        'historic lead still has source attribution');

    [$status,$site]=lc_api('POST','/api/schools/1/sites',[
        'name'=>'School WordPress','domain'=>'website.test','platform'=>'WordPress']);
    lc($status===201,'individual website connected');
    $source=$site['id'];
    $sourceData=$store->transaction(static fn(array $s)=>sf_source($s,$source));
    lc(lc_api('DELETE','/api/schools/1/sites/'.$source,['confirm_guid'=>'bad'])[0]===422,
        'website deletion requires exact GUID');
    lc(lc_api('DELETE','/api/schools/1/sites/'.$source,
        ['confirm_guid'=>$sourceData['guid']],'staff')[0]===403,'staff cannot delete website');
    lc(lc_api('DELETE','/api/schools/1/sites/'.$source,
        ['confirm_guid'=>$sourceData['guid']],'outside')[0]===403,'foreign admin cannot delete website');
    [$deleted,$result]=lc_api('DELETE','/api/schools/1/sites/'.$source,
        ['confirm_guid'=>$sourceData['guid']]);
    lc($deleted===200&&$result['historical_leads_retained']===0,
        'unused website permanently removed');
    $snapshot=$store->transaction(static fn(array $s):array=>$s);
    lc(sf_source($snapshot,$source)===null,'unused source no longer consumes active storage');

    [$second,$usedSite]=lc_api('POST','/api/schools/1/sites',[
        'name'=>'School Website With Leads','domain'=>'leads.test','platform'=>'WordPress']);
    lc($second===201,'second website connected');
    $usedId=$usedSite['id'];
    $siteRow=$store->transaction(static fn(array $s)=>sf_source($s,$usedId));
    $leadBody=['student_name'=>'Another Child','parent_name'=>'Parent',
        'phone'=>'9876543210','grade'=>'I','consent'=>true];
    lc(lc_api('POST','/api/v1/inbound-leads',$leadBody,
        $usedSite['api_key'],'site-test-00001')[0]===201,'site captures enquiry');
    [$removed2,$res2]=lc_api('DELETE','/api/schools/1/sites/'.$usedId,
        ['confirm_guid'=>$siteRow['guid']]);
    lc($removed2===200&&$res2['historical_leads_retained']===1,
        'used website removed without deleting lead');
    lc(lc_api('POST','/api/v1/inbound-leads',$leadBody,
        $usedSite['api_key'],'site-test-00002')[0]===401,
        'old individual private key revoked');
    $snapshot=$store->transaction(static fn(array $s):array=>$s);
    lc(!empty(sf_source($snapshot,$usedId)['removed_at'])&&
       count($snapshot['leads'])===2,'referenced source tombstone preserved');
    lc(lc_api('GET','/api/schools/1/sites')[1]===[],
        'removed websites do not clutter active workspace');
    echo "PASS: form-surface switches, group revoke, individual website removal, permissions, historic leads, minimal retention\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
