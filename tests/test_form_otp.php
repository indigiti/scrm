<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-otp-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function otp_ok(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('OTP test: '.$why);}
function otp_api(string $verb,string $url,array $p=[],string $who='admin',string $idem=''):array{
 global $store;
 try{return sf_handle($verb,$url,$p,['authorization'=>'Bearer '.$who,
   'origin'=>'https://lexiconedu.in','idempotency-key'=>$idem],'198.51.100.10',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
function otp_math(string $url):array{
 $c=otp_api('GET',$url.'/challenge')[1];
 preg_match('/Solve: (\d+) \+ (\d+)/',$c['question'],$m);
 return [$c['token'],(string)((int)$m[1]+(int)$m[2])];
}
try{
 putenv('SCHOOLFLOW_WABRIDGE_API_KEY='.str_repeat('x',40));
 $store->transaction(static function(array &$s):void{
  foreach([1,2] as $id)$s['schools'][]=['id'=>$id,'name'=>'Campus '.$id];
  $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
  foreach([1,2] as $id)$s['memberships'][]=['user_id'=>1,'school_id'=>$id,'role'=>'Admin'];
  $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+3600];
  $s['counters']['schools']=2;$s['counters']['users']=1;
 },true);
 [$created,$g]=otp_api('POST','/api/group-sites',['name'=>'School Group',
   'domain'=>'lexiconedu.in','school_ids'=>[1,2]]);
 otp_ok($created===201,'create school group');
 $base='/api/public/forms/'.$g['guid'];$settings='/api/group-sites/'.$g['id'].'/otp-settings';
 otp_ok(otp_api('GET',$base.'/config')[1]['otp']['required']===false,'default off');
 [$status,$config]=otp_api('PATCH',$settings,
   ['enabled'=>true,'short_forms'=>true,'session_id'=>'schoolflow-session']);
 otp_ok($status===200&&$config['has_api_key']&&!isset($config['api_key']),'admin policy and secret redaction');
 otp_ok(otp_api('GET',$base.'/config')[1]['otp']['required']===true,'public policy required');
 [$token,$answer]=otp_math($base);
 $request=['consent'=>true,'phone'=>'9876543210','school_code'=>'campus-1',
   'landing_page'=>'https://lexiconedu.in/lp/',
   'captcha_token'=>$token,'captcha_answer'=>$answer];
 $code='';$providerId=sf_uuid();
 $sender=static function(array $body,string $key)use(&$code,$providerId):array{
  otp_ok($body['to']==='919876543210'&&str_starts_with($key,'schoolflow-otp-'),'scoped queue');
  preg_match('/code: ([0-9]{6})/',$body['text'],$m);$code=$m[1]??'';
  return ['http'=>202,'data'=>['job_id'=>$providerId]];
 };
 [$queued,$result]=sf_form_otp_request($g['guid'],'https://lexiconedu.in',
    $request,'198.51.100.10',$store,$sender);
 otp_ok($queued===202&&!isset($result['code'])&&strlen($code)===6,'queued without code leak');
 $verify=['otp_id'=>$result['otp_id'],'phone'=>'9876543210','school_code'=>'campus-1'];
 otp_ok(otp_api('POST',$base.'/otp/verify',$verify+['code'=>'000000'])[0]===422,'wrong code rejected');
 $snapshot=$store->transaction(static fn(array $s)=>$s);
 otp_ok(sf_form_otp_public_record($snapshot,$result['otp_id'])['attempts']===1,'wrong attempt persisted');
 otp_ok(otp_api('POST',$base.'/otp/verify',[...$verify,'school_code'=>'campus-2','code'=>$code])[0]===422,'wrong campus denied');
 [$verified,$proof]=otp_api('POST',$base.'/otp/verify',$verify+['code'=>$code]);
 otp_ok($verified===200&&!empty($proof['otp_proof']),'one verified proof');
 [$ct,$ca]=otp_math($base);
 $form=['parent_name'=>'Example Parent','phone'=>'9876543210','grade'=>'Nursery',
    'school_code'=>'campus-1','consent'=>true,'website'=>'','form_display'=>'popup',
    'captcha_token'=>$ct,'captcha_answer'=>$ca,'landing_page'=>'https://lexiconedu.in/lp/'];
 otp_ok(otp_api('POST',$base.'/short-enquiries',$form,'admin','test-otp-noproof')[0]===422,
    'OTP mandatory on popup and inline');
 [$accepted,$lead]=otp_api('POST',$base.'/short-enquiries',
   [...$form,'otp_id'=>$proof['otp_id'],'otp_proof'=>$proof['otp_proof']],
   'admin','test-otp-accepted');
 otp_ok($accepted===201&&isset($lead['guid']),'verified enquiry accepted');
 $saved=$store->transaction(static fn(array $s)=>array_values(array_filter($s['leads'],
    static fn($row)=>($row['guid']??'')===$lead['guid']))[0]);
 otp_ok(!empty($saved['whatsapp_verified'])&&$saved['verification_job_id']===$providerId,'proof recorded');
 otp_ok(otp_api('POST',$base.'/short-enquiries',
   [...$form,'otp_id'=>$proof['otp_id'],'otp_proof'=>$proof['otp_proof']],
   'admin','test-otp-accepted')[1]['duplicate_submission']===true,'duplicate ignored');
 [$ct2,$ca2]=otp_math($base);
 otp_ok(otp_api('POST',$base.'/short-enquiries',[...$form,
   'captcha_token'=>$ct2,'captcha_answer'=>$ca2,
   'otp_id'=>$proof['otp_id'],'otp_proof'=>$proof['otp_proof']],
   'admin','test-otp-replayed')[0]===422,'proof one use');
 otp_ok(otp_api('PATCH',$settings,['enabled'=>false,'short_forms'=>true,
    'session_id'=>'schoolflow-session'])[0]===200,'disable setting');
 otp_ok(otp_api('GET',$base.'/config')[1]['otp']['required']===false,'public policy off');
 echo "PASS: OTP default off, authorized settings, queue, code checks, proof binding, replay and shutdown\n";
}finally{
 putenv('SCHOOLFLOW_WABRIDGE_API_KEY');
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
