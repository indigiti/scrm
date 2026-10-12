<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$tmp=sys_get_temp_dir().'/schoolflow-demo-test-'.bin2hex(random_bytes(6));
mkdir($tmp,0700,true);
$store=new SchoolFlowStore($tmp);
function dm_assert(bool $ok, string $message): void {if (!$ok)throw new RuntimeException('Demo check failed: '.$message);}
function dm_call(string $method,string $path,array $payload=[],string $token=''): array {
    global $store;
    try{return sf_handle($method,$path,$payload,['authorization'=>$token?'Bearer '.$token:''], '198.51.100.12',$store);}
    catch (SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
    $store->transaction(function (&$s){
        $users=[['Demo Admin','admin@example.invalid','Admin',1],['Demo Counsellor','staff@example.invalid','Counsellor',1],['Other Admin','other@example.invalid','Admin',2]];
        foreach ($users as [$name,$email,$role,$schoolId]) {
            $userId=sf_id($s,'users');
            $s['users'][]=['id'=>$userId,'name'=>$name,'email'=>$email,'password_hash'=>password_hash('not-a-real-password-2026',PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
            $s['memberships'][]=['school_id'=>$schoolId,'user_id'=>$userId,'role'=>$role];
            $s['sessions'][]=['token_hash'=>sf_hash('token'.$userId),'user_id'=>$userId,'expires_at'=>time()+3600];
        }
        foreach(['Test School','Different School'] as $n){$s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$n,'created_at'=>sf_time()];}
    },true);
    $admin='token1';$staff='token2';$other='token3';
    dm_assert(dm_call('GET','/api/schools/1/demo-data',[],$staff)[0]===403,'staff cannot see demo management');
    dm_assert(dm_call('POST','/api/schools/1/demo-data/seed',[],$staff)[0]===403,'staff cannot seed');
    dm_assert(dm_call('POST','/api/schools/1/demo-data/seed',[],$other)[0]===403,'other school cannot seed');
    dm_assert(dm_call('GET','/api/schools/1/demo-data',[],$admin)[1]['leads']===0,'initial blank counts');
    [$code,$seed]=dm_call('POST','/api/schools/1/demo-data/seed',[],$admin);
    dm_assert($code===201 && $seed['leads']===18 && $seed['sites']===3,'seed creates 18 leads, 3 sources');
    dm_assert(dm_call('POST','/api/schools/1/demo-data/seed',[],$admin)[0]===409,'no accidental duplicate seed');
    [$code,$leads]=dm_call('GET','/api/schools/1/leads',[],$admin);
    dm_assert($code===200 && count($leads)===18 && count(array_filter($leads,fn($l)=>!empty($l['is_demo'])))===18,'demo marker and lists');
    $stages=array_unique(array_column($leads,'status'));
    dm_assert(count($stages)===7,'all admissions stages');
    dm_assert(count(array_filter($leads,fn($l)=>$l['followup_date']!==''))>=12,'followup example data');
    [$code,$sources]=dm_call('GET','/api/schools/1/sites',[],$admin);
    dm_assert($code===200 && count($sources)===3 && count(array_filter($sources,fn($s)=>$s['enabled']))===0,'three disabled demo websites');
    dm_assert(!isset($sources[0]['key_hash']),'website secrets excluded');
    dm_assert(dm_call('POST','/api/schools/1/demo-data/clear',[],$admin)[0]===422,'confirmation required');
    // Real lead may reference a demo source; it must survive along with its source.
    [$code,$real]=dm_call('POST','/api/schools/1/leads',['student_name'=>'Actual enquiry','parent_name'=>'Real Parent','phone'=>'+91 9900000999','grade'=>'Grade 1','site_id'=>$sources[0]['id']],$admin);
    dm_assert($code===201 && empty($real['is_demo']),'real enquiry created independently');
    [$code,$clear]=dm_call('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'],$admin);
    dm_assert($code===200 && $clear['removed_leads']===18 && $clear['removed_sites']===2 && $clear['kept_demo_sites_with_real_leads']===1,'clear selectively retains referenced website');
    [$code,$left]=dm_call('GET','/api/schools/1/leads',[],$admin);
    dm_assert($code===200 && count($left)===1 && $left[0]['id']===$real['id'],'real enquiry survives');
    dm_assert(dm_call('GET','/api/schools/1/demo-data',[],$admin)[1]['has_demo_data']===false,'retained source no longer demo-tagged');
    [$code,$siteLeft]=dm_call('GET','/api/schools/1/sites',[],$admin);
    dm_assert(count($siteLeft)===1 && $siteLeft[0]['id']===$real['site_id'],'retained website preserves real attribution');
    [$code,$seed]=dm_call('POST','/api/schools/1/demo-data/seed',[],$admin);
    dm_assert($code===201 && $seed['leads']===18,'can seed another demo set');
    [$code,$school2]=dm_call('POST','/api/schools/2/demo-data/seed',[],$other);
    dm_assert($code===201 && $school2['leads']===18,'second school separate');
    dm_call('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'],$admin);
    dm_assert(dm_call('GET','/api/schools/2/demo-data',[],$other)[1]['leads']===18,'clearing school1 does not affect school2');
    dm_assert(dm_call('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'],$staff)[0]===403,'staff cannot delete');
    // No demo activities or receipt records remain in first school after deletion.
    $snap=$store->transaction(fn(array $s)=>$s);
    dm_assert(count(array_filter($snap['activities'],fn($a)=>$a['school_id']===1 && $a['lead_id']!==$real['id']))===0,'demo activity deleted without residue');
    dm_assert(count(array_filter($snap['leads'],fn($l)=>$l['school_id']===1))===1,'real data preserved after repeat clear');
    echo "PASS: demo-data admin-only, 18 leads, 3 sources, full pipeline, selective clear, idempotency, and school isolation\n";
} finally {
    foreach(glob($tmp.'/*')?:[] as $f) @unlink($f);
    @rmdir($tmp);
}