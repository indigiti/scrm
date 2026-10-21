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
  ai_assert(ai_req('GET',$base.'/chat-config','',
       'https://unrelated.test')[0]===403,'unapproved origin denied');
  [$status,$config]=ai_req('GET',$base.'/chat-config');
  ai_assert($status===200&&count($config['schools'])===1,'only enabled campus appears');
  ai_assert($config['schools'][0]['code']==='campus-1','campus isolation');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-2','question'=>'Which documents are required?'])[0]===422,
    'disabled campus cannot be asked');
  // No provider key: exact verified knowledge remains useful offline.
  putenv('SCHOOLFLOW_OPENAI_API_KEY');
  [$status,$answer]=ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','question'=>'Which documents are required?']);
  ai_assert($status===200&&($answer['source']??'')==='approved_knowledge',
    'offline verified source');
  ai_assert(str_contains($answer['answer'],'official admissions page'),'approved answer returned');
  [$status,$unknown]=ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','question'=>'What is the bus pickup schedule?']);
  ai_assert($status===200 && ($unknown['source']??'')==='human_handoff','unknown question not guessed');
  [$status,$review]=ai_req('GET','/api/schools/1/public-chat/review');
  ai_assert($status===200&&count($review['items'])===1,'new topic review queue');
  ai_assert(ai_req('POST',$base.'/chat',[
    'school_code'=>'campus-1','question'=>'My phone 9876543210 what is the bus pickup schedule?'])[0]===200,'private question blocked safely');
  [$status,$reviewAfter]=ai_req('GET','/api/schools/1/public-chat/review');
  ai_assert(count($reviewAfter['items'])===1,'personal question not learned');
  $item=$review['items'][0];
  ai_assert(ai_req('POST','/api/schools/2/public-chat/review',[
      'id'=>$item['id'],'decision'=>'approve','answer'=>'Wrong school'])[0]===404,
      'cross-school review isolation');
  [$status,$approved]=ai_req('POST','/api/schools/1/public-chat/review',[
      'id'=>$item['id'],'decision'=>'approve','answer'=>'Contact the transport desk for current routes.']);
  ai_assert($status===200&&$approved['ok'],'approved learning');
  [$status,$result]=ai_req('POST',$base.'/chat',[
      'school_code'=>'campus-1','question'=>'What is the bus pickup schedule?']);
  ai_assert(($result['source']??'')==='approved_knowledge','approved previous question reused without AI');
  // Callback must verify guardian consent, CAPTCHA and idempotency.
  [$status,$challenge]=ai_req('GET',$base.'/challenge');
  preg_match('/Solve: (\\d+) \\+ (\\d+)/',$challenge['question'],$m);
  $callback=[
    'school_code'=>'campus-1','parent_name'=>'Demo Guardian','phone'=>'9876543210',
    'student_name'=>'Demo Child','consent'=>true,'captcha_token'=>$challenge['token'],
    'captcha_answer'=>(string)((int)$m[1]+(int)$m[2])
  ];
  ai_assert(ai_req('POST',$base.'/callback',[...$callback,'consent'=>false],
     'https://example-school.test','chat-callback-00001')[0]===422,'consent enforcement');
  [$status,$lead]=ai_req('POST',$base.'/callback',$callback,'https://example-school.test','chat-callback-00002');
  ai_assert($status===201 && !empty($lead['guid']),'callback creates lead');
  ai_assert(ai_req('POST',$base.'/callback',$callback,'https://example-school.test',
    'chat-callback-00002')[1]['duplicate_submission']===true,'idempotent callback');
  ai_assert(ai_req('POST',$base.'/callback',$callback,'https://example-school.test',
    'chat-callback-00003')[0]===409,'replayed challenge blocked');
  $state=$store->transaction(static fn(array $s)=>$s);
  ai_assert(count($state['leads'])===1 && $state['leads'][0]['school_id']===1,
    'callback lead bound to approved campus');
  ai_assert($state['leads'][0]['contact_state']==='Callback Requested','callback state');
  ai_assert(!empty($state['leads'][0]['consent']),'consent stored');
  echo "PASS: published knowledge, offline FAQ, admin review, tenant isolation, callback and consent\n";
}finally{
  foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
  @rmdir($dir);
}
