<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-ops-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function oc(bool $test,string $message):void{if(!$test)throw new RuntimeException('Admissions operations: '.$message);}
function orq(string $method,string $path,array $payload=[],string $token='admin'):array {
 global $store;
 try{return sf_handle($method,$path,$payload,['authorization'=>'Bearer '.$token],'198.51.100.6',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(&$s){
  $s['schools'][]=['id'=>1,'name'=>'Campus One'];$s['schools'][]=['id'=>2,'name'=>'Campus Two'];
  $s['users'][]=['id'=>1,'name'=>'Administrator','email'=>'admin@example.invalid','active'=>true];
  $s['users'][]=['id'=>2,'name'=>'Counsellor','email'=>'counsellor@example.invalid','active'=>true];
  $s['users'][]=['id'=>3,'name'=>'Other','email'=>'other@example.invalid','active'=>true];
  $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Admin'];
  $s['memberships'][]=['school_id'=>1,'user_id'=>2,'role'=>'Counsellor'];
  $s['memberships'][]=['school_id'=>2,'user_id'=>3,'role'=>'Admin'];
  foreach(['admin'=>1,'counsellor'=>2,'other'=>3] as $name=>$id)$s['sessions'][]=[
    'user_id'=>$id,'token_hash'=>sf_hash($name),'expires_at'=>time()+3600];
 },true);
 $base='/api/schools/1';
 oc(orq('PATCH',$base.'/response-sla',['first_contact_minutes'=>120])[0]===200,'admin updates SLA');
 oc(orq('PATCH',$base.'/response-sla',['first_contact_minutes'=>1])[0]===422,'out of range SLA rejected');
 oc(orq('GET',$base.'/response-sla',[],'counsellor')[0]===403,'counsellor cannot change policy');
 [$c,$lead]=orq('POST',$base.'/leads',[
   'student_name'=>'Test Student','parent_name'=>'Test Parent','phone'=>'9876543210',
   'grade'=>'I','academic_year'=>'2027-28','assigned_to'=>2,'consent'=>true
 ]);
 oc($c===201,'lead created');
 $id=$lead['id'];
 $docs=orq('GET',$base.'/leads/'.$id.'/documents',[],'counsellor')[1];
 oc(count($docs)===count(SF_DOCUMENT_TYPES)&&$docs[0]['status']==='Pending','checklist initialized lazily');
 oc(orq('PATCH',$base.'/leads/'.$id.'/documents',['type'=>'Birth Certificate','status'=>'Verified'],'counsellor')[0]===403,'counsellor cannot verify');
 oc(orq('PATCH',$base.'/leads/'.$id.'/documents',['type'=>'Birth Certificate','status'=>'Received'],'counsellor')[0]===200,'counsellor can record receipt');
 oc(orq('PATCH',$base.'/leads/'.$id.'/documents',['type'=>'Birth Certificate','status'=>'Verified','note'=>'Validated at reception'])[0]===200,'admissions admin verifies');
 $docs=orq('GET',$base.'/leads/'.$id.'/documents')[1];
 oc($docs[0]['status']==='Verified','document verified status persisted');
 oc(orq('GET',$base.'/leads/'.$id.'/documents',[],'other')[0]===403,'other-campus document access denied');
 oc(orq('PATCH',$base.'/seat-plans',['academic_year'=>'2027-28','grade'=>'I','capacity'=>1])[0]===200,'grade/year capacity configured');
 oc(orq('PATCH',$base.'/seat-plans',['academic_year'=>'2027-28','grade'=>'I','capacity'=>-1])[0]===422,'invalid seat capacity rejected');
 $other=orq('POST',$base.'/leads',[
   'student_name'=>'Second Student','parent_name'=>'Second Parent','phone'=>'9876543211',
   'grade'=>'I','academic_year'=>'2027-28','assigned_to'=>2,'consent'=>true
 ])[1];
 $apps=[];
 foreach([$id,$other['id']] as $lid){
  $app=orq('POST',$base.'/applications',['lead_id'=>$lid])[1];$apps[]=$app;
  oc(orq('PATCH',$base.'/applications/'.$app['id'],['status'=>'Admission Confirmed'])[0]===200,'admission offer confirmed');
 }
 oc(orq('PATCH',$base.'/applications/'.$apps[0]['id'],['status'=>'Enrolled','fee_recorded'=>true])[0]===200,'first student consumes one seat');
 oc(orq('PATCH',$base.'/applications/'.$apps[1]['id'],['status'=>'Enrolled','fee_recorded'=>true])[0]===409,'capacity blocks second enrollment');
 $seats=orq('GET',$base.'/seat-plans')[1];
 oc(count($seats)===1&&$seats[0]['remaining']===0&&$seats[0]['enrolled']===1,'remaining seats reflected in report');
 oc(orq('PATCH',$base.'/seat-plans',['academic_year'=>'2027-28','grade'=>'I','capacity'=>0])[0]===422,'cannot lower cap below enrolled');
 $visit=orq('POST',$base.'/visits',['lead_id'=>$other['id'],'starts_at'=>'2027-11-01T10:30','purpose'=>'Campus tour','staff_id'=>2])[1];
 oc(orq('PATCH',$base.'/visits/'.$visit['id'],['status'=>'Completed','feedback'=>'Parents liked facilities','next_followup_date'=>'2027-11-03'])[0]===200,'visit outcome saved');
 $updated=orq('GET',$base.'/leads/'.$other['id'])[1]['lead'];
 oc($updated['followup_date']==='2027-11-03','visit schedules next contact');
 $visits=orq('GET',$base.'/visits')[1];
 oc(str_contains($visits[0]['feedback'],'facilities'),'feedback shown in visit record');
 $policy=['enabled'=>false,'email'=>false,'whatsapp'=>false,'task_kinds'=>[],'max_daily'=>5,
    'send_start'=>'09:00','send_end'=>'18:00'];
 oc(orq('PATCH',$base.'/automation',$policy)[0]===200,'quiet hours saved');
 oc(orq('PATCH',$base.'/automation',[...$policy,'send_start'=>'22:00','send_end'=>'08:00'])[0]===422,'overnight window disallowed');
 oc(sf_auto_within_hours($policy,new DateTimeImmutable('2027-11-01T11:00:00+05:30')),'in-window dispatch allowed');
 oc(!sf_auto_within_hours($policy,new DateTimeImmutable('2027-11-01T20:00:00+05:30')),'after-hours dispatch blocked');
 $store->transaction(static function(&$s)use($other){
  foreach($s['leads'] as &$l)if($l['id']===$other['id']){$l['created_at']='2026-01-01T00:00:00+00:00';break;}unset($l);
 },true);
 $summary=orq('GET',$base.'/analytics')[1];
 oc(($summary['sla_breached']??0)>=1,'late first responses appear in analytic SLA metric');
 $overview=orq('GET','/api/schools/overview')[1];
 oc($overview[0]['enrolled']===1 && isset($overview[0]['hot_leads'],$overview[0]['sla_breached']),'cross-campus admin overview enriched');
 echo "PASS: document checklist, verification roles, enrollment capacity, visit feedback, SLA metrics and quiet hours\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
