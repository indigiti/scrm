<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-classification-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);$db=new SchoolFlowStore($dir);
function ac(bool $truth,string $message):void{if(!$truth)throw new RuntimeException('Release A: '.$message);}
function arun(string $verb,string $uri,array $payload=[],string $token='staff'):array{
 global $db;
 try{return sf_handle($verb,$uri,$payload,['authorization'=>'Bearer '.$token],'198.51.100.24',$db);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $db->transaction(function(&$s){
  $s['schools'][]=['id'=>1,'name'=>'Campus One'];
  $s['schools'][]=['id'=>2,'name'=>'Campus Two'];
  $s['users'][]=['id'=>1,'name'=>'Counsellor','email'=>'staff@example.invalid','active'=>true];
  $s['users'][]=['id'=>2,'name'=>'Other','email'=>'other@example.invalid','active'=>true];
  $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Counsellor'];
  $s['memberships'][]=['school_id'=>2,'user_id'=>2,'role'=>'Counsellor'];
  foreach(['staff'=>1,'other'=>2] as $token=>$uid)$s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
 },true);
 [$ok,$lead]=arun('POST','/api/schools/1/leads',['student_name'=>'Example Student','parent_name'=>'Example Parent','phone'=>'9876501234','consent'=>true]);
 ac($ok===201,'create lead');
 $id=$lead['id'];$path='/api/schools/1/leads/'.$id;
 ac($lead['temperature']==='Unrated' && $lead['followup_status']==='Pending Contact','new lead independent defaults');
 ac(arun('PATCH',$path,['temperature'=>'Hot'])[0]===422,'temperature reason is mandatory');
 [$ok,$updated]=arun('PATCH',$path,['temperature'=>'Hot','temperature_reason'=>'Confirmed admission intent','academic_year'=>'2027-28']);
 ac($ok===200 && $updated['temperature']==='Hot' && $updated['academic_year']==='2027-28','classification set with reason');
 for($i=0;$i<3;$i++)ac(arun('POST',$path.'/calls',['outcome'=>'No Answer'])[0]===201,'no answer saved');
 $lead=arun('GET',$path)[1]['lead'];
 ac(empty($lead['last_successful_contact_at'])&&empty($lead['last_contact_at']),'unanswered calls never count as connected');
 ac($lead['contact_state']==='Unresponsive' && $lead['contact_attempts']===3,'three documented attempts');
 ac($lead['temperature']==='Hot','no answers must not downgrade Hot');
 ac($lead['followup_status']==='Unresponsive','unresponsive distinct from temperature');
 ac(arun('POST',$path.'/calls',['outcome'=>'Callback Requested'])[0]===201,'callback logged');
 $lead=arun('GET',$path)[1]['lead'];
 ac($lead['contact_state']==='Callback Requested' && !empty($lead['last_successful_contact_at']),'connected callback status');
 ac($lead['followup_status']==='Callback Requested','callback followup derived');
 ac(arun('PATCH',$path,['followup_date'=>'2020-01-01'])[1]['followup_status']==='Follow-up Overdue','overdue higher priority than callback');
 ac(arun('PATCH',$path,['contact_state'=>'Fabricated'])[0]===422,'invalid contact state rejected');
 ac(arun('GET',$path,[],'other')[0]===403,'other-campus user cannot inspect lead');
 $audit=arun('GET','/api/schools/1/leads/'.$id.'/audit')[1];
 ac(count($audit['events']??[])>=4,'audit events retained');
 echo "PASS: Release A classifications, contact truth, follow-up derivation, reasons, audit and campus isolation\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
