<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-rel-audit-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$db=new SchoolFlowStore($dir);
function ar(bool $condition,string $msg):void {if(!$condition)throw new RuntimeException('Release audit: '.$msg);}
function callRelease(string $method,string $path,array $p=[],string $user='t1',?callable $provider=null):array {
 global $db;
 try{return sf_handle($method,$path,$p,['authorization'=>'Bearer '.$user],'198.51.100.21',$db,null,$provider);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
 $db->transaction(function(&$s){
  foreach(['North Campus','West Campus'] as $name)$s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name];
  foreach([['Admin','Admin',1],['Counsellor','Counsellor',1],['Other','Admin',2]] as [$name,$role,$sid]){
   $id=sf_id($s,'users');$s['users'][]=['id'=>$id,'name'=>$name,'active'=>true,'email'=>"$id@example.invalid"];
   $s['memberships'][]=['school_id'=>$sid,'user_id'=>$id,'role'=>$role];
   $s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash('t'.$id),'expires_at'=>time()+3600];
  }
 },true);
 $snap=$db->transaction(fn($s)=>$s);
 foreach(['users','schools','memberships','sessions'] as $t)ar(!empty($snap[$t][0]['guid']),$t.' initial GUID');
 $stable=$snap['schools'][0]['guid'];
 $db->transaction(function(&$s){$s['schools'][0]['name']='North Updated';},true);
 ar($db->transaction(fn($s)=>$s['schools'][0]['guid'])===$stable,'GUID immutable after subsequent writes');
 $p=['student_name'=>'Child One','parent_name'=>'Guardian One','phone'=>'+919999001122','grade'=>'Grade 1','email'=>'parent@example.com','consent'=>true];
 [$code,$lead]=callRelease('POST','/api/schools/1/leads',$p);
 ar($code===201&&preg_match('/^[0-9a-f]{8}-/',$lead['guid'])===1,'new lead exposes GUID');
 ar(callRelease('GET','/api/schools/1/leads/'.$lead['guid'])[0]===200,'GET enquiry by GUID');
 ar(callRelease('GET','/api/schools/'.$stable.'/leads/'.$lead['guid'])[0]===200,'school and lead GUIDs route');
 ar(callRelease('POST','/api/schools/1/leads/'.$lead['guid'].'/view',[])[0]===200,'audit view via GUID');
 ar(callRelease('GET','/api/schools/1/leads/'.$lead['id'])[0]===200,'numeric legacy routing preserved');
 ar(callRelease('GET','/api/schools/1/leads/'.$lead['guid'],[],'t3')[0]===403,'cross-school GUID not accessible');
 ar(callRelease('POST','/api/schools/1/communications/email/send',['lead_id'=>$lead['guid'],'request_id'=>SchoolFlowStore::guid()])[0]===409,'not configured blocks send');
 $settings=['email_provider'=>'resend','email_from'=>'admissions@example.com','email_api_key'=>'re_PRIVATE_TEST_TOKEN',
  'wa_provider'=>'meta','wa_api_key'=>'EAA_PRIVATE_TEST_TOKEN','phone_number_id'=>'123456789012345',
  'wa_template'=>'admission_enquiry_followup','wa_language'=>'en_US'];
 ar(callRelease('PATCH','/api/schools/1/communications',$settings,'t2')[0]===403,'counsellor cannot configure provider');
 [$code,$view]=callRelease('PATCH','/api/schools/1/communications',$settings);
 ar($code===200&&$view['email']['connected']&&$view['whatsapp']['connected'],'both provider statuses connected');
 $snapshot=json_encode($db->transaction(fn($s)=>$s));
 ar(!str_contains($snapshot,'re_PRIVATE_TEST_TOKEN')&&!str_contains($snapshot,'EAA_PRIVATE_TEST_TOKEN'),'secret never written in plaintext store');
 ar(is_file($dir.'/messaging.key')&&strlen(file_get_contents($dir.'/messaging.key'))===32,'private 32-byte encryption key');
 ar(!str_contains(json_encode(callRelease('GET','/api/schools/1/communications')[1]),'PRIVATE_TEST_TOKEN'),'GET settings redacted');
 $seen=[];$mock=function($channel,$body,$nonce)use(&$seen){$seen[]=[$channel,$body,$nonce];return ['ok'=>true,'provider_id'=>'sample-'.$channel];};
 $uuid=SchoolFlowStore::guid();
 [$code,$sent]=callRelease('POST','/api/schools/1/communications/email/send',['lead_id'=>$lead['guid'],'request_id'=>$uuid],'t2',$mock);
 ar($code===202 && $sent['status']==='accepted','assigned counsellor can send email');
 ar($seen[0][1]['to'][0]==='parent@example.com','send routed to guardian email');
 ar(callRelease('POST','/api/schools/1/communications/email/send',['lead_id'=>$lead['guid'],'request_id'=>$uuid],'t2',$mock)[0]===409,'idempotent send');
 ar(count($seen)===1,'replay did not invoke provider');
 ar(callRelease('POST','/api/schools/1/communications/whatsapp/send',['lead_id'=>$lead['guid'],'request_id'=>SchoolFlowStore::guid()],'t2',$mock)[0]===202,'meta approved template accepted');
 ar($seen[1][1]['template']['name']==='admission_enquiry_followup','Meta template shape');
 $other=callRelease('POST','/api/schools/2/leads',[...$p,'student_name'=>'Other School Child'],'t3');
 ar(callRelease('POST','/api/schools/1/communications/email/send',['lead_id'=>$other[1]['guid'],'request_id'=>SchoolFlowStore::guid()],'t2',$mock)[0]===404,'cross-school lead cannot be sent');
 $none=callRelease('POST','/api/schools/1/leads',[...$p,'phone'=>'+919999001133','consent'=>false]);
 ar(callRelease('POST','/api/schools/1/communications/whatsapp/send',['lead_id'=>$none[1]['guid'],'request_id'=>SchoolFlowStore::guid()],'t1',$mock)[0]===403,'unconsented send rejected');
 $detail=callRelease('GET','/api/schools/1/leads/'.$lead['guid'])[1];
 ar(count($detail['messages'])===2,'outbound history visible in enquiry sidebar');
 ar(count(array_filter($detail['audit']['events'],fn($a)=>$a['action']==='message_accepted'))===2,'accepted sends audited');
 $d=callRelease('POST','/api/schools/1/demo-data/seed');
 ar($d[0]===201&&$d[1]['leads']===18,'full seed created');
 $snap=$db->transaction(fn($s)=>$s);
 ar(count(array_filter($snap['calls'],fn($c)=>!empty($c['is_demo'])))>=8,'demo calls generated');
 ar(count(array_filter($snap['visits'],fn($v)=>!empty($v['is_demo'])))>=3,'demo visits generated');
 $demoId=array_values(array_filter($snap['leads'],fn($l)=>!empty($l['is_demo'])))[0];
 ar(callRelease('POST','/api/schools/1/communications/whatsapp/send',['lead_id'=>$demoId['guid'],'request_id'=>SchoolFlowStore::guid()],'t1',$mock)[0]===403,'demo cannot send real messages');
 ar(callRelease('POST','/api/schools/1/demo-data/clear',['confirm'=>'CLEAR DEMO'])[0]===200,'demo cleanup');
 $end=$db->transaction(fn($s)=>$s);
 ar(count(array_filter($end['calls'],fn($c)=>!empty($c['is_demo'])))===0,'no orphaned demo calls');
 ar(count(array_filter($end['visits'],fn($c)=>!empty($c['is_demo'])))===0,'no orphaned demo visits');
 ar(count(array_filter($end['leads'],fn($c)=>!empty($c['is_demo'])))===0,'no demo leads after clear');
 ar(count(array_filter($end['audit_logs'],fn($c)=>$c['action']==='demo_cleared'))===1,'demo clear audit retained');
 ar(callRelease('GET','/api/schools/1/leads/'.$lead['guid'])[0]===200,'real lead survives demo cleanup');
 echo "PASS: GUID migration, backward routes, encrypted API credentials, roles, consent, delivery audit, duplicate sends, complete demo lifecycle\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
