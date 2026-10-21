<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function ai_assert(bool $value,string $label):void {
    if(!$value)throw new RuntimeException('Public AI regression: '.$label);
}
$dir=sys_get_temp_dir().'/schoolflow-chat-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function ai_req(string $method,string $path,array $payload=[],string $origin='https://example-school.test',string $idem=''):array {
    global $store;
    try{return sf_handle($method,$path,$payload,
      ['origin'=>$origin,'authorization'=>'Bearer admin','idempotency-key'=>$idem],
      '198.51.100.77',$store);
    }catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
  // Isolated synthetic staff, schools and group site.
  $store->transaction(static function(array &$s):void {
    $s['users'][]=['id'=>1,'name'=>'Admin','active'=>true,'email'=>'admin@example.invalid'];
    $s['counters']['users']=1;
    foreach([1,2,3] as $i){
      $s['schools'][]=['id'=>$i,'guid'=>SchoolFlowStore::guid(),'name'=>'Campus '.$i];
      $s['counters']['schools']=$i;
    }
    $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin'];
    $s['memberships'][]=['user_id'=>1,'school_id'=>2,'role'=>'Admin'];
    $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+500];
  },true);
  [$status,$group]=ai_req('POST','/api/group-sites',[
    'name'=>'School Group','domain'=>'example-school.test','school_ids'=>[1,2]]);
  ai_assert($status===201 && !empty($group['guid']),'group setup');
  $guid=$group['guid'];
  $base='/api/public/forms/'.$guid;
  ai_assert(ai_req('GET',$base.'/chat-config')[1]['schools']===[],'disabled by default');
  ai_assert(ai_req('PATCH','/api/schools/3/public-chat',[
    'enabled'=>true,'overview'=>'Other campus','faqs'=>[]])[0]===403,'nonmember cannot publish');
  [$status,$settings]=ai_req('PATCH','/api/schools/1/public-chat',[
    'enabled'=>true,'overview'=>'Campus 1 admissions office.',
    'remember_days'=>30,
    'school_facts'=>[
      ['category'=>'fees','label'=>'Nursery tuition 2026–27','details'=>'Approved tuition fee is INR 42,000 annually.'],
      ['category'=>'fees','label'=>'Class 1 tuition 2026–27','details'=>'Approved tuition fee is INR 48,000 annually.'],
      ['category'=>'age_eligibility','label'=>'Nursery age eligibility','details'=>'Minimum age is 3 years by the school cut-off date.'],
      ['category'=>'documents','label'=>'General admission documents','details'=>'Birth certificate, proof of address and photographs.'],
      ['category'=>'transport','label'=>'Bus availability','details'=>'Bus operates on selected approved routes. Confirm the pickup point with admissions.']
    ],
    'faqs'=>[['question'=>'Which documents are required?',
              'answer'=>'Bring the documents listed on the official admissions page.']]]);
  ai_assert($status===200&&$settings['enabled'],'admin publishing');
  ai_assert(count($settings['school_facts'])===5,'multiple approved school facts saved');
  ai_assert($settings['remember_days']===30,'school-selected visitor memory period');
  ai_assert(str_contains(sf_chat_fact_answer('fees',$settings),'INR 42,000'),
    'approved classwise fees can be answered without AI');
  ai_assert(str_contains(sf_chat_fact_answer('fees',$settings),'INR 48,000'),
    'multiple grade fee entries are preserved');
  ai_assert(str_contains(sf_chat_fact_answer('bus available',$settings),'approved routes'),
    'verified bus availability answered');
  ai_assert(sf_chat_fact_answer('School fees',['school_facts'=>[]])===null,
    'unknown fees are not fabricated');

  ai_assert(ai_req('GET',$base.'/chat-config',[],
       'https://unrelated.test')[0]===403,'unapproved origin denied');
  [$status,$config]=ai_req('GET',$base.'/chat-config');
  ai_assert($status===200&&count($config['schools'])===1,'only enabled campus appears');
  ai_assert($config['schools'][0]['code']==='campus-1','campus isolation');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-2','question'=>'Which documents are required?'])[0]===422,
    'disabled campus cannot be asked');
  ai_assert(sf_chat_expand_topic('fees')==='What is the admission fee structure?',
    'short fees query maps to published admissions fee FAQ');
  ai_assert(sf_chat_expand_topic('documents')==='Which documents are required for school admission?',
    'documents shorthand is understood');
  [$probeStatus,$probeNoKey]=ai_req('POST','/api/schools/1/public-chat/provider/test',[]);
  ai_assert($probeStatus===200 && $probeNoKey['status']==='no_key',
    'admin test reports no saved OpenRouter key without outbound request');
  ai_assert(ai_req('POST','/api/schools/3/public-chat/provider/test',[])[0]===403,
    'OpenRouter diagnostic requires school administrator');
  // Parent must register an enquiry before the assistant may answer.
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','question'=>'Which documents are required?'])[0]===401,
    'no access without a signed chat session');
  ai_assert(ai_req('POST',$base.'/chat-start',[
    'school_code'=>'campus-1','parent_name'=>'Demo Parent','phone'=>'9876543210',
    'student_name'=>'Demo Child','grade'=>'Nursery','consent'=>false
  ],'https://example-school.test','chat-start-000001')[0]===422,
    'consent needed before registering');
  [$status,$challenge]=ai_req('GET',$base.'/challenge');
  preg_match('/Solve: (\d+) \+ (\d+)/',$challenge['question'],$m);
  $startPayload=['school_code'=>'campus-1','parent_name'=>'Demo Parent','phone'=>'9876543210',
    'student_name'=>'Demo Child','grade'=>'Nursery','email'=>'guardian@example.invalid','consent'=>true,
    'captcha_token'=>$challenge['token'],'captcha_answer'=>(string)((int)$m[1]+(int)$m[2]),'website'=>''];
  [$status,$started]=ai_req('POST',$base.'/chat-start',$startPayload,
    'https://example-school.test','chat-start-000002');
  ai_assert($status===201 && !empty($started['session']),'chat creates lead before Q&A');
  ai_assert(count($started['quick_questions'])===1,'published quick question after registration');
  [$status,$repeated]=ai_req('POST',$base.'/chat-start',$startPayload,
    'https://example-school.test','chat-start-000002');
  ai_assert($status===200 && $repeated['session']===$started['session'],'registration is idempotent');
  ai_assert(ai_req('POST',$base.'/chat-start',$startPayload,
    'https://example-school.test','chat-start-000003')[0]===409,
    'CAPTCHA replay rejected');
  $session=$started['session'];
  // Browser storage receives an opaque token only if parent opts in.
  $beforeOpt=$store->transaction(static fn(array $s)=>count($s['public_chat_remember']??[]));
  ai_assert($beforeOpt===0,'no remembered identity stored before opt-in');
  [$rememberStatus,$remembered]=ai_req('POST',$base.'/chat-remember',[
      'school_code'=>'campus-1','session'=>$session]);
  ai_assert($rememberStatus===201 && strlen($remembered['remember_token'])===43,
      'opt-in issues 256-bit opaque return credential');
  ai_assert($remembered['remember_expires_at']>time()+29*86400,'30-day expiry');
  $stored=$store->transaction(static fn(array $s)=>$s);
  ai_assert(!str_contains(json_encode($stored),$remembered['remember_token']),
    'return credential stored hashed only');
  ai_assert(ai_req('POST',$base.'/chat-resume',[
    'remember_token'=>$remembered['remember_token']],'https://unrelated.test')[0]===403,
    'remembered session remains origin bound');
  [$resumeStatus,$resumed]=ai_req('POST',$base.'/chat-resume',[
    'remember_token'=>$remembered['remember_token']]);
  ai_assert($resumeStatus===200 && !empty($resumed['session']) &&
      $resumed['school_code']==='campus-1','returning parent resumes same campus without details');
  ai_assert(ai_req('POST',$base.'/chat-resume',[
    'remember_token'=>$remembered['remember_token']])[0]===401,
    'return credential rotates after use');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','session'=>$resumed['session'],
    'question'=>'What documents are required?'])[0]===200,
    'resumed session can ask admission questions');
  [$forgetStatus,$forgot]=ai_req('POST',$base.'/chat-forget',[
    'remember_token'=>$resumed['remember_token']]);
  ai_assert($forgetStatus===200 && $forgot['ok'],'parent can forget this browser');
  ai_assert(ai_req('POST',$base.'/chat-resume',[
    'remember_token'=>$resumed['remember_token']])[0]===401,
    'forgotten remembered credential cannot return');
  // Even choosing New Chat and resubmitting the same student/grade should
  // reuse one lead, without scheduling a duplicate First Contact task.
  [$st2,$challenge2]=ai_req('GET',$base.'/challenge');
  preg_match('/Solve: (\d+) \+ (\d+)/',$challenge2['question'],$m2);
  $sameLeadPayload=[...$startPayload,'captcha_token'=>$challenge2['token'],
    'captcha_answer'=>(string)((int)$m2[1]+(int)$m2[2])];
  [$againStatus,$again]=ai_req('POST',$base.'/chat-start',$sameLeadPayload,
       'https://example-school.test','chat-start-returning-0003');
  ai_assert($againStatus===201 && ($again['reused_lead']??false)===true,
    'new chat with same student and class reuses earlier lead');
  $afterNew=$store->transaction(static fn(array $s)=>$s);
  ai_assert(count($afterNew['leads'])===1,'repeat enquiry does not duplicate CRM lead');

  $question=['school_code'=>'campus-1','session'=>$session,'question'=>'Which documents are required?'];
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-2','session'=>$session,'question'=>'Which documents are required?'])[0]===422,
    'session cannot switch to another campus');
  ai_assert(ai_req('POST',$base.'/chat',[
    ...$question,'session'=>'invalid-token'])[0]===401,'forged token refused');
  ai_assert(ai_req('POST',$base.'/chat',$question,'https://unrelated.test')[0]===403,
    'other website origin refused');
  putenv('SCHOOLFLOW_OPENAI_API_KEY');
  [$status,$answer]=ai_req('POST',$base.'/chat',$question);
  ai_assert($status===200 && ($answer['source']??'')==='approved_knowledge','offline verified FAQ');
  ai_assert(str_contains($answer['answer'],'official admissions page'),'approved answer returned');
  [$status,$unknown]=ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','session'=>$session,'question'=>'What is the weekend holiday policy?']);
  ai_assert($status===200 && ($unknown['source']??'')==='human_handoff','unknown question not invented');
  [$status,$review]=ai_req('GET','/api/schools/1/public-chat/review');
  ai_assert($status===200 && count($review['items'])===1,'review queue of unanswered topics');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','session'=>$session,
    'question'=>'My phone 9876543210 what is the bus pickup schedule?'])[0]===200,'contact data refused in chat');
  [$status,$reviewAfter]=ai_req('GET','/api/schools/1/public-chat/review');
  ai_assert(count($reviewAfter['items'])===1,'private question not learned');
  $item=$review['items'][0];
  ai_assert(ai_req('POST','/api/schools/2/public-chat/review',[
      'id'=>$item['id'],'decision'=>'approve','answer'=>'Wrong school'])[0]===404,'review isolation');
  [$status,$approved]=ai_req('POST','/api/schools/1/public-chat/review',[
      'id'=>$item['id'],'decision'=>'approve','answer'=>'Contact transport admissions for current routes.']);
  ai_assert($status===200 && $approved['ok'],'staff approval of verified information');
  [$status,$result]=ai_req('POST',$base.'/chat',[
      'school_code'=>'campus-1','session'=>$session,'question'=>'What is the weekend holiday policy?']);
  ai_assert(($result['source']??'')==='approved_knowledge','approved answer works offline');
  [$feeStatus,$feeReply]=ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','session'=>$session,'question'=>'fees']);
  ai_assert($feeStatus===200 && ($feeReply['source']??'')==='approved_school_information' &&
    str_contains($feeReply['answer'],'INR 42,000'),
    'fees are answered from approved school facts without needing AI');

  // Provider key is encrypted per school. No API response ever returns it.
  $rawKey='sk-or-v1-'.str_repeat('a',32);
  [$status,$provider]=ai_req('PATCH','/api/schools/1/public-chat/provider',[
    'api_key'=>$rawKey,'model'=>'openai/gpt-4o-mini'
  ]);
  ai_assert($status===200 && $provider['connected'],'OpenRouter configured');
  ai_assert(!str_contains(json_encode($provider),$rawKey),'provider response redacted');
  [$status,$providerGet]=ai_req('GET','/api/schools/1/public-chat/provider');
  ai_assert($status===200 && $providerGet['connected'],'provider status');
  [$status,$settingsGet]=ai_req('GET','/api/schools/1/public-chat');
  ai_assert(!str_contains(json_encode($settingsGet),$rawKey),'chat settings redacted');
  $state=$store->transaction(static fn(array $s)=>$s);
  ai_assert(!str_contains(json_encode($state),$rawKey),'OpenRouter key encrypted in JSON store');
  ai_assert($store->unseal($state['public_chat_settings']['1']['openrouter_key'])===$rawKey,
    'encrypted key decrypts with private store key');
  ai_assert(ai_req('GET','/api/schools/2/public-chat/provider')[1]['connected']===false,'provider school isolation');
  ai_assert(ai_req('POST',$base.'/chat-followup',[
    'school_code'=>'campus-1','session'=>'fake'])[0]===401,'unauthorized callback denied');
  [$status,$followup]=ai_req('POST',$base.'/chat-followup',[
    'school_code'=>'campus-1','session'=>$session]);
  ai_assert($status===200 && $followup['ok'],'session parent callback');
  $state=$store->transaction(static fn(array $s)=>$s);
  ai_assert(count($state['leads'])===1 && $state['leads'][0]['school_id']===1,'single school-scoped chat lead');
  ai_assert($state['leads'][0]['contact_state']==='Callback Requested','followup on same lead');
  ai_assert(!empty($state['leads'][0]['consent']),'lead consent stored');
  ai_assert(($state['leads'][0]['lead_channel']??'')==='chatbot','new lead has permanent chatbot tracking channel');
  $leadView=sf_lead_view($state,$state['leads'][0]);
  ai_assert(($leadView['chatbot_lead']??false)===true,'chatbot flag visible to CRM workspace');
  ai_assert(str_starts_with((string)$leadView['source_name'],'Chatbot · '),'chatbot source displayed distinct from ordinary website form');
  $old=$state['leads'][0];unset($old['lead_channel']);
  ai_assert(sf_lead_view($state,$old)['chatbot_lead']===true,'historical chat lead grouped by UTM source');

  [$status,$disconnected]=ai_req('PATCH','/api/schools/1/public-chat/provider',[
    'model'=>'openai/gpt-4o-mini','remove_key'=>true
  ]);
  ai_assert($status===200 && !$disconnected['connected'],'provider key can be removed');
  echo "PASS: mandatory consented chat lead, signed token, offline FAQ, OpenRouter encryption, campus isolation, callback\n";
}finally{
  foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
  @rmdir($dir);
}
