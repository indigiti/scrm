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
 [$resumeCode,$resume]=co_req('POST',$base.'/counsel',[
    'message'=>'Could you explain the admission documents?',
    'school_code'=>'campus-1','chat_session'=>$converted['session']]);
 co_assert($resumeCode===200 && $resume['existing_lead'],
     'remembered signed CRM session attaches to the same enquiry');
 co_assert(sf_counsellor_ai_topic(['fees','curriculum'],['grade'=>'2'])==='Explain general school admission information about fees, curriculum for class 2. If specific campus facts are unavailable, state what needs confirmation.',
    'AI prompt is derived solely from nonpersonal topics');

 [$reportStatus,$report]=co_req('GET','/api/schools/1/public-chat/counsellor-report');
 co_assert($reportStatus===200 && count($report['topics'])>0,'anonymised topic counts available');
 co_assert(co_req('GET','/api/schools/3/public-chat/counsellor-report')[0]!==200,
     'unknown school does not disclose analytics');
 co_assert(co_req('POST',$base.'/counsel',['message'=>'fees','school_code'=>'campus-2'])[1]['reply']!=='',
    'another campus chat still works');

 // School-source scanner only accepts school-registered public domains.
 co_assert(co_req('POST','/api/schools/1/public-chat/sources',[
    'action'=>'add','url'=>'http://admissions.school.test/fees'])[0]===422,
    'HTTP school source rejected');
 co_assert(co_req('POST','/api/schools/1/public-chat/sources',[
    'action'=>'add','url'=>'https://127.0.0.1/admin'])[0]===422,
    'private-IP source forbidden');
 co_assert(co_req('POST','/api/schools/1/public-chat/sources',[
    'action'=>'add','url'=>'https://other.example.test/fees'])[0]===422,
    'unregistered source domain forbidden');
 [$sourceStatus,$sourceAdd]=co_req('POST','/api/schools/1/public-chat/sources',[
    'action'=>'add','url'=>'https://admissions.school.test/fees']);
 co_assert($sourceStatus===201,'registered school source URL added safely');
 [$listStatus,$sourceList]=co_req('GET','/api/schools/1/public-chat/sources');
 co_assert($listStatus===200 && count($sourceList['sources'])===1,'school source library accessible');
 $sourceId=$sourceList['sources'][0]['id'];
 $draftId=sf_uuid();
 $store->transaction(static function(array &$s)use($sourceId,$draftId):void{
    $s['public_chat_source_candidates'][]=[
      'id'=>$draftId,'school_id'=>1,'source_id'=>$sourceId,
      'category'=>'fees','label'=>'Grade 3 tuition',
      'details'=>'Proposed unapproved 123','evidence'=>'Unverified source text',
      'source_hash'=>'hash','status'=>'pending'];
 },true);
 co_assert(!str_contains(json_encode(co_req('GET','/api/schools/1/public-chat')[1]),'Proposed unapproved'),
    'unreviewed website content is not published');
 [$approveStatus,$approved]=co_req('POST','/api/schools/1/public-chat/sources',[
    'action'=>'approve','candidate_id'=>$draftId,
    'label'=>'Grade 3 verified tuition','details'=>'The Grade 3 fee is ₹55,000 in 2026–27.']);
 co_assert($approveStatus===200 && $approved['ok'],'admin-approved source fact published');
 $settings=co_req('GET','/api/schools/1/public-chat')[1];
 co_assert(str_contains(sf_counsellor_fact_answer('grade 3 fees',
     ['grade'=>'3'],$settings),'55,000'),'reviewed source fact reused for school response');
 $state=$store->transaction(static fn(array $s)=>$s);
 co_assert(count($state['public_chat_sources'])===1,'source still belongs to current school');
 // Changed sources must not silently continue publishing stale facts.
 $store->transaction(static function(array &$s)use($sourceId):void{
    foreach($s['public_chat_settings']['1']['school_facts'] as &$fact)
      if(($fact['source_id']??'')===$sourceId)$fact['needs_recheck']=true;
 },true);
 $settings=co_req('GET','/api/schools/1/public-chat')[1];
 co_assert(!str_contains((string)sf_counsellor_fact_answer('grade 3 fees',
     ['grade'=>'3'],$settings),'55,000'),'stale source fact suspended pending re-verification');

 [$staleSaveStatus,$staleSaved]=co_req('PATCH','/api/schools/1/public-chat',[
   'enabled'=>true,'ai_enabled'=>false,'overview'=>'Campus 1 approved school information.',
   'faqs'=>[],'school_facts'=>$settings['school_facts'],'remember_days'=>30
 ]);
 co_assert($staleSaveStatus===200,'unrelated school settings save accepted');
 $stillStale=co_req('GET','/api/schools/1/public-chat')[1];
 co_assert((bool)end($stillStale['school_facts'])['needs_recheck']===true,
     'stale source fact cannot be silently reapproved by saving settings');
 $confirmed=$stillStale['school_facts'];
 $confirmed[count($confirmed)-1]['confirm_recheck']=true;
 [$approvedStatus,$approvedConfig]=co_req('PATCH','/api/schools/1/public-chat',[
   'enabled'=>true,'ai_enabled'=>false,'overview'=>'Campus 1 approved school information.',
   'faqs'=>[],'school_facts'=>$confirmed,'remember_days'=>30
 ]);
 co_assert($approvedStatus===200 &&
   str_contains((string)sf_counsellor_fact_answer('grade 3 fees',['grade'=>'3'],$approvedConfig),'55,000'),
   'explicit administrator re-verification restores changed source fact');



 // Regression: asking "fees" before campus selection must not be forgotten
 // when the parent chooses the relevant branch in the next chat bubble.
 $md=<<<'MARKDOWN'
# Approved campus fees
## 2. Board and curriculum
The school is affiliated to CBSE.

## 3. Campus facilities
- Library
- Science laboratory
- Outdoor sports area

## 6. Age eligibility for the 2026–27 session
| Class | Eligible date of birth | Approximate age |
|---|---|---|
| Pre-Nursery | **1 January 2024 – 31 December 2024** | 2 years and above, below 3 |
| Nursery | **1 January 2023 – 31 December 2023** | 3 years and above, below 4 |
| LKG | **1 January 2022 – 31 December 2022** | 4 years and above, below 5 |
| UKG | **1 January 2021 – 31 December 2021** | 5 years and above, below 6 |
| Class I | **1 January 2020 – 31 December 2020** | 6 years and above, below 7 |
| Classes II–IX | Must have passed the previous class at a recognized school | DOB cutoff not supplied |

## 7. Age eligibility for the 2027–28 session
| Class | Eligible date of birth | Approximate age |
|---|---|---|
| Pre-Nursery | **1 January 2025 – 31 December 2025** | 2 years and above, below 3 |
| Nursery | **1 January 2024 – 31 December 2024** | 3 years and above, below 4 |

## 8. Admission documents
- Birth certificate
- Recent passport photographs
- Previous school report where applicable

## 9. School fees — academic year 2026–27
### Annual fee table, 2026–27
| Class | Term component | Tuition | Stated annual total |
|---|---:|---:|---:|
| Class I | 10,000 | 65,000 | **75,000** |
| Class II | 16,675 | 100,050 | **116,725** |
| Class V | 17,500 | 102,500 | **120,000** |

## 10. School fees — academic year 2027–28
### Annual fee table, 2027–28
| Class | Term component | Tuition | Stated annual total |
|---|---:|---:|---:|
| Class II | 15,133 | 90,794 | **105,927** |
MARKDOWN;
 [$upStatus,$up]=co_req('POST','/api/schools/1/public-chat/knowledge-files',[
   'action'=>'upload','filename'=>'Campus1_fee_schedule.md','content'=>$md]);
 co_assert($upStatus===201,'school knowledge uploaded');
 [$pendingStatus,$pendingTest]=co_req('POST','/api/schools/1/public-chat/knowledge-files',
    ['action'=>'test','question'=>'Class II annual fees 2026-27']);
 co_assert($pendingStatus===200 && $pendingTest['status']==='no_published_files',
    'uploaded files unavailable until approved');
 co_assert(co_req('POST','/api/schools/1/public-chat/knowledge-files',[
   'action'=>'publish','id'=>$up['id'],'confirmed'=>true])[0]===200,
   'admin publishes fee document for selected campus');
 [$previewStatus,$preview]=co_req('POST','/api/schools/1/public-chat/knowledge-files',[
   'action'=>'test','question'=>'Class II annual fees 2026-27']);
 co_assert($previewStatus===200 && $preview['status']==='matched' &&
    str_contains($preview['answer'],'116,725'),'knowledge test confirms approved school fee');
 [$firstStatus,$first]=co_req('POST',$base.'/counsel',[
   'message'=>'What are the school fees?']);
 co_assert($firstStatus===200 && str_contains($first['reply'],'Which campus'),
   'no campus prompts for branch while storing fee intent');
 $continuation=$first['conversation_token'];
 [$chosenStatus,$chosen]=co_req('POST',$base.'/counsel',[
   'conversation_token'=>$continuation,'school_code'=>'campus-1',
   'message'=>'I am interested in the Campus 1 campus.']);
 co_assert($chosenStatus===200 && ($chosen['pending_topic']??'')==='fees' &&
   str_contains($chosen['reply'],'2026') && !str_contains($chosen['reply'],'general admission steps'),
   'campus selection continues pending fee question instead of generic fallback');
 co_assert($chosen['needs_detail']==='academic_year','academic year requested for two fee tables');
 [$yearStatus,$chosenYear]=co_req('POST',$base.'/counsel',[
   'conversation_token'=>$continuation,'school_code'=>'campus-1','message'=>'2026–27']);
 co_assert($yearStatus===200 && str_contains($chosenYear['reply'],'Which class'),
   'bare year continues fee enquiry and requests grade');
 co_assert($chosenYear['needs_detail']==='grade','grade suggestion only after year');
 [$gradeStatus,$gradeReply]=co_req('POST',$base.'/counsel',[
   'conversation_token'=>$continuation,'school_code'=>'campus-1','message'=>'Class II']);
 co_assert($gradeStatus===200 && str_contains($gradeReply['reply'],'₹116,725') &&
    $gradeReply['source']==='approved_knowledge_file',
   'bare grade continues the same school fee enquiry and quotes correct annual total');
 [$separateStatus,$separate]=co_req('POST',$base.'/counsel',[
   'message'=>'Class II fees 2027-28','school_code'=>'campus-1']);
 co_assert($separateStatus===200 && str_contains($separate['reply'],'105,927'),
   'different academic year returns different approved total');
 co_assert(!str_contains($gradeReply['reply'],'105,927'),'two years never mixed');
 [$class1Status,$class1]=co_req('POST',$base.'/counsel',[
   'message'=>'Class I annual fees 2026-27','school_code'=>'campus-1']);
 co_assert($class1Status===200 && str_contains($class1['reply'],'75,000'),
   'Class I fee read from exact approved table');
 [$class5Status,$class5]=co_req('POST',$base.'/counsel',[
   'conversation_token'=>$class1['conversation_token'],'school_code'=>'campus-1',
   'message'=>'fees for 5 th']);
 co_assert($class5Status===200 && str_contains($class5['reply'],'Class V') &&
   str_contains($class5['reply'],'120,000') &&
   !str_contains($class5['reply'],'75,000'),
   'Class V ordinal query overrides older Class I and returns only Grade V price');
 [$boardStatus,$board]=co_req('POST',$base.'/counsel',[
   'message'=>'is cbse','school_code'=>'campus-1']);
 co_assert($boardStatus===200 && $board['source']==='approved_knowledge_file' &&
   str_contains($board['reply'],'CBSE-affiliated') &&
   !str_contains($board['reply'],'General context'),
   'brief school CBSE question gets a direct confirmed school answer');
 [$featureStatus,$feature]=co_req('POST',$base.'/counsel',[
   'message'=>'school features','school_code'=>'campus-1']);
 co_assert($featureStatus===200 && $feature['source']==='approved_knowledge_file' &&
   str_contains($feature['reply'],'• Library') &&
   !str_contains($feature['reply'],'general considerations'),
   'features response is a short list of approved campus facilities');
 [$unverifiedStatus,$unverified]=co_req('POST',$base.'/counsel',[
   'message'=>'is cbse','school_code'=>'campus-2']);
 co_assert($unverifiedStatus===200 &&
   str_contains($unverified['reply'],'not certain') &&
   !str_contains($unverified['reply'],'CBSE-affiliated'),
   'no other campus may inherit positive CBSE affiliation');
 co_assert(!str_contains((string)sf_counsellor_fact_answer(
   'fees for 5 th',['grade'=>'V'],[
   'school_facts'=>[['category'=>'fees','label'=>'Class I fee','details'=>'₹75,000']]]),
   '75,000'),'single structured fee record cannot leak another class price');

 [$ageStatus,$ageResult]=co_req('POST',$base.'/counsel',[
    'message'=>'What is the age eligibility for 2026-27?','school_code'=>'campus-1']);
 co_assert($ageStatus===200 && $ageResult['source']==='approved_knowledge_file' &&
    str_contains($ageResult['reply'],'• Pre-Nursery') &&
    str_contains($ageResult['reply'],'Classes II–IX'),
    'counsellor answers all confirmed age classes as readable list');
 [$docStatus,$docResult]=co_req('POST',$base.'/counsel',[
    'message'=>'Which documents are required for admission?','school_code'=>'campus-1']);
 co_assert($docStatus===200 && $docResult['source']==='approved_knowledge_file' &&
    str_contains($docResult['reply'],'• Birth certificate') &&
    str_contains($docResult['reply'],'• Recent passport photographs'),
    'counsellor searches school document checklist and answers in bullet points');


 echo "PASS: answer-first AI admission counsellor, anonymous intent, school facts, contact consent, qualification and CRM conversion\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
