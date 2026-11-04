<?php
declare(strict_types=1);

/** Admin-configured conversion destinations: one exact HTTPS URL for each approved origin. */
function sf_submission_defaults():array{
 return ['inline_redirect'=>true,'popup_redirect'=>false,'detailed_redirect'=>false,'thank_you_urls'=>[]];
}
function sf_submission_settings(array $group):array{
 $saved=$group['submission_settings']??[];
 if(!is_array($saved))$saved=[];
 return array_replace(sf_submission_defaults(),array_intersect_key($saved,sf_submission_defaults()));
}
function sf_submission_validate(array $p,array $group):array{
 $defaults=sf_submission_defaults();
 foreach(array_keys($p) as $key)if(!array_key_exists($key,$defaults))sf_fail(422,'Unknown submission setting');
 $settings=array_replace($defaults,$p);
 foreach(['inline_redirect','popup_redirect','detailed_redirect'] as $key)
  if(!is_bool($settings[$key]))sf_fail(422,'Redirect settings must be boolean');
 if(!is_array($settings['thank_you_urls'])||!array_is_list($settings['thank_you_urls'])||count($settings['thank_you_urls'])>25)
  sf_fail(422,'Set at most 25 thank-you page URLs');
 $urls=[];$origins=[];
 foreach($settings['thank_you_urls'] as $value){
  if(!is_string($value))sf_fail(422,'Enter a complete thank-you page URL');
  $url=sf_landing_normalize_url($value);
  $origin=sf_landing_origin($url);
  if(!sf_landing_origin_approved($group,$origin))
   sf_fail(422,'Thank-you URL must belong to an approved form website origin');
  if(isset($origins[$origin]))sf_fail(422,'Only one thank-you URL is allowed per website origin');
  $origins[$origin]=true;$urls[]=$url;
 }
 $settings['thank_you_urls']=$urls;
 // Product contract: thank-you page conversions belong to inline LP forms only.
 // Retain legacy fields for stored settings but never enable popup navigation.
 $settings['popup_redirect']=false;
 $settings['detailed_redirect']=false;
 return $settings;
}
function sf_submission_public(array $group,string $origin):array{
 $settings=sf_submission_settings($group);
 $url='';
 foreach($settings['thank_you_urls'] as $candidate)
  if(sf_landing_origin((string)$candidate)===$origin){$url=$candidate;break;}
 return ['thank_you_url'=>$url,'inline_redirect'=>$settings['inline_redirect'],
  'popup_redirect'=>false,'detailed_redirect'=>false];
}
/** Never use browser-supplied touch metadata for routing, authorization or staff identity. */
function sf_marketing_first_touch(array $payload):?array{
 $value=$payload['first_touch_data']??null;
 if($value===null)return null;
 if(!is_array($value)||array_is_list($value))sf_fail(422,'Invalid campaign touch metadata');
 $keys=['landing_page','referrer','utm_source','utm_medium','utm_campaign','utm_term','utm_content'];
 foreach(array_keys($value) as $key)if(!in_array($key,$keys,true))sf_fail(422,'Unknown campaign touch metadata');
 $out=[];
 foreach($keys as $key){
  $raw=$value[$key]??'';
  if(!is_string($raw)||strlen($raw)>($key==='landing_page'||$key==='referrer'?500:140))
    sf_fail(422,'Invalid campaign touch value');
  $out[$key]=trim($raw);
 }
 return $out;
}
/** Return bounded, school-authorized aggregates over ALL historical enquiries, not the inbox's 2,000-row window. */
function sf_campaign_report(array $s,int $sid,int $uid,string $role,string $uri):array{
 $query=parse_url($uri,PHP_URL_QUERY);parse_str(is_string($query)?$query:'',$raw);
 $allowed=['from','to','utm_source','utm_medium','utm_campaign','utm_term','utm_content','form_type','status'];
 foreach(array_keys($raw) as $key)if(!in_array($key,$allowed,true))sf_fail(422,'Unsupported campaign report filter');
 $filter=[];
 foreach($allowed as $key){
  $val=$raw[$key]??'';
  if(!is_string($val)||strlen($val)>140)sf_fail(422,'Invalid campaign report filter');
  $filter[$key]=trim($val);
 }
 foreach(['from','to'] as $key)if($filter[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$filter[$key])
    ||!checkdate((int)substr($filter[$key],5,2),(int)substr($filter[$key],8,2),(int)substr($filter[$key],0,4))))
   sf_fail(422,'Invalid campaign report date');
 if($filter['from']!==''&&$filter['to']!==''&&$filter['from']>$filter['to'])
   sf_fail(422,'Start date cannot exceed end date');
 $fields=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','form_type','status'];
 $rows=[];$totals=['enquiries'=>0,'potential_duplicates'=>0,'applied_or_later'=>0,'confirmed_or_enrolled'=>0,'enrolled'=>0,'hot_or_warm'=>0];
 $grouped=[];
 foreach(sf_visible_leads($s,$sid,$uid,$role) as $lead){
  $date=substr((string)($lead['created_at']??''),0,10);
  if(($filter['from']!==''&&$date<$filter['from'])||($filter['to']!==''&&$date>$filter['to']))continue;
  $ok=true;
  foreach($fields as $field)if($filter[$field]!==''&&strcasecmp((string)($lead[$field]??''),$filter[$field])!==0){$ok=false;break;}
  if(!$ok)continue;
  $applied=in_array((string)($lead['status']??''),['Applied','Application Started','Application Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled'],true);
  $confirmed=in_array((string)($lead['status']??''),['Admission Confirmed','Enrolled'],true);
  $enrolled=(string)($lead['status']??'')==='Enrolled';
  $warm=in_array((string)($lead['temperature']??''),['Hot','Warm'],true);
  $duplicate=!empty($lead['duplicate_of']);
  $totals['enquiries']++;$totals['potential_duplicates']+=(int)$duplicate;
  $totals['applied_or_later']+=(int)$applied;$totals['confirmed_or_enrolled']+=(int)$confirmed;
  $totals['enrolled']+=(int)$enrolled;$totals['hot_or_warm']+=(int)$warm;
  foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','form_type','landing_page_version'] as $field){
   $label=trim((string)($lead[$field]??''))?:'Unattributed';
   if(strlen($label)>140)$label=substr($label,0,140);
   $key=$field."\0".$label;
   if(!isset($grouped[$key]))$grouped[$key]=['dimension'=>$field,'name'=>$label,'enquiries'=>0,'applied'=>0,'confirmed'=>0,'enrolled'=>0];
   $grouped[$key]['enquiries']++;$grouped[$key]['applied']+=(int)$applied;
   $grouped[$key]['confirmed']+=(int)$confirmed;$grouped[$key]['enrolled']+=(int)$enrolled;
  }
 }
 $result=array_values($grouped);
 usort($result,static function(array $a,array $b):int{
  return ($a['dimension']<=>$b['dimension'])?:($b['enquiries']<=>$a['enquiries'])?:strcmp($a['name'],$b['name']);
 });
 $by=[];foreach($result as $row)if(count($by[$row['dimension']]??[])<100)$by[$row['dimension']][]=$row;
 return ['school_id'=>$sid,'filters'=>$filter,'totals'=>$totals,'by'=>$by,
  'as_of'=>sf_time(),'definitions'=>[
   'applied'=>'Current status at application or later; not verified historic event counts',
   'confirmed'=>'Current status Admission Confirmed or Enrolled',
   'hot_or_warm'=>'Lead temperature classifications; not verified qualified conversions',
   'potential_duplicates'=>'Rows flagged as potential duplicates, not a deduplicated unique-family count'
  ]];
}
/** Per-school enquiry workspace preferences; existing section settings migrate in place. */
function sf_enquiry_panel_defaults():array{
 return [
  'attribution'=>['visible'=>true,'expanded'=>false],
  'communication'=>['visible'=>true,'expanded'=>true],
  'family'=>['visible'=>true,'expanded'=>false],
  'application'=>['visible'=>true,'expanded'=>false],
  'documents'=>['visible'=>true,'expanded'=>false],
  'consent'=>['visible'=>true,'expanded'=>false],
  'audit'=>['visible'=>true,'expanded'=>false],
 ];
}
function sf_enquiry_action_defaults():array{
 return ['call'=>true,'whatsapp'=>true,'email'=>true,'api_email'=>true,'api_whatsapp'=>true];
}
function sf_enquiry_tab_defaults():array{
 return ['followup'=>true,'admission'=>true,'family'=>true,'history'=>true,'more'=>true];
}
function sf_enquiry_preference_defaults():array{
 return ['default_tab'=>'followup','remember_tab'=>true,'admin_shortcut'=>true];
}
function sf_enquiry_panel_settings(array $s,int $sid,string $role):array{
 $defaults=sf_enquiry_panel_defaults();
 $actions=sf_enquiry_action_defaults();
 $tabs=sf_enquiry_tab_defaults();
 $prefs=sf_enquiry_preference_defaults();
 $saved=$s['enquiry_panels'][(string)$sid]??[];
 // Version 1 stored the seven sections flat. The new format stores
 // sections/actions/tabs/preferences; both are readable without migration.
 $oldSections=is_array($saved)?($saved['sections']??$saved):[];
 if(is_array($oldSections))foreach($defaults as $key=>$default){
  if(isset($oldSections[$key])&&is_array($oldSections[$key]))
   foreach(['visible','expanded'] as $prop)
    if(is_bool($oldSections[$key][$prop]??null))$defaults[$key][$prop]=$oldSections[$key][$prop];
 }
 if(is_array($saved))foreach(['actions','tabs','preferences'] as $key){
  $stored=$saved[$key]??null;
  if(!is_array($stored))continue;
  $target=$key==='actions'?$actions:($key==='tabs'?$tabs:$prefs);
  foreach($target as $prop=>$default){
   $candidate=$stored[$prop]??null;
   if($prop==='default_tab'){
    if(is_string($candidate)&&array_key_exists($candidate,$tabs))$target[$prop]=$candidate;
   }elseif(is_bool($candidate))$target[$prop]=$candidate;
  }
  if($key==='actions')$actions=$target;
  elseif($key==='tabs')$tabs=$target;
  else $prefs=$target;
 }
 if(!in_array(true,$tabs,true))$tabs['followup']=true;
 if(!$tabs[$prefs['default_tab']])
  $prefs['default_tab']=array_key_first(array_filter($tabs));
 return ['sections'=>$defaults,'actions'=>$actions,'tabs'=>$tabs,'preferences'=>$prefs,
   'audit_access'=>'assigned_lead'];
}
function sf_enquiry_panel_validate(array $payload):array{
 // Section-only updates remain accepted for older clients. In that case
 // preserve actions, tabs and preferences in the API handler.
 foreach(array_keys($payload) as $key)
  if(!in_array($key,['sections','actions','tabs','preferences'],true))
   sf_fail(422,'Unknown enquiry interface setting');
 if(!isset($payload['sections'])||!is_array($payload['sections'])||array_is_list($payload['sections']))
  sf_fail(422,'Provide enquiry panel section settings');
 $defaults=sf_enquiry_panel_defaults();$submitted=$payload['sections'];
 if(array_keys($submitted)!==array_keys($defaults))sf_fail(422,'Provide exactly the supported enquiry sections');
 $result=[];
 foreach($defaults as $key=>$props){
  $value=$submitted[$key];
  if(!is_array($value)||array_keys($value)!==['visible','expanded']||
     !is_bool($value['visible'])||!is_bool($value['expanded']))
   sf_fail(422,'Invalid enquiry section setting: '.$key);
  $result[$key]=$value;
 }
 $out=['sections'=>$result];
 foreach(['actions'=>sf_enquiry_action_defaults(),'tabs'=>sf_enquiry_tab_defaults()] as $name=>$expected){
  if(!array_key_exists($name,$payload))continue;
  $given=$payload[$name];
  if(!is_array($given)||count($given)!==count($expected)||
     array_diff(array_keys($given),array_keys($expected))||
     array_diff(array_keys($expected),array_keys($given)))
    sf_fail(422,'Invalid '.$name.' settings');
  foreach($expected as $key=>$val)
   if(!is_bool($given[$key]))sf_fail(422,'Invalid '.$name.' setting: '.$key);
  // Save in stable canonical order regardless of how the client serialized keys.
  $given=array_replace($expected,$given);
  if($name==='tabs'&&!in_array(true,$given,true))sf_fail(422,'At least one enquiry workspace tab must remain enabled');
  $out[$name]=$given;
 }
 if(array_key_exists('preferences',$payload)){
  $given=$payload['preferences'];$expected=sf_enquiry_preference_defaults();
  if(!is_array($given)||array_keys($given)!==array_keys($expected)||
    !is_string($given['default_tab'])||
    !array_key_exists($given['default_tab'],sf_enquiry_tab_defaults())||
    !is_bool($given['remember_tab'])||!is_bool($given['admin_shortcut']))
    sf_fail(422,'Invalid enquiry workspace preferences');
  $out['preferences']=$given;
 }
 return $out;
}
function sf_enquiry_action_allowed(array $s,int $sid,string $key):bool{
 $actions=sf_enquiry_panel_settings($s,$sid,'Admin')['actions'];
 return isset($actions[$key])&&$actions[$key]===true;
}
