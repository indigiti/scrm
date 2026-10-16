<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-stabilization-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function st_ok(bool $v,string $why):void{if(!$v)throw new RuntimeException('Stabilization: '.$why);}
function st_api(string $method,string $path,array $payload=[]):array{
 global $store;
 try{return sf_handle($method,$path,$payload,['authorization'=>'Bearer admin'],'198.51.100.24',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
function st_task(array $tasks,string $kind):?array{
 foreach($tasks as $t)if($t['kind']===$kind)return $t;
 return null;
}
try{
 $store->transaction(static function(array &$s):void {
   $s['schools'][]=['id'=>1,'name'=>'Campus One'];
   $s['schools'][]=['id'=>2,'name'=>'Campus Two'];
   $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
   $s['users'][]=['id'=>2,'name'=>'Counsellor','email'=>'owner@example.invalid','active'=>true];
   $s['users'][]=['id'=>3,'name'=>'Counsellor Two','email'=>'owner2@example.invalid','active'=>true];
   foreach([1,2,3] as $uid)$s['memberships'][]=['user_id'=>$uid,'school_id'=>1,'role'=>$uid===1?'Admin':'Counsellor'];
   $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+3600];
 },true);
 $base='/api/schools/1';
 [$status,$lead]=st_api('POST',$base.'/leads',[
   'student_name'=>'Student Sample','parent_name'=>'Parent Sample',
   'phone'=>'9876543210','assigned_to'=>2,'consent'=>true
 ]);
 st_ok($status===201,'lead created');
 $id=$lead['id'];
 $tasks=st_api('GET',$base.'/tasks')[1];
 $first=st_task($tasks,'First Contact');
 st_ok($first!==null && $first['status']==='Open','first contact initially open');
 [$status,$call]=st_api('POST',$base.'/leads/'.$id.'/calls',[
   'outcome'=>'Answered','note'=>'Spoke to the guardian','followup_date'=>'2027-11-04'
 ]);
 st_ok($status===201,'answered call recorded');
 $saved=st_api('GET',$base.'/leads/'.$id)[1]['lead'];
 $tasks=st_api('GET',$base.'/tasks')[1];
 st_ok($saved['followup_date']==='2027-11-04','lead follow-up date saved');
 st_ok(st_task($tasks,'First Contact')['status']==='Completed','first contact completed in JSON store');
 st_ok(st_task($tasks,'Follow-up')['due_date']==='2027-11-04','new call date becomes task date');
 st_ok(!empty($saved['last_successful_contact_at']),'answered call records successful contact');
 [$status,$lead]=st_api('PATCH',$base.'/leads/'.$id,['assigned_to'=>3]);
 st_ok($status===200,'lead reassigned');
 $tasks=st_api('GET',$base.'/tasks')[1];
 st_ok(st_task($tasks,'Follow-up')['assigned_to']===3,'open follow-up follows new owner');
 st_ok(st_task($tasks,'First Contact')['assigned_to']===2,'historical completed task stays assigned to original staff');
 [$status,$visit]=st_api('POST',$base.'/visits',[
   'lead_id'=>$id,'starts_at'=>'2027-11-10T10:30',
   'purpose'=>'Campus tour','staff_id'=>3
 ]);
 st_ok($status===201,'visit booked');
 $visitTask=st_task(st_api('GET',$base.'/tasks')[1],'Campus Visit');
 st_ok($visitTask!==null && $visitTask['status']==='Open','visit task created');
 st_ok(st_api('PATCH',$base.'/visits/'.$visit['id'],['status'=>'Completed'])[0]===200,'visit completed');
 st_ok(st_task(st_api('GET',$base.'/tasks')[1],'Campus Visit')['status']==='Completed','visit completion closes action task');
 [$status,$visit2]=st_api('POST',$base.'/visits',[
   'lead_id'=>$id,'starts_at'=>'2027-11-11T10:30','purpose'=>'Follow-up tour','staff_id'=>3
 ]);
 st_ok($status===201,'second visit booked');
 st_ok(st_api('PATCH',$base.'/visits/'.$visit2['id'],['status'=>'Cancelled'])[0]===200,'visit cancelled');
 st_ok(st_task(st_api('GET',$base.'/tasks')[1],'Campus Visit')['status']==='Completed','completed historical visit task not reopened');
 st_ok(st_api('PATCH',$base.'/leads/'.$id,['followup_date'=>''])[0]===200,'follow-up cleared');
 st_ok(st_task(st_api('GET',$base.'/tasks')[1],'Follow-up')['status']==='Cancelled','clearing follow-up cancels persisted task');
 $audits=$store->transaction(fn(array $s)=>array_filter($s['audit_logs'],fn($a)=>($a['school_id']??null)===1));
 st_ok(count($audits)>3,'record changes retain school scoped audit events');
 echo "PASS: completed first contact, current call due date, reassigned tasks, visit resolution, cancelled follow-up\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
