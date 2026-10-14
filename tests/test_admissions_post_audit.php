<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-postaudit-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function pa(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('Admissions post audit: '.$why);}
function preq(string $method,string $path,array $data=[]):array{
 global $store;
 try{return sf_handle($method,$path,$data,['authorization'=>'Bearer admin'],'198.51.100.28',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function(&$s){
  $s['schools'][]=['id'=>1,'name'=>'Test Campus'];
  $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
  $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Admin'];
  $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+3600];
 },true);
 pa(preq('PATCH','/api/schools/1/followup-policy',['retry_days'=>[1,3,7]])[0]===200,'configure retry intervals');
 pa(preq('PATCH','/api/schools/1/followup-policy',['retry_days'=>[0,100,-1]])[0]===422,'invalid retry schedule rejected');
 $lead=preq('POST','/api/schools/1/leads',['student_name'=>'Example Child','parent_name'=>'Example Guardian','phone'=>'9876543210','consent'=>true])[1];
 $id=$lead['id'];$path='/api/schools/1/leads/'.$id;
 pa(preq('POST',$path.'/calls',['outcome'=>'No Answer'])[0]===201,'first no answer');
 $after=preq('GET',$path)[1]['lead'];
 pa($after['followup_date']===(new DateTimeImmutable(sf_admission_today()))->modify('+1 day')->format('Y-m-d'),'first retry policy applied');
 pa(empty($after['last_successful_contact_at']),'failed contact does not set successful contact');
 pa(preq('PATCH',$path,['status'=>'On Hold / Deferred'])[0]===422,'deferred date required');
 pa(preq('PATCH',$path,['status'=>'On Hold / Deferred','deferred_until'=>'2027-07-01'])[0]===200,'deferred status accepted with review date');
 $snap=$store->transaction(fn($s)=>$s);
 pa(count(array_filter($snap['tasks'],fn($t)=>$t['kind']==='Follow-up'))===1,'deferred review uses one upsert task');
 // Add a marked demo record plus new related collections to prove deep cleanup does not touch real.
 $store->transaction(function(&$s){
    $demo=['id'=>sf_id($s,'leads'),'guid'=>sf_uuid(),'school_id'=>1,'name'=>'Demo','guardian'=>'Demo',
      'phone'=>'9100000000','normalized_phone'=>'9100000000','email'=>'demo@example.invalid','grade'=>'I',
      'status'=>'New','assigned_to'=>1,'is_demo'=>true,'created_at'=>sf_time()];
    $s['leads'][]=$demo;
    $s['tasks'][]=['id'=>sf_id($s,'tasks'),'school_id'=>1,'lead_id'=>$demo['id'],'kind'=>'Follow-up','due_date'=>sf_admission_today(),'status'=>'Open'];
    $s['applications'][]=['id'=>sf_id($s,'applications'),'school_id'=>1,'lead_id'=>$demo['id'],'status'=>'Started'];
    $s['notification_queue'][]=['id'=>sf_id($s,'notification_queue'),'school_id'=>1,'lead_id'=>$demo['id'],'status'=>'queued'];
    $s['stage_history'][]=['id'=>sf_id($s,'stage_history'),'school_id'=>1,'lead_id'=>$demo['id'],'from_stage'=>'New','to_stage'=>'Counselling'];
    $s['parent_profiles'][]=['id'=>sf_id($s,'parent_profiles'),'guid'=>sf_uuid(),'school_id'=>1,'guardian'=>'Demo'];
    $s['leads'][count($s['leads'])-1]['family_guid']=$s['parent_profiles'][count($s['parent_profiles'])-1]['guid'];
 },true);
 pa(preq('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'])[0]===200,'demo clear accepted');
 $snap=$store->transaction(fn($s)=>$s);
 pa(count($snap['leads'])===1 && count($snap['tasks'])>=1,'real lead and tasks preserved');
 pa(count($snap['applications'])===0 && count($snap['notification_queue'])===0 &&
    count($snap['stage_history'])>=1,'orphan demo related records removed, genuine stage history preserved');
 pa(count($snap['parent_profiles'])===1,'real family preserved, demo family deleted');
 echo "PASS: Post-audit school retry schedule, deferred review, non-destructive demo cleanup and families\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
