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
    'faqs'=>[['question'=>'Which documents are required?',
              'answer'=>'Bring the documents listed on the official admissions page.']]]);
  ai_assert($status===200&&$settings['enabled'],'admin publishing');
  ai_assert(ai_req('GET',$base.'/chat-config',[],
       'https://unrelated.test')[0]===403,'unapproved origin denied');
  [$status,$config]=ai_req('GET',$base.'/chat-config');
  ai_assert($status===200&&count($config['schools'])===1,'only enabled campus appears');
  ai_assert($config['schools'][0]['code']==='campus-1','campus isolation');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-2','question'=>'Which documents are required?'])[0]===422,
    'disabled campus cannot be asked');
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
    'school_code'=>'campus-1','session'=>$session,'question'=>'What is the bus pickup schedule?']);
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
      'school_code'=>'campus-1','session'=>$session,'question'=>'What is the bus pickup schedule?']);
  ai_assert(($result['source']??'')==='approved_knowledge','approved answer works offline');
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
  [$status,$disconnected]=ai_req('PATCH','/api/schools/1/public-chat/provider',[
    'model'=>'openai/gpt-4o-mini','remove_key'=>true
  ]);
  ai_assert($status===200 && !$disconnected['connected'],'provider key can be removed');
  echo "PASS: mandatory consented chat lead, signed token, offline FAQ, OpenRouter encryption, campus isolation, callback\n";
}finally{
  foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
  @rmdir($dir);
}
