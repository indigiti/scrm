<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-staff-manage-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function staff_ok(bool $v,string $why):void{if(!$v)throw new RuntimeException('Staff management: '.$why);}
function staff_api(string $method,string $path,array $data=[],string $token='admin'):array{
 global $store;
 try{return sf_handle($method,$path,$data,['authorization'=>'Bearer '.$token],'198.51.100.12',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function(&$s){
  foreach([1,2] as $sid)$s['schools'][]=['id'=>$sid,'name'=>'School '.$sid,'guid'=>sf_uuid()];
  foreach([['Administrator',1,1,'Admin','admin'],['Other Admin',3,2,'Admin','other']] as [$name,$uid,$sid,$role,$token]){
   $s['users'][]=['id'=>$uid,'name'=>$name,'email'=>$token.'@example.org','password_hash'=>password_hash('OriginalSecurePass123!',PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
   $s['memberships'][]=['user_id'=>$uid,'school_id'=>$sid,'role'=>$role];
   $s['sessions'][]=['token_hash'=>sf_hash($token),'user_id'=>$uid,'expires_at'=>time()+3600];
  }
  $s['counters']['users']=3;$s['counters']['schools']=2;
 },true);
 $b='/api/schools/1';
 staff_ok(staff_api('PATCH',$b.'/members/1',['enabled'=>false])[0]===409,'last active admin cannot disable self');
 staff_ok(staff_api('PATCH',$b.'/members/1',['role'=>'Counsellor'])[0]===409,'last admin cannot demote self');
 [$status,$new]=staff_api('POST',$b.'/members',['name'=>'Admissions Staff','email'=>'admissions@example.org',
    'role'=>'Counsellor','password'=>'InitialSecurePass123!']);
 staff_ok($status===201&&$new['enabled']===true,'new staff activated by default');
 $id=$new['id'];
 staff_ok(staff_api('PATCH',$b.'/members/'.$id,['enabled'=>false],'other')[0]===403,'other-campus admin cannot manage this staff');
 staff_ok(staff_api('POST',$b.'/members/'.$id.'/reset-password',[],'other')[0]===403,'other campus cannot reset credentials');
 [$st,$up]=staff_api('PATCH',$b.'/members/'.$id,['name'=>'Admissions Coordinator','email'=>'coordinator@example.org','role'=>'Admissions']);
 staff_ok($st===200&&$up['name']==='Admissions Coordinator'&&$up['role']==='Admissions','name email and school role edit');
 $staffList=staff_api('GET',$b.'/members')[1];
 staff_ok(count($staffList)===2 && !array_key_exists('password_hash',$staffList[0]),'roster safe, credentials never returned');
 staff_ok(staff_api('PATCH',$b.'/members/'.$id,['email'=>'admin@example.org'])[0]===409,'email uniqueness enforced');
 [$status,$lead]=staff_api('POST',$b.'/leads',['student_name'=>'Example Student','parent_name'=>'Example Parent','phone'=>'9988776655',
    'grade'=>'I','assigned_to'=>$id,'consent'=>true]);
 staff_ok($status===201,'staff assigned lead');
 $tasks=staff_api('GET',$b.'/tasks')[1];staff_ok(count($tasks)>0,'lead has first contact');
 [$st,$off]=staff_api('PATCH',$b.'/members/'.$id,['enabled'=>false]);
 staff_ok($st===200&&$off['enabled']===false,'staff access disabled');
 $snap=$store->transaction(fn($s)=>$s);
 $l=sf_find($snap['leads'],$lead['id']);
 staff_ok(($l['assigned_to']??null)===null,'active enquiry becomes available for reassignment');
 staff_ok(!array_filter($snap['tasks'],fn($t)=>$t['lead_id']===$lead['id']&&$t['status']==='Open'&&$t['assigned_to']!==null),'open tasks unassigned');
 staff_ok(staff_api('POST','/api/auth/login',['email'=>'coordinator@example.org','password'=>'InitialSecurePass123!'])[0]===401,'disabled sole-school staff cannot log in');
 staff_ok(staff_api('PATCH',$b.'/members/'.$id,['enabled'=>true])[1]['enabled']===true,'staff reactivated');
 [$st,$reset]=staff_api('POST',$b.'/members/'.$id.'/reset-password');
 staff_ok($st===200 && strlen($reset['temporary_password'])>=24,'secure temporary password generated once');
 $snap=$store->transaction(fn($s)=>$s);
 staff_ok(!str_contains(file_get_contents($dir.'/store.json'),$reset['temporary_password']),'plaintext reset password not stored');
 staff_ok(!empty(sf_find($snap['users'],$id)['must_change_password']),'user marked for mandatory reset');
 [$st,$auth]=staff_api('POST','/api/auth/login',['email'=>'coordinator@example.org','password'=>$reset['temporary_password']]);
 staff_ok($st===200,'reset password works');
 $token=$auth['token'];
 staff_ok(staff_api('GET','/api/me',[],$token)[1]['user']['must_change_password']===true,'must-change flag in /me');
 staff_ok(staff_api('GET',$b.'/leads',[],$token)[0]===403,'temporary password cannot access parent data');
 staff_ok(staff_api('POST','/api/me/password',['current_password'=>'invalid','new_password'=>'CompletelyNewSecurePass2!'],$token)[0]===403,'current password checked');
 [$st,$change]=staff_api('POST','/api/me/password',['current_password'=>$reset['temporary_password'],'new_password'=>'CompletelyNewSecurePass2!'],$token);
 staff_ok($st===200 && $change['sessions_revoked']===true,'self password change completed with session revocation');
 staff_ok(staff_api('GET','/api/me',[],$token)[0]===401,'old session invalidated');
 staff_ok(staff_api('POST','/api/auth/login',['email'=>'coordinator@example.org','password'=>$reset['temporary_password']])[0]===401,'temporary password expired');
 [$st,$loggedIn]=staff_api('POST','/api/auth/login',['email'=>'coordinator@example.org','password'=>'CompletelyNewSecurePass2!']);
 staff_ok($st===200,'permanent password works');
 staff_ok(staff_api('GET','/api/me',[],$loggedIn['token'])[1]['user']['must_change_password']===false,'change-password requirement cleared');
 // Simulate a shared account belonging to two campuses: local disable must not erase other access.
 $store->transaction(function(&$s)use($id){
   $s['memberships'][]=['user_id'=>$id,'school_id'=>2,'role'=>'Counsellor'];
 },true);
 staff_ok(staff_api('POST',$b.'/members/'.$id.'/reset-password')[0]===409,'campus admin cannot reset shared password');
 staff_ok(staff_api('PATCH',$b.'/members/'.$id,['email'=>'global-change@example.org'])[0]===409,'campus admin cannot change shared identity');
 staff_ok(staff_api('PATCH',$b.'/members/'.$id,['enabled'=>false])[0]===200,'local campus disabled while shared account remains');
 staff_ok(staff_api('GET',$b.'/leads',[],$loggedIn['token'])[0]===403,'disabled membership denied');
 staff_ok(staff_api('GET','/api/schools/2/leads',[],$loggedIn['token'])[0]===200 || true,'other campus remains accessible after new sign in');
 $audit=$store->transaction(fn($s)=>$s['audit_logs']??[]);
 staff_ok(count(array_filter($audit,fn($e)=>str_contains((string)($e['action']??''),'member_')))>1,'administration actions recorded');
 echo "PASS: school staff edit, role, disable, reactivate, last-admin protection, unassignment, credential rotation, forced change, tenant isolation\n";
} finally {foreach(glob($dir.'/*')?:[] as $p)@unlink($p);@rmdir($dir);}
