<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-detailed-campaign-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function dcheck(bool $ok,string $note):void {if(!$ok)throw new RuntimeException('Detailed school popup: '.$note);}
function dreq(string $method,string $path,array $data=[],string $idem='',string $origin='https://school.test'):array{
 global $store;
 try{return sf_handle($method,$path,$data,
  ['origin'=>$origin,'idempotency-key'=>$idem],'198.51.100.17',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
$guid='71be8d09-2fc1-4df5-a6dd-c2e87a1e5668';
$campaign='f47ac10a-5a49-4d2e-a5d2-58d254e0901f';
try{
 $store->transaction(static function(array &$s)use($guid,$campaign):void{
  $s['schools'][]=['id'=>1,'name'=>'Lexicon Kalyani Nagar'];
  $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
  $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin'];
  $s['sites'][]=['id'=>1,'school_id'=>1,'name'=>'School Website','domain'=>'school.test',
   'enabled'=>true,'platform'=>'WordPress'];
  $s['group_sites'][]=[
   'id'=>1,'guid'=>$guid,'name'=>'School form','owner_id'=>1,'domain'=>'school.test',
   'enabled'=>true,'linked_school_site_id'=>1,
   'form_surface_settings'=>['inline_enabled'=>false,'group_popup_enabled'=>false,'detailed_popup_enabled'=>false],
   'routes'=>[['code'=>'campus-1','school_id'=>1,'site_id'=>1]],
   'page_popup_campaigns'=>[['guid'=>$campaign,'name'=>'Admission Enquiry',
    'scope'=>'website','landing_page'=>'https://school.test/',
    'settings'=>['enabled'=>true,'trigger'=>'manual','school_ids'=>[1]]]]
  ];
 },true);
 [$ok,$cfg]=dreq('GET','/api/public/forms/'.$guid.'/config');
 dcheck($ok===200&&count($cfg['page_popup_campaigns'])===1,'active campaign published');
 [$ok,$challenge]=dreq('GET','/api/public/forms/'.$guid.'/challenge');
 dcheck($ok===200&&preg_match('/(\d+) \+ (\d+)/',$challenge['question'],$m)===1,'math question generated');
 $form=['student_name'=>'Sample Student','parent_name'=>'Sample Parent','email'=>'parent@example.invalid',
  'phone'=>'9876543210','pincode'=>'411014','state'=>'Maharashtra','city'=>'Pune',
  'grade'=>'I','school_code'=>'campus-1','consent'=>true,'captcha_token'=>$challenge['token'],
  'captcha_answer'=>(string)((int)$m[1]+(int)$m[2]),'website'=>'',
  'landing_page'=>'https://school.test/admissions/',
  'popup_campaign_guid'=>$campaign,'form_display'=>'popup','popup_trigger'=>'manual',
  'referrer'=>'','utm_source'=>'','utm_medium'=>'','utm_campaign'=>'',
  'utm_content'=>'','utm_term'=>''];
 [$status,$lead]=dreq('POST','/api/public/forms/'.$guid.'/enquiries',$form,'detailed-campaign-001');
 dcheck($status===201,'campaign-specific original detailed form submits while legacy surface disabled: HTTP '.$status.' '.json_encode($lead));
 $snapshot=$store->transaction(static fn(array $s):array=>$s);
 dcheck(count($snapshot['leads'])===1,'detailed form created exactly one school lead');
 dcheck(($snapshot['leads'][0]['school_id']??null)===1,'correct school routed');
 $serialized=json_encode($snapshot['leads'][0]);
 dcheck(str_contains($serialized,$campaign),'lead retains campaign GUID attribution');
 [$status,$duplicate]=dreq('POST','/api/public/forms/'.$guid.'/enquiries',$form,'detailed-campaign-001');
 dcheck($status===200&&!empty($duplicate['duplicate_submission']),'duplicate POST idempotency');
 $bad=$form;$bad['landing_page']='https://unapproved.test/admissions/';
 dcheck(dreq('POST','/api/public/forms/'.$guid.'/enquiries',$bad,'detailed-campaign-002')[0]===403,
  'campaign cannot submit for unrelated origin or page');
 $bad=$form;$bad['popup_campaign_guid']='0a386b29-3444-4c5e-9cd8-b2c8c0608ec3';
 dcheck(dreq('POST','/api/public/forms/'.$guid.'/enquiries',$bad,'detailed-campaign-003')[0]===403,
  'unknown campaign rejected');
 $store->transaction(static function(array &$s):void{
  $s['group_sites'][0]['page_popup_campaigns'][0]['settings']['enabled']=false;
 },true);
 dcheck(dreq('POST','/api/public/forms/'.$guid.'/enquiries',$form,'detailed-campaign-004')[0]===403,
  'disabled campaign cannot accept detailed form submission');
 $store->transaction(static function(array &$s):void{
  $s['group_sites'][0]['page_popup_campaigns'][0]['settings']['enabled']=true;
  $s['group_sites'][0]['form_otp_settings']=['enabled'=>true,'short_forms'=>true,
    'session_id'=>'fake-test-session','api_key_sealed'=>'fake-test-sealed-value'];
 },true);
 [$ok,$next]=dreq('GET','/api/public/forms/'.$guid.'/challenge');
 preg_match('/(\d+) \+ (\d+)/',$next['question'],$n);
 $otpMissing=$form;$otpMissing['captcha_token']=$next['token'];
 $otpMissing['captcha_answer']=(string)((int)$n[1]+(int)$n[2]);
 dcheck(dreq('POST','/api/public/forms/'.$guid.'/enquiries',$otpMissing,'detailed-campaign-005')[0]===422,
  'WhatsApp OTP policy cannot be bypassed using original detailed popup');
 echo "PASS: original detailed popup accepts school campaign, enforces scope, captcha, idempotency, OTP and attribution\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $f)@unlink($f);
 @rmdir($dir);
}
