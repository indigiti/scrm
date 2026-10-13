<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-route-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function chk($yes,$label){if(!$yes)throw new RuntimeException('Routing: '.$label);}
function req(string $verb,string $url,array $body=[],string $token='t1',string $idem=''):array{
 global $store;
 try{return sf_handle($verb,$url,$body,['authorization'=>'Bearer '.$token,'idempotency-key'=>$idem],'127.0.0.1',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function(&$s){
  foreach(['Alpha School','Beta School','Outside School'] as $name){$s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name];}
  foreach([['Admin Alpha','Admin',1],['Counsellor Alpha','Counsellor',1],['Admin Beta','Admin',2],['Counsellor Beta','Counsellor',2],['Outside Admin','Admin',3]] as [$name,$role,$sid]){
   $id=sf_id($s,'users');$s['users'][]=['id'=>$id,'name'=>$name,'email'=>"$id@example.invalid",'active'=>true];
   $s['memberships'][]=['user_id'=>$id,'school_id'=>$sid,'role'=>$role];
   $s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash('t'.$id),'expires_at'=>time()+3600];
  }
 },true);
 chk(req('GET','/api/schools/overview',[],'t2')[0]===403,'non-admin blocked');
 chk(count(req('GET','/api/schools/overview',[],'t1')[1])===1,'unrelated school hidden');
 $a=req('POST','/api/schools/1/sites',['name'=>'Alpha Form','domain'=>'shared.example.test','platform'=>'WordPress'],'t1');
 $b=req('POST','/api/schools/2/sites',['name'=>'Beta Form','domain'=>'shared.example.test','platform'=>'WordPress'],'t3');
 chk($a[0]===201 && $b[0]===201,'both sites created');
 chk(req('POST','/api/schools/2/sites',['name'=>'Leak','domain'=>'shared.example.test'],'t1')[0]===403,'cross-tenant source blocked');
 $lead=['student_name'=>'Test Child','parent_name'=>'Test Guardian','phone'=>'+91 9999998765','class'=>'Grade 2','consent'=>true,'school_id'=>2];
 $one=req('POST','/api/v1/inbound-leads',$lead,$a[1]['api_key'],'sub-alpha-0001');
 $two=req('POST','/api/v1/inbound-leads',$lead,$b[1]['api_key'],'sub-beta-0001');
 chk($one[0]===201&&$two[0]===201,'both key submissions succeed');
 $aa=req('GET','/api/schools/1/leads',[],'t1')[1];
 $bb=req('GET','/api/schools/2/leads',[],'t3')[1];
 chk(count($aa)===1&&count($bb)===1,'inboxes separate');
 chk($aa[0]['school_id']===1&&$bb[0]['school_id']===2,'cannot spoof destination using form school_id');
 chk($aa[0]['assigned_to']===2&&$bb[0]['assigned_to']===4,'staff assigned within destination school');
 chk(req('GET','/api/schools/2/leads',[],'t1')[0]===403,'school isolation');
 chk(empty($aa[0]['duplicate_of'])&&empty($bb[0]['duplicate_of']),'no cross-school duplicate flag');
 $ov=req('GET','/api/schools/overview',[],'t1')[1];
 chk($ov[0]['leads']===1 && count($ov[0]['sites'])===1 && !isset($ov[0]['sites'][0]['key_hash']),'overview returns counts but no secrets');
 chk(!str_contains(json_encode($ov),$a[1]['api_key']),'API key not exposed');
 chk(req('POST','/api/v1/inbound-leads',$lead,$a[1]['api_key'],'sub-alpha-0001')[1]['duplicate_submission']===true,'idempotent');
 $created=req('POST','/api/schools',['name'=>'New Campus'],'t1');
 chk($created[0]===201 && count(req('GET','/api/schools/overview',[],'t1')[1])===2,'new school immediately manageable');
 echo "PASS: isolated multi-school website routing, staff assignment, no key disclosure\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
