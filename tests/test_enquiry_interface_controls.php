<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function ui_ok(bool $condition,string $case):void{if(!$condition)throw new RuntimeException('Enquiry interface: '.$case);}
$dir=sys_get_temp_dir().'/sf-enquiry-ui-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function ui_request(string $verb,string $path,array $payload=[],string $token='admin'):array{
 global $store;
 try{return sf_handle($verb,$path,$payload,['authorization'=>'Bearer '.$token],'198.51.100.42',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s):void{
  foreach([1,2] as $sid)$s['schools'][]=['id'=>$sid,'name'=>'School '.$sid];
  foreach([[1,'admin',1,'Admin'],[2,'counsellor',1,'Counsellor'],[3,'outsider',2,'Admin']] as [$uid,$token,$sid,$role]){
   $s['users'][]=['id'=>$uid,'name'=>'Member '.$uid,'email'=>$token.'@example.invalid','active'=>true];
   $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
   $s['memberships'][]=['user_id'=>$uid,'school_id'=>$sid,'role'=>$role,'enabled'=>true];
  }
  $s['counters']['schools']=2;$s['counters']['users']=3;
 },true);
 $url='/api/schools/1/enquiry-panels';
 [$code,$defaults]=ui_request('GET',$url,[],'counsellor');
 ui_ok($code===200&&count($defaults['sections'])===7&&
    $defaults['actions']['call']===true&&$defaults['actions']['api_email']===true&&
    $defaults['tabs']['followup']===true&&$defaults['preferences']['default_tab']==='followup',
    'old schools inherit working communication and tab defaults');
 ui_ok(ui_request('PATCH',$url,['sections'=>sf_enquiry_panel_defaults()],'counsellor')[0]===403,
    'staff cannot change settings');
 ui_ok(ui_request('GET',$url,[],'outsider')[0]===403,'foreign school is forbidden');
 $sections=sf_enquiry_panel_defaults();$actions=sf_enquiry_action_defaults();
 $tabs=sf_enquiry_tab_defaults();$prefs=sf_enquiry_preference_defaults();
 foreach($actions as $key=>$_)$actions[$key]=false;
 $tabs['followup']=false;$tabs['family']=false;$tabs['history']=false;$tabs['more']=false;
 $prefs['default_tab']='followup';$prefs['admin_shortcut']=false;
 ui_ok(ui_request('PATCH',$url,['sections'=>$sections,'actions'=>['call'=>'off']])[0]===422,
   'boolean toggles strictly validated');
 ui_ok(ui_request('PATCH',$url,['sections'=>$sections,'tabs'=>array_fill_keys(array_keys($tabs),false)])[0]===422,
   'cannot disable every workspace tab');
 ui_ok(ui_request('PATCH',$url,['sections'=>$sections,'tabs'=>['unknown'=>true]])[0]===422,
   'unknown drawer tab rejected');
 ui_ok(ui_request('PATCH',$url,['sections'=>$sections,'unknown'=>true])[0]===422,
   'unknown setting rejected');
 [$status,$saved]=ui_request('PATCH',$url,['sections'=>$sections,'actions'=>$actions,
   'tabs'=>$tabs,'preferences'=>$prefs]);
 ui_ok($status===200&&$saved['actions']['whatsapp']===false&&
   $saved['tabs']['admission']===true&&$saved['tabs']['followup']===false&&
   $saved['preferences']['default_tab']==='admission'&&$saved['preferences']['admin_shortcut']===false,
   'school settings saved with safe default-tab fallback');
 $read=ui_request('GET',$url,[],'counsellor')[1];
 ui_ok($read['actions']===$saved['actions']&&$read['tabs']===$saved['tabs'],
    'same-school authorized staff receive updated settings');
 ui_ok(ui_request('GET','/api/schools/2/enquiry-panels',[],'outsider')[1]['actions']['whatsapp']===true,
    'other school settings not changed');
 [$created,$lead]=ui_request('POST','/api/schools/1/leads',[
  'student_name'=>'Example Student','parent_name'=>'Example Parent',
  'phone'=>'9876543210','email'=>'parent@example.org','grade'=>'III',
  'consent'=>true,'assigned_to'=>2
 ]);
 ui_ok($created===201,'test enquiry created');
 foreach(['phone','whatsapp','email'] as $channel){
  $res=ui_request('POST','/api/schools/1/leads/'.$lead['id'].'/contact-action',['channel'=>$channel],'counsellor');
  ui_ok($res[0]===403,'school disables '.$channel.' contact action at API');
 }
 foreach(['email','whatsapp'] as $channel){
  $res=ui_request('POST','/api/schools/1/communications/'.$channel.'/send',[
    'lead_id'=>(int)$lead['id'],'request_id'=>sf_uuid()
  ],'counsellor');
  ui_ok($res[0]===403,'disabled '.$channel.' API send cannot reach provider');
 }
 $sections['attribution']['visible']=false;
 [$status,$legacy]=ui_request('PATCH',$url,['sections'=>$sections]);
 ui_ok($status===200&&$legacy['sections']['attribution']['visible']===false&&
   $legacy['actions']['call']===false&&$legacy['tabs']['admission']===true,
   'legacy section-only updates preserve newer action and tab preferences');
 $actions['call']=true;
 [$status,$on]=ui_request('PATCH',$url,['sections'=>$sections,'actions'=>$actions]);
 ui_ok($status===200&&$on['actions']['call']===true&&$on['actions']['whatsapp']===false,
   'individual call toggle can be enabled without enabling WhatsApp');
 ui_ok(ui_request('POST','/api/schools/1/leads/'.$lead['id'].'/contact-action',
    ['channel'=>'phone'],'counsellor')[0]===200,'allowed call action continues to work');
 ui_ok(ui_request('POST','/api/schools/1/leads/'.$lead['id'].'/contact-action',
    ['channel'=>'whatsapp'],'counsellor')[0]===403,'disabled WhatsApp remains blocked');
 $logs=$store->transaction(static fn(array $s):array=>$s['audit_logs']??[]);
 ui_ok(count(array_filter($logs,static fn(array $row):bool=>($row['action']??'')==='enquiry_panels_updated'))===3,
   'school interface changes audited');
 echo "PASS: per-school communication toggles, secure sends, tab rules, legacy panels and admin isolation\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
