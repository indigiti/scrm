<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function co_assert(bool $v,string $label):void {if(!$v)throw new RuntimeException('Counsellor regression: '.$label);}
$dir=sys_get_temp_dir().'/schoolflow-counsellor-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function co_req(string $method,string $path,array $p=[],string $origin='https://admissions.school.test',string $idem=''):array {
 global $store;
 try{return sf_handle($method,$path,$p,[
   'origin'=>$origin,'authorization'=>'Bearer admin','idempotency-key'=>$idem
 ],'198.51.100.34',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
 $store->transaction(static function(array &$s):void {
   $s['users'][]=['id'=>1,'name'=>'Admin','active'=>true,'email'=>'admin@example.invalid'];
   $s['counters']['users']=1;
   foreach([1,2] as $i){
     $s['schools'][]=['id'=>$i,'guid'=>SchoolFlowStore::guid(),'name'=>'Campus '.$i];
     $s['counters']['schools']=$i;
     $s['memberships'][]=['user_id'=>1,'school_id'=>$i,'role'=>'Admin'];
   }
   $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+1000];
 },true);
 [$gs,$group]=co_req('POST','/api/group-sites',[
   'name'=>'Admission school','domain'=>'admissions.school.test','school_ids'=>[1,2]]);
 co_assert($gs===201,'test website exists');
 $base='/api/public/forms/'.$group['guid'];
 foreach([1,2] as $schoolId){
   [$status,$data]=co_req('PATCH','/api/schools/'.$schoolId.'/public-chat',[
     'enabled'=>true,'ai_enabled'=>false,'overview'=>'Campus '.$schoolId.' has an admissions office.',
     'faqs'=>[],
     'school_facts'=>[
       ['category'=>'fees','label'=>'Grade 2 tuition','details'=>$schoolId===1?'Verified ₹50,000 per year.':'Verified ₹60,000 per year.'],
       ['category'=>'transport','label'=>'Bus coverage','details'=>'Ask the campus for current approved routes.']
     ]
   ]);
   co_assert($status===200,'school published knowledge');
 }
 [$beforeStatus,$before]=co_req('POST',$base.'/counsel',['message'=>'Hi, what is the Grade 2 fee?','school_code'=>'campus-1']);
 co_assert($beforeStatus===200,'anonymous conversation works');
 co_assert(str_contains($before['reply'],'50,000'),'grade fee answered first without lead');
 co_assert(!str_contains($before['reply'],'60,000'),'school two fee never crosses');
 co_assert(strlen($before['conversation_token'])===43,'anonymous bearer issued');
 $token=$before['conversation_token'];
 $s=$store->transaction(static fn(array $s)=>$s);
 co_assert(count($s['leads'])===0,'no CRM lead or consent demanded before answers');
 co_assert(count($s['public_counsellor_sessions'])===1,'anonymous intent metadata stored');
 co_assert(!str_contains(json_encode($s['public_counsellor_sessions']),'Grade 2 fee'),
   'raw chat message not persisted');
 co_assert(co_req('POST',$base.'/counsel',['message'=>'What about transport?','conversation_token'=>$token],
      'https://evil.example.test')[0]===403,'unregistered website rejected');
 [$ctxStatus,$ctx]=co_req('POST',$base.'/counsel',[
   'message'=>'We live in Hinjewadi and would like to visit Saturday',
   'conversation_token'=>$token,'school_code'=>'campus-1'
 ]);
 co_assert($ctxStatus===200 && $ctx['profile']['residential_area']==='Hinjewadi','residential area extracted');
 co_assert($ctx['profile']['visit_preference']==='Saturday' && $ctx['qualified']==='High interest','visit intent qualified');
 co_assert($ctx['suggest_contact']===true,'visitor offered optional handoff');
 $s=$store->transaction(static fn(array $s)=>$s);
 co_assert(count($s['leads'])===0,'visit does not silently create a lead');
 [$challengeStatus,$challenge]=co_req('GET',$base.'/challenge');
 co_assert($challengeStatus===200,'math challenge available');
 preg_match('/Solve: (\d+) \+ (\d+)/',$challenge['question'],$m);
 $form=['conversation_token'=>$token,'phone'=>'9876543210','parent_name'=>'Rahul',
   'captcha_token'=>$challenge['token'],'captcha_answer'=>(string)((int)$m[1]+(int)$m[2])];
 co_assert(co_req('POST',$base.'/counsel-lead',$form,'https://admissions.school.test','cnsl-idem-00001')[0]===422,'consent mandatory');
 [$convertStatus,$converted]=co_req('POST',$base.'/counsel-lead',[
   ...$form,'consent'=>true],'https://admissions.school.test','cnsl-idem-00001');
 co_assert($convertStatus===201 && !empty($converted['session']),'contact consent converts anonymous enquiry to lead');
 $state=$store->transaction(static fn(array $s)=>$s);
 co_assert(count($state['leads'])===1,'one CRM lead created');
 co_assert($state['leads'][0]['grade']==='2','inferred grade retained from conversation');
 co_assert(($state['leads'][0]['lead_channel']??'')==='chatbot','lead channel labelled chatbot');
 co_assert($state['leads'][0]['ai_intent']==='High interest','lead qualification stored');
 co_assert($state['leads'][0]['consent']==1,'CRM permission persisted');
 [$reportStatus,$report]=co_req('GET','/api/schools/1/public-chat/counsellor-report');
 co_assert($reportStatus===200 && count($report['topics'])>0,'anonymised topic counts available');
 co_assert(co_req('GET','/api/schools/3/public-chat/counsellor-report')[0]!==200,
     'unknown school does not disclose analytics');
 co_assert(co_req('POST',$base.'/counsel',['message'=>'fees','school_code'=>'campus-2'])[1]['reply']!=='',
    'another campus chat still works');
 echo "PASS: answer-first AI admission counsellor, anonymous intent, school facts, contact consent, qualification and CRM conversion\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
