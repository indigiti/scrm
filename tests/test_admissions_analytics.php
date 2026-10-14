<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-family-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);$db=new SchoolFlowStore($dir);
function cc(bool $v,string $why):void{if(!$v)throw new RuntimeException('Release C: '.$why);}
function cr(string $method,string $path,array $data=[],string $auth='admin'):array{
 global $db;
 try{return sf_handle($method,$path,$data,['authorization'=>'Bearer '.$auth],'198.51.100.11',$db);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $db->transaction(function(&$s){
  foreach([1,2] as $id)$s['schools'][]=['id'=>$id,'name'=>'School '.$id];
  $s['users'][]=['id'=>1,'name'=>'Administrator','email'=>'admin@example.invalid','active'=>true];
  $s['users'][]=['id'=>2,'name'=>'Other Admin','email'=>'other@example.invalid','active'=>true];
  $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin'];
  $s['memberships'][]=['user_id'=>2,'school_id'=>2,'role'=>'Admin'];
  foreach(['admin'=>1,'other'=>2] as $token=>$id)$s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
 },true);
 $base='/api/schools/1';
 $form=['student_name'=>'Aarav Example','parent_name'=>'Example Parent','phone'=>'9876543210','grade'=>'Grade 1','consent'=>true,'utm_campaign'=>'admissions'];
 [$code,$a]=cr('POST',$base.'/leads',$form);
 cc($code===201 && !empty($a['family_guid']),'lead owns explicit parent profile');
 [$code,$b]=cr('POST',$base.'/leads',[...$form,'student_name'=>'Diya Example']);
 cc($code===201 && $b['family_guid']!==$a['family_guid'] && empty($b['duplicate_of']),'same phone different child not a duplicate or silently merged');
 [$code,$dup]=cr('POST',$base.'/leads',$form);
 cc($code===201&&$dup['duplicate_of']===$a['id'],'same student/grade/phone flagged as duplicate');
 [$code,$sibling]=cr('POST',$base.'/families/'.$a['id'].'/siblings',['student_name'=>'Kabir Example','grade'=>'Grade 3']);
 cc($code===201 && $sibling['family_guid']===$a['family_guid'],'explicit sibling inherits permanent family GUID');
 $family=cr('GET',$base.'/families')[1];
 $linked=array_values(array_filter($family,fn($v)=>($v['family_guid']??null)===$a['family_guid']));
 cc(count($linked)===1&&$linked[0]['count']===2&&$linked[0]['link_status']==='Explicitly linked','family view uses persistent GUID not phone');
 cc(cr('GET','/api/schools/2/families',[],'other')[1]===[],'family isolation');
 [$code,$changed]=cr('PATCH',$base.'/leads/'.$a['id'],['temperature'=>'Hot','temperature_reason'=>'Parent confirmed next week admission','status'=>'Counselling']);
 cc($code===200 && $changed['temperature']==='Hot','override captured');
 cr('POST',$base.'/leads/'.$a['id'].'/calls',['outcome'=>'Not Interested']);
 $after=cr('GET',$base.'/leads/'.$a['id'])[1]['lead'];
 cc($after['temperature']==='Hot'&&$after['temperature_suggestion']==='Cold','suggestion never overrides manual classification');
 cc(str_contains($after['temperature_suggestion_reason'],'declined'),'explainable recommendation');
 $snapshot=$db->transaction(fn($s)=>$s);
 cc(count($snapshot['stage_history'])>=1 && $snapshot['stage_history'][0]['from_stage']==='New','stage history immutable event');
 cc(count($snapshot['parent_profiles'])===3,'no orphan parent profiles after explicit sibling linking');
 $insights=cr('GET',$base.'/analytics')[1];
 cc(($insights['temperature_counts']['Hot']??0)===1,'report correctly counts hot');
 cc(($insights['by_campaign']['admissions']??0)>=1,'campaign attribution');
 cc(cr('GET',$base.'/analytics',[],'other')[0]===403,'unauthorized analytics blocked');
 echo "PASS: Release C family separation, duplicate precision, explicit siblings, recommendation evidence and stage reports\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
