<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/schoolflow-audit-test-'.bin2hex(random_bytes(7));mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function aud_assert(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException('Audit test: '.$msg);}
function aud_call(string $verb,string $url,array $data=[],string $token=''): array {
    global $store;
    try {return sf_handle($verb,$url,$data,['authorization'=>$token?'Bearer '.$token:'','idempotency-key'=>'audit-test-receipt-12'],'198.51.100.77',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
    $store->transaction(function (&$s): void {
        foreach ([['School One',1],['School Two',2]] as [$name,$id])$s['schools'][]=['id'=>$id,'name'=>$name,'created_at'=>sf_time()];
        $entries=[['Admin One',1,'Admin',1],['Counsellor',2,'Counsellor',1],['Admin Two',3,'Admin',2],['Admissions',4,'Admissions',1]];
        foreach($entries as [$name,$user,$role,$school]){
            $s['users'][]=['id'=>$user,'name'=>$name,'email'=>"test$user@example.invalid",'password_hash'=>password_hash('example-secure-password',PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
            $s['memberships'][]=['school_id'=>$school,'user_id'=>$user,'role'=>$role];
            $s['sessions'][]=['token_hash'=>sf_hash("test-token-$user"),'user_id'=>$user,'expires_at'=>time()+5000];
        }
        $s['counters']['users']=4;$s['counters']['schools']=2;
    },true);
    $admin='test-token-1';$staff='test-token-2';$other='test-token-3';$admissions='test-token-4';
    [$code,$lead]=aud_call('POST','/api/schools/1/leads',['student_name'=>'Demo Applicant','parent_name'=>'Fictional Parent','phone'=>'000001234567','grade'=>'Grade 5','assigned_to'=>1],$admin);
    aud_assert($code===201,'create lead');$id=$lead['id'];
    aud_assert(aud_call('POST',"/api/schools/1/leads/$id/view",[],$staff)[0]===403,'unassigned counsellor cannot view');
    aud_assert(aud_call('POST',"/api/schools/1/leads/$id/view",[],$admin)[0]===200,'view logged');
    aud_assert(aud_call('PATCH',"/api/schools/1/leads/$id",['status'=>'Contacted','assigned_to'=>2,'followup_date'=>'2026-11-01'],$admin)[0]===200,'update lead');
    aud_assert(aud_call('POST',"/api/schools/1/leads/$id/view",[],$staff)[0]===200,'assigned staff can view');
    aud_assert(aud_call('POST',"/api/schools/1/leads/$id/notes",['text'=>'Private family note: DO NOT COPY INTO AUDIT'],$staff)[0]===201,'note created');
    [$code,$detail]=aud_call('GET',"/api/schools/1/leads/$id",[],$staff);
    aud_assert($code===200 && count($detail['audit']['events'])>=6,'per-lead audit returned in detail');
    $actionNames=array_column($detail['audit']['events'],'action');
    foreach (['manual_create','enquiry_viewed','update_status','update_assigned_to','update_followup_date','note'] as $action) {
       aud_assert(in_array($action,$actionNames,true),'recorded '.$action);
    }
    aud_assert(!str_contains(json_encode($detail['audit']), 'DO NOT COPY INTO AUDIT'),'notes are masked in audit (full note stays in conversation history)');
    aud_assert(count(array_filter($detail['activities'],fn($a)=>$a['action']==='note'))===1,'note content retained in activity');
    aud_assert(aud_call('GET','/api/schools/1/audit',[],$staff)[0]===403,'schoolwide audit restricted to admin');
    aud_assert(aud_call('GET','/api/schools/1/audit',[],$other)[0]===403,'schoolwide audit isolated');
    aud_assert(aud_call('GET',"/api/schools/2/leads/$id/audit",[],$other)[0]===404,'enquiry audit cross-school invisible');
    aud_assert(aud_call('POST','/api/schools/1/audit',['event'=>'export_enquiries'],$admissions)[0]===200,'admissions export logged');
    aud_assert(aud_call('POST','/api/schools/1/audit',['event'=>'bogus'],$admin)[0]===404,'arbitrary audit event rejected');
    [$code,$site]=aud_call('POST','/api/schools/1/sites',['name'=>'School WP','domain'=>'school.example.invalid','platform'=>'WordPress'],$admin);
    aud_assert($code===201,'site connected');
    aud_assert(aud_call('POST','/api/schools/1/sites/'.$site['id'].'/rotate-key',[],$admin)[0]===200,'rotate key');
    aud_assert(aud_call('POST','/api/schools/1/demo-data/seed',[],$admin)[0]===201,'seed demo');
    [$code,$cleared]=aud_call('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'],$admin);
    aud_assert($code===200 && $cleared['removed_leads']===18,'demo cleared');
    $all=$store->transaction(fn(array $s): array=>$s);
    aud_assert(count($all['audit_logs'])>=25,'retains previously written audit logs');
    $events=sf_audit_page($all,1,null,null,100)['events'];
    aud_assert(in_array('demo_cleared',array_column($events,'action'),true),'demo clear itself audited');
    aud_assert(in_array('demo_seeded',array_column($events,'action'),true),'demo seed audited');
    aud_assert(in_array('website_key_rotated',array_column($events,'action'),true),'key rotation audited');
    aud_assert(in_array('enquiries_exported',array_column($events,'action'),true),'export audited');
    aud_assert(!str_contains(json_encode($events),$site['api_key']),'API key never saved to audit');
    $first=sf_audit_page($all,1,null,null,5);
    aud_assert($first['has_more'] && count($first['events'])===5 && $first['next_before']!==null,'pagination first page');
    $next=sf_audit_page($all,1,null,$first['next_before'],5);
    aud_assert(count($next['events'])===5 && $next['events'][0]['id']<$first['events'][4]['id'],'pagination cursor older, no overlap');
    // Existing install upgrade: activity history is migrated at the first post-deploy audited mutation.
    $legacy=new SchoolFlowStore($dir.'/legacy');
    $legacy->transaction(function (&$s): void {
      $s['schools']=[['id'=>1,'name'=>'Old School','created_at'=>sf_time()]];
      $s['users']=[['id'=>1,'name'=>'Older Admin','active'=>true]];
      $s['activities']=[['id'=>1,'school_id'=>1,'lead_id'=>55,'actor_id'=>1,'action'=>'note','details'=>'Old secret notes must not leak','created_at'=>sf_time()]];
      unset($s['audit_logs']);
    },true);
    $beforeWrite=$legacy->transaction(fn(array $s)=>sf_audit_page($s,1,55,null));
    aud_assert(count($beforeWrite['events'])===1 && $beforeWrite['events'][0]['action']==='note', 'existing activity visible before migration');
    $legacy->transaction(function (&$s): void {sf_event($s,1,null,1,'new_activity','Second action');},true);
    $prev=$legacy->transaction(fn(array $s)=>$s['audit_logs']);
    aud_assert(count($prev)===2 && $prev[0]['action']==='note' && $prev[0]['details']==='Conversation note added','legacy note migrated with redaction');
    echo "PASS: audit history captures enquiry and school changes, protects secrets, enforces roles, pagination and legacy migration\n";
} finally {
  foreach(glob($dir.'/*')?:[] as $f){if(is_dir($f)){foreach(glob($f.'/*')?:[] as $f2) @unlink($f2);@rmdir($f);}else @unlink($f);}
  @rmdir($dir);
}