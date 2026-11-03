<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-school-assignment-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function multi_staff_ok(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('School staff assignment: '.$name);}
function multi_staff_req(string $method,string $path,array $payload=[],string $token='admin'):array{
 global $store;
 try{return sf_handle($method,$path,$payload,['authorization'=>'Bearer '.$token],'198.51.100.111',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s):void {
  foreach(['Wagholi','Hadapsar','Outside School'] as $name)
   $s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name,'guid'=>sf_uuid()];
  foreach([
   [1,'Multi School Admin','admin@example.org','admin',[1,2],'Admin'],
   [2,'Wagholi Admissions','admissions@example.org','admissions',[1],'Admissions'],
   [3,'Wagholi Counsellor','counsellor@example.org','counsellor',[1],'Counsellor'],
   [4,'Outside Admin','other@example.org','outsider',[3],'Admin'],
   [5,'Hadapsar Admin','hadapsar@example.org','hadapsar',[2],'Admin'],
   [6,'Transfer Counsellor','transfer@example.org','transfer',[1],'Counsellor']
  ] as [$uid,$name,$email,$token,$schools,$role]){
   $s['users'][]=['id'=>$uid,'name'=>$name,'email'=>$email,
     'password_hash'=>password_hash('StaffPassword123!',PASSWORD_DEFAULT),'active'=>true];
   foreach($schools as $sid)$s['memberships'][]=['guid'=>sf_uuid(),'user_id'=>$uid,'school_id'=>$sid,
      'role'=>$role,'enabled'=>true];
   $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
  }
  $s['counters']['users']=6;
 },true);
 $base='/api/schools/2';
 multi_staff_ok(multi_staff_req('GET',$base.'/members/link-candidates',[],'hadapsar')[1]===[],
    'target-only admin cannot discover staff from unrelated school');
 multi_staff_ok(multi_staff_req('GET',$base.'/members/link-candidates',[],'outsider')[0]===403,
    'other school admin cannot read candidate list');
 multi_staff_ok(multi_staff_req('GET',$base.'/members/link-candidates',[],'counsellor')[0]===403,
    'counsellor cannot discover candidate identities');
 $rows=multi_staff_req('GET',$base.'/members/link-candidates')[1];
 multi_staff_ok(count($rows)===1 && $rows[0]['id']===2 && $rows[0]['can_link_admissions'],
    'only eligible Admissions user from admin-owned source school is shown');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>3,'role'=>'Counsellor'])[0]===403,
    'active home-school counselor cannot be assigned to a second school');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>3,'role'=>'Admissions'])[0]===403,
    'counselor cannot bypass exclusive home-school restriction using Admissions role');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>4,'role'=>'Admissions'])[0]===403,
    'cannot link globally known but unrelated person');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>2,'role'=>'Admin'])[0]===422,
    'cannot escalate link to administrator');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>'2','role'=>'Admissions'])[0]===422,
    'user ID must be numeric JSON value, not string');
 $before=$store->transaction(static fn(array $s):array=>$s);
 $hash=sf_find($before['users'],2)['password_hash'];
 [$status,$linked]=multi_staff_req('POST',$base.'/members/link',['user_id'=>2,'role'=>'Admissions']);
 multi_staff_ok($status===201&&$linked['id']===2&&$linked['role']==='Admissions',
    'Admissions employee linked to second school');
 $after=$store->transaction(static fn(array $s):array=>$s);
 multi_staff_ok(count($before['users'])===count($after['users'])&&
    sf_find($after['users'],2)['password_hash']===$hash,'no duplicate account and no password change');
 multi_staff_ok(multi_staff_req('GET','/api/me',[],'admissions')[0]===401,'old sessions revoked after permissions expanded');
 [$loginStatus,$login]=multi_staff_req('POST','/api/auth/login',['email'=>'admissions@example.org','password'=>'StaffPassword123!']);
 multi_staff_ok($loginStatus===200,'linked employee signs in with unchanged password');
 $admissionsToken=$login['token'];
 $me=multi_staff_req('GET','/api/me',[],$admissionsToken);
 multi_staff_ok($me[0]===200&&count($me[1]['schools'])===2,'Admissions can choose both explicitly assigned schools');
 multi_staff_ok(multi_staff_req('GET','/api/schools/3/leads',[],$admissionsToken)[0]===403,
    'Admissions blocked from outside school');
 multi_staff_ok(multi_staff_req('POST',$base.'/members/link',['user_id'=>2,'role'=>'Admissions'])[0]===409,
    'duplicate school membership refused');
 multi_staff_ok(multi_staff_req('PATCH','/api/schools/1/members/3',['role'=>'Admissions'],'hadapsar')[0]===403,
    'unrelated admin cannot change counselor role');
 multi_staff_ok(multi_staff_req('PATCH','/api/schools/1/members/3',['role'=>'Admissions'])[0]===200,
    'source admin can promote counselor to Admissions intentionally');
 multi_staff_ok(multi_staff_req('PATCH','/api/schools/1/members/3',['role'=>'Counsellor'])[0]===200,
    'source admin can restore single-school counsellor');
 multi_staff_ok(multi_staff_req('PATCH','/api/schools/1/members/6',['enabled'=>false])[0]===200,
    'source admin disables old counselor membership before transfer');
 $candidate=multi_staff_req('GET',$base.'/members/link-candidates')[1];
 multi_staff_ok(count(array_filter($candidate,static fn(array $r):bool=>$r['id']===6&&$r['can_link_counsellor']))===1,
    'inactive source counselor appears for transfer');
 [$transferred,$transferRow]=multi_staff_req('POST',$base.'/members/link',['user_id'=>6,'role'=>'Counsellor']);
 multi_staff_ok($transferred===201&&$transferRow['role']==='Counsellor',
    'transferred counselor only has active membership at new campus');
 multi_staff_ok(multi_staff_req('PATCH','/api/schools/1/members/6',['enabled'=>true])[0]===409,
    'old counselor membership cannot be reactivated while new campus assignment is active');
 [$transferLoginStatus,$transferLogin]=multi_staff_req('POST','/api/auth/login',
     ['email'=>'transfer@example.org','password'=>'StaffPassword123!']);
 multi_staff_ok($transferLoginStatus===200,'transferred counselor can sign in');
 $transferToken=$transferLogin['token'];
 $transferMe=multi_staff_req('GET','/api/me',[],$transferToken);
 multi_staff_ok($transferMe[0]===200&&count($transferMe[1]['schools'])===1&&
    $transferMe[1]['schools'][0]['id']===2,'counselor sees only assigned home-school option');
 multi_staff_ok(multi_staff_req('GET','/api/schools/1/leads',[],$transferToken)[0]===403,
    'counselor cannot read former school records');
 multi_staff_ok(multi_staff_req('GET','/api/schools/3/leads',[],$transferToken)[0]===403,
    'counselor cannot read unrelated school');
 [$ownedStatus,$owned]=multi_staff_req('POST',$base.'/leads',
   ['student_name'=>'Assigned Student','parent_name'=>'Assigned Guardian','phone'=>'9999998888',
    'grade'=>'I','assigned_to'=>6,'consent'=>true]);
 [$otherStatus,$other]=multi_staff_req('POST',$base.'/leads',
   ['student_name'=>'Other Student','parent_name'=>'Other Guardian','phone'=>'9999997777',
    'grade'=>'II','assigned_to'=>2,'consent'=>true]);
 multi_staff_ok($ownedStatus===201&&$otherStatus===201,'test leads created in target campus');
 [$listStatus,$list]=multi_staff_req('GET',$base.'/leads',[],$transferToken);
 multi_staff_ok($listStatus===200&&count($list)===1&&$list[0]['id']===$owned['id'],
    'counselor sees only specifically assigned enquiries within home school');
 multi_staff_ok(multi_staff_req('GET',$base.'/leads/'.$other['id'],[],$transferToken)[0]===403,
    'counselor cannot read another employee enquiry at same campus');
 multi_staff_ok(multi_staff_req('GET',$base.'/members/link-candidates',[],$transferToken)[0]===403,
    'transferred counselor cannot access staff linking tools');
 $logs=$store->transaction(static fn(array $s):array=>$s['audit_logs']??[]);
 multi_staff_ok(count(array_filter($logs,static fn(array $e):bool=>($e['action']??'')==='staff_school_assigned'))===2,
    'school assignment mutations audited');
 echo "PASS: existing staff re-use, counselor home-school isolation, school transfers, admissions multi-school access, no cross-tenant data or account duplication\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
