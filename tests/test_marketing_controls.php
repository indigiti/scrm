<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function marketing_ok(bool $condition,string $name):void{if(!$condition)throw new RuntimeException('Marketing release: '.$name);}
$dir=sys_get_temp_dir().'/sf-marketing-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function marketing_api(string $verb,string $path,array $payload=[],string $token='admin',string $origin='https://school.test'):array{
 global $store;
 try{return sf_handle($verb,$path,$payload,['authorization'=>'Bearer '.$token,'origin'=>$origin],'198.51.100.35',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s):void{
  foreach([1,2] as $id)$s['schools'][]=['id'=>$id,'name'=>'School '.$id];
  $s['counters']['schools']=2;
  foreach([[1,'admin','Admin',1],[2,'counsellor','Counsellor',1],[3,'outsider','Admin',2]] as [$id,$token,$role,$sid]){
   $s['users'][]=['id'=>$id,'name'=>$token,'email'=>$token.'@example.test','active'=>true];
   $s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
   $s['memberships'][]=['user_id'=>$id,'school_id'=>$sid,'role'=>$role,'enabled'=>true];
  }
  $s['counters']['users']=3;
 },true);
 [$status,$group]=marketing_api('POST','/api/group-sites',['name'=>'School website','domain'=>'school.test','school_ids'=>[1]]);
 marketing_ok($status===201,'admin created approved form group');
 $id=$group['id'];$guid=$group['guid'];
 $path='/api/group-sites/'.$id.'/submission-settings';
 marketing_ok(marketing_api('GET',$path,[],'counsellor')[0]===403,'counsellor blocked from conversion settings');
 marketing_ok(marketing_api('PATCH',$path,['thank_you_urls'=>['https://evil.test/thanks']])[0]===422,'unapproved redirect origin rejected');
 marketing_ok(marketing_api('PATCH',$path,['thank_you_urls'=>['http://school.test/thanks']])[0]===422,'http redirect rejected');
 marketing_ok(marketing_api('PATCH',$path,['thank_you_urls'=>['https://school.test/thanks?email=secret']])[0]===422,'query based redirect rejected');
 marketing_ok(marketing_api('PATCH',$path,['thank_you_urls'=>['https://school.test/thanks','https://school.test/done']])[0]===422,'one redirect per origin');
 marketing_ok(marketing_api('PATCH',$path,['inline_redirect'=>'yes'])[0]===422,'invalid boolean rejected');
 [$savedStatus,$saved]=marketing_api('PATCH',$path,[
  'thank_you_urls'=>['https://school.test/thanks/'],
  'inline_redirect'=>true,'popup_redirect'=>false,'detailed_redirect'=>false]);
 marketing_ok($savedStatus===200&&$saved['thank_you_urls']===['https://school.test/thanks'],
   'admin conversion settings persisted');
 [$cfgCode,$cfg]=marketing_api('GET','/api/public/forms/'.$guid.'/config',[],'admin');
 marketing_ok($cfgCode===200&&($cfg['submission_settings']['thank_you_url']??'')==='https://school.test/thanks',
  'public form returns same-origin approved thank-you page');
 marketing_ok(!($cfg['submission_settings']['popup_redirect']??true),'popup keeps inline confirmation by default');
 // Legacy clients may submit stale redirect toggles, but those flags must
 // never reenable popup navigation or appear enabled in the admin dashboard.
 [$legacyStatus,$normalized]=marketing_api('PATCH',$path,[
  'thank_you_urls'=>['https://school.test/thanks'],
  'inline_redirect'=>true,'popup_redirect'=>true,'detailed_redirect'=>true
 ]);
 marketing_ok($legacyStatus===200&&$normalized['popup_redirect']===false&&
  $normalized['detailed_redirect']===false,'legacy redirect flags cannot enable popup navigation');
 marketing_ok(marketing_api('GET',$path)[1]['popup_redirect']===false,
  'admin settings also normalize previously saved redirect flags');


 marketing_ok(marketing_api('PATCH','/api/schools/1/enquiry-panels',[],'counsellor')[0]===403,
  'counsellor cannot change school UI settings');
 [$defaultStatus,$defaults]=marketing_api('GET','/api/schools/1/enquiry-panels',[],'counsellor');
 marketing_ok($defaultStatus===200 && $defaults['sections']['audit']['visible']===true,
  'audit starts visible but collapsed for authorized staff');
 $sections=sf_enquiry_panel_defaults();
 $sections['family']=['visible'=>false,'expanded'=>false];
 $sections['audit']=['visible'=>true,'expanded'=>false];
 marketing_ok(marketing_api('PATCH','/api/schools/1/enquiry-panels',['sections'=>['arbitrary'=>['visible'=>true,'expanded'=>true]]])[0]===422,
  'reject unknown UI panel keys');
 [$updatedCode,$updated]=marketing_api('PATCH','/api/schools/1/enquiry-panels',['sections'=>$sections]);
 marketing_ok($updatedCode===200&&$updated['sections']['family']['visible']===false,'school admin saved panel choices');
 marketing_ok(marketing_api('GET','/api/schools/1/enquiry-panels',[],'counsellor')[1]['sections']['audit']['expanded']===false,
  'school audit preference applies to authorized staff');

 $reported=sf_marketing_first_touch(['first_touch_data'=>[
  'landing_page'=>'https://school.test/admissions','referrer'=>'https://google.com/',
  'utm_source'=>'google','utm_medium'=>'cpc','utm_campaign'=>'november','utm_term'=>'nursery','utm_content'=>'ad-a']]);
 marketing_ok($reported!==null&&$reported['utm_campaign']==='november','first-touch campaign parsed');
 try{sf_marketing_first_touch(['first_touch_data'=>['unsafe_key'=>'x']]);throw new RuntimeException('Unknown first touch accepted');}
 catch(SFError $e){marketing_ok($e->httpStatus===422,'invalid first-touch input rejected');}

 $store->transaction(static function(array &$s):void{
  for($i=1;$i<=2004;$i++){
   $s['leads'][]=[
    'id'=>$i,'school_id'=>1,'assigned_to'=>$i<=3?2:1,
    'created_at'=>'2026-11-01T08:00:00+00:00',
    'status'=>$i%2===0?'Enrolled':'New',
    'temperature'=>$i%3===0?'Hot':'Unrated',
    'utm_source'=>$i<=3?'meta':'google','utm_medium'=>'cpc',
    'utm_campaign'=>$i<=3?'facebook-winter':'nursery-november',
    'utm_term'=>'nursery','utm_content'=>'hero-a','form_type'=>'inline',
    'landing_page_version'=>'v2','duplicate_of'=>($i===4?2:null)
   ];
  }
  $s['leads'][]=['id'=>2005,'school_id'=>2,'assigned_to'=>3,
    'created_at'=>'2026-11-01T08:00:00+00:00','status'=>'Enrolled','utm_source'=>'secret','utm_campaign'=>'other'];
 },true);
 [$reportCode,$report]=marketing_api('GET','/api/schools/1/campaign-report');
 marketing_ok($reportCode===200&&$report['totals']['enquiries']===2004,
    'report aggregates all records outside 2k inbox limit');
 marketing_ok($report['totals']['enrolled']===1002,'enrolment aggregate accurate');
 marketing_ok($report['totals']['potential_duplicates']===1,'potential duplicate count accurate');
 marketing_ok(array_sum(array_column($report['by']['utm_campaign'],'enquiries'))===2004,
   'campaign aggregation accounts for all school leads');
 [$filterCode,$filter]=marketing_api('GET','/api/schools/1/campaign-report?utm_source=meta');
 marketing_ok($filterCode===200&&$filter['totals']['enquiries']===3,
   'source filter works on server');
 marketing_ok(marketing_api('GET','/api/schools/1/campaign-report?from=2030-01-01&to=2030-02-01')[1]['totals']['enquiries']===0,
   'date range enforced server-side');
 marketing_ok(marketing_api('GET','/api/schools/1/campaign-report?from=2026-13-01')[0]===422,
   'invalid date rejected');
 marketing_ok(marketing_api('GET','/api/schools/2/campaign-report',[],'admin')[0]===403,
   'cross-school campaign report blocked');
 marketing_ok(marketing_api('GET','/api/schools/1/campaign-report',[],'counsellor')[1]['totals']['enquiries']===3,
   'counsellor aggregate restricted to assigned enquiries');
 marketing_ok(marketing_api('GET','/api/schools/1/leads/1/audit',[],'counsellor')[0]===200,
   'assigned counselor can access only their assigned lead audit');

 $logs=$store->transaction(static fn(array $s):array=>$s['audit_logs']??[]);
 marketing_ok(count(array_filter($logs,static fn(array $e):bool=>in_array($e['action']??'',[
  'enquiry_panels_updated','submission_settings_updated'],true)))===3,
  'admin changes are audited without disabling logging');
 echo "PASS: conversion redirects, same-origin policy, UTM metadata validation, full-history reporting, staff privacy and admin drawer settings\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
