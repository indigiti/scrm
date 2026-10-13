<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/schoolflow-group-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function gc(bool $p,string $m):void{if(!$p)throw new RuntimeException('Group routing failure: '.$m);}
function gr(string $method,string $url,array $data=[],string $auth='admin',string $idem=''):array{
 global $store;
 try{return sf_handle($method,$url,$data,['authorization'=>'Bearer '.$auth,'idempotency-key'=>$idem],'198.51.100.1',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function (&$s){
  foreach(['ABC Wakad','ABC Baner','Unrelated School'] as $name)$s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name,'created_at'=>sf_time()];
  foreach([['Group Admin',1,'Admin'],['Group Admin',2,'Admin'],['Wakad Counsellor',1,'Counsellor'],['Baner Counsellor',2,'Counsellor'],['Other Admin',3,'Admin']] as [$name,$school,$role]) {
    if($name==='Group Admin')$uid=1;else{$uid=sf_id($s,'users');}
    if(!sf_find($s['users'],$uid)){$s['users'][]=['id'=>$uid,'name'=>$name,'email'=>$uid.'@example.invalid','active'=>true];$s['counters']['users']=max($s['counters']['users']??0,$uid);}
    $s['memberships'][]=['user_id'=>$uid,'school_id'=>$school,'role'=>$role];
  }
  foreach([1,2,3,4] as $id){$s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash(match($id){1=>'admin',2=>'staff-a',3=>'staff-b',4=>'outsider'}),'expires_at'=>time()+3600];}
 },true);
 gc(gr('GET','/api/group-sites',[],'staff-a')[0]===403,'counsellor blocked');
 gc(gr('POST','/api/group-sites',['name'=>'Bad Group','domain'=>'abc.test','school_ids'=>[1,3]])[0]===403,'cross-school creation blocked');
 $create=gr('POST','/api/group-sites',['name'=>'ABC Group Website','domain'=>'abc-school.test','school_ids'=>[1,2]]);
 gc($create[0]===201,'create group');$id=$create[1]['id'];$key=$create[1]['api_key'];
 gc(str_starts_with($key,'sfgroup_'),'has group-specific key');
 $list=gr('GET','/api/group-sites')[1];gc(count($list)===1 && count($list[0]['schools'])===2 && !str_contains(json_encode($list),$key),'GET routes exclude private secret');
 gc(gr('GET','/api/group-sites',[],'outsider')[1]===[],'other admin cannot see group');
 gc(gr('GET','/api/v1/group-schools',[],'invalid')[0]===401,'invalid public read key');
 $choices=gr('GET','/api/v1/group-schools',[],$key);
 gc($choices[0]===200 && count($choices[1]['schools'])===2,'two campus options');
 gc($choices[1]['schools'][0]['code']==='campus-1','stable server generated campus code');
 $form=['student_name'=>'Ria','parent_name'=>'Guardian','phone'=>'9999988888','grade'=>'Grade 3','consent'=>true,'school_code'=>'campus-2','school_id'=>1];
 gc(gr('POST','/api/v1/inbound-leads',$form,$key,'grp-001')[0]===201,'group POST accepted');
 $a=gr('GET','/api/schools/1/leads')[1];$b=gr('GET','/api/schools/2/leads')[1];
 gc(count($a)===0 && count($b)===1 && $b[0]['school_id']===2,'route chosen by school_code, not forged school_id');
 gc($b[0]['assigned_to']===3,'lead auto-assigned to selected-school counsellor');
 gc($b[0]['source_name']==='ABC Group Website (Group website)','group source attribution');
 gc(gr('POST','/api/v1/inbound-leads',$form,$key,'grp-001')[1]['duplicate_submission']===true,'idempotent');
 gc(gr('POST','/api/v1/inbound-leads',array_merge($form,['school_code'=>'campus-1']),$key,'grp-001')[0]===409,'same receipt cannot change destination');
 gc(gr('POST','/api/v1/inbound-leads',array_merge($form,['school_code'=>'campus-3']),$key,'grp-002')[0]===422,'unmapped choice blocked');
 gc(gr('POST','/api/v1/inbound-leads',array_merge($form,['school_code'=>'']),$key,'grp-003')[0]===422,'missing choice blocked');
 gc(gr('POST','/api/v1/inbound-leads',array_merge($form,['consent'=>false]),$key,'grp-004')[0]===422,'consent blocked');
 gc(gr('PATCH','/api/group-sites/'.$id,['name'=>'ABC Group Website','domain'=>'abc-school.test','school_ids'=>[1,3],'enabled'=>true])[0]===403,'cannot add unauthorized school');
 $changed=gr('PATCH','/api/group-sites/'.$id,['name'=>'ABC Group Website','domain'=>'abc-school.test','school_ids'=>[1],'enabled'=>true]);gc($changed[0]===200,'edit group routes');
 gc(count(gr('GET','/api/v1/group-schools',[],$key)[1]['schools'])===1,'updated dropdown');
 gc(gr('POST','/api/v1/inbound-leads',$form,$key,'grp-005')[0]===422,'disabled campus cannot receive leads');
 gc(count(gr('GET','/api/schools/2/leads')[1])===1,'historic lead remains in original school');
 $oldSource=$b[0]['site_id'];gc(gr('POST','/api/schools/2/sites/'.$oldSource.'/rotate-key',[],'admin')[0]===403,'route source cannot be separately rekeyed');
 $rot=gr('POST','/api/group-sites/'.$id.'/rotate-key');gc($rot[0]===200 && $rot[1]['api_key']!==$key,'rotate key');
 gc(gr('GET','/api/v1/group-schools',[],$key)[0]===401,'old key revoked');
 $newKey=$rot[1]['api_key'];
 gc(gr('PATCH','/api/group-sites/'.$id,['name'=>'ABC Group Website','domain'=>'abc-school.test','school_ids'=>[1],'enabled'=>false])[0]===200,'disable group');
 gc(gr('GET','/api/v1/group-schools',[],$newKey)[0]===401,'disabled group key unusable');
 $snap=$store->transaction(fn($s)=>$s);
 gc(count($snap['audit_logs'])>=3,'audits persisted');
 gc(!str_contains(json_encode($snap),$newKey),'no plaintext key in JSON');
 echo "PASS: group website routes, dropdown, authorization, consent, school assignment, idempotency, key revocation, audit\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}