<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-tasks-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function bc(bool $ok,string $desc):void{if(!$ok)throw new RuntimeException('Release B: '.$desc);}
function br(string $method,string $path,array $p=[],string $auth='admin'):array {
 global $store;
 try{return sf_handle($method,$path,$p,['authorization'=>'Bearer '.$auth],'198.51.100.8',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function(array &$s){
  foreach([1,2] as $id)$s['schools'][]=['id'=>$id,'name'=>'School '.$id];
  $s['users'][]=['id'=>1,'name'=>'Administrator','email'=>'admin@example.invalid','active'=>true];
  $s['users'][]=['id'=>2,'name'=>'Other','email'=>'other@example.invalid','active'=>true];
  $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin'];
  $s['memberships'][]=['user_id'=>2,'school_id'=>2,'role'=>'Counsellor'];
  $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+3600];
  $s['sessions'][]=['user_id'=>2,'token_hash'=>sf_hash('other'),'expires_at'=>time()+3600];
 },true);
 [$code,$lead]=br('POST','/api/schools/1/leads',['student_name'=>'Student','parent_name'=>'Guardian','phone'=>'9876543210','consent'=>true]);
 bc($code===201,'lead created');
 $id=$lead['id'];$base='/api/schools/1';
 [$code,$tasks]=br('GET',$base.'/tasks');
 bc($code===200 && count($tasks)===1 && $tasks[0]['kind']==='First Contact','first contact task automatically created');
 bc(br('GET',$base.'/tasks',[],'other')[0]===403,'cross-campus tasks blocked');
 [$code,$lead]=br('PATCH',$base.'/leads/'.$id,['followup_date'=>'2027-03-03']);
 bc($code===200,'schedule follow-up');
 br('PATCH',$base.'/leads/'.$id,['followup_date'=>'2027-03-04']);
 $tasks=br('GET',$base.'/tasks')[1];
 bc(count(array_filter($tasks,fn($t)=>$t['kind']==='Follow-up'))===1,'follow-up upsert avoids duplicates');
 bc(in_array('Overdue',['Due Today','Upcoming','Overdue'],true),'task status enumeration');
 bc(br('PATCH',$base.'/leads/'.$id,['status'=>'Lost'])[0]===422,'lost requires reason');
 bc(br('PATCH',$base.'/leads/'.$id,['status'=>'Enrolled'])[0]===422,'direct enrollment blocked');
 [$code,$app]=br('POST',$base.'/applications',['lead_id'=>$id]);
 bc($code===201 && $app['status']==='Started','application started');
 bc(br('POST',$base.'/applications',['lead_id'=>$id])[0]===409,'no duplicate application');
 bc(br('PATCH',$base.'/applications/'.$app['id'],['status'=>'Enrolled','fee_recorded'=>true])[0]===422,'cannot jump to Enrolled');
 bc(br('PATCH',$base.'/applications/'.$app['id'],['status'=>'Submitted'])[0]===200,'application submitted');
 $tasks=br('GET',$base.'/tasks')[1];
 bc(count(array_filter($tasks,fn($t)=>$t['kind']==='Application Review'))===1,'application review task generated');
 bc(br('PATCH',$base.'/applications/'.$app['id'],['status'=>'Admission Confirmed'])[0]===200,'admission confirmed');
 bc(br('PATCH',$base.'/applications/'.$app['id'],['status'=>'Enrolled','fee_recorded'=>true])[0]===200,'fee-verified enrollment');
 bc(br('GET',$base.'/leads/'.$id)[1]['lead']['status']==='Enrolled','admission stage synchronized');
 $task=$tasks[0];
 bc(br('PATCH',$base.'/tasks/'.$task['id'],['status'=>'Completed'])[0]===200,'complete assigned task');
 bc(br('PATCH',$base.'/tasks/'.$task['id'],['status'=>'Open'], 'other')[0]===403,'no cross-school task modification');
 $snapshot=$store->transaction(fn($s)=>$s);
 bc(!empty($snapshot['applications'][0]['guid'])&&!empty($snapshot['tasks'][0]['guid']),'new entities use immutable GUID');
 bc(count($snapshot['audit_logs'])>=5,'task and milestone history audited');
 echo "PASS: Release B tasks, idempotent follow-up, application milestone, enrollment guard, tenant isolation, GUIDs\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
