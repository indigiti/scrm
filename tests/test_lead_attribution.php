<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function la(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException('Attribution: '.$reason);}
$dir=sys_get_temp_dir().'/sf-attribution-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
try{
 $store->transaction(static function(array &$s):void{
  $s['schools'][]=['id'=>1,'name'=>'Campus One','created_at'=>sf_time()];
  $s['sites'][]=['id'=>1,'school_id'=>1,'name'=>'Group Campus Route','domain'=>'lexicon.digiti.in','is_group_route'=>true,'enabled'=>1];
  $s['sites'][]=['id'=>2,'school_id'=>1,'name'=>'Individual School Website','domain'=>'school.example','enabled'=>1];
  $s['counters']['schools']=1;$s['counters']['sites']=2;
 },true);
 $rows=$store->transaction(static function(array &$s):array{
  $first=sf_create_lead($s,1,['student_name'=>'','parent_name'=>'Parent Example','phone'=>'9876543210',
   'grade'=>'Nursery','email'=>'','form_display'=>'inline','landing_page'=>'https://lexicon.digiti.in/ver/demo/v2/index.html',
   'utm_source'=>'google','utm_campaign'=>'lexicon-nursery','source_type'=>'individual_website',
   'consent'=>true],1,null,null,'public_group_short_received',true);
  $second=sf_create_lead($s,1,['student_name'=>'','parent_name'=>'Parent Example','phone'=>'9876543210',
   'grade'=>'Nursery','email'=>'','form_display'=>'popup','landing_page'=>'https://lexicon.digiti.in/ver/demo/v3/index.html',
   'utm_source'=>'facebook','utm_campaign'=>'lexicon-tour','consent'=>true],1,null,null,'public_group_short_received',true);
  $individual=sf_create_lead($s,1,['student_name'=>'School Student','parent_name'=>'Other Parent','phone'=>'9876543211',
   'grade'=>'II','landing_page'=>'https://school.example/admission','consent'=>true],2,null,null,'website_received');
  $manual=sf_create_lead($s,1,['student_name'=>'Manual Student','parent_name'=>'Manual Parent','phone'=>'9876543212',
   'grade'=>'IV','consent'=>true],null,null,null,'manual_created');
  return [$first,$second,$individual,$manual];
 },true);
 [$first,$second,$individual,$manual]=$rows;
 la($first['source_type']==='group_website' && $first['form_type']==='inline' && $first['form_variant']==='short','group inline route trusted');
 la($first['landing_page_version']==='v2' && $second['landing_page_version']==='v3','page versions identified by path');
 la($second['duplicate_of']===$first['id'],'cross-session same-lead duplicate flagged without merging');
 la($second['first_touch']['utm_source']==='google' && $second['first_touch']['utm_campaign']==='lexicon-nursery','original touch inherited');
 la($second['latest_touch']['utm_source']==='facebook' && $second['latest_touch']['form_type']==='popup','later acquisition recorded on new lead');
 la($first['latest_touch']['utm_source']==='google','previous lead remains immutable');
 la($individual['source_type']==='individual_website' && $individual['form_type']==='api','single-campus API categorized');
 la($manual['source_type']==='manual' && $manual['form_type']==='manual','manual intake categorized');
 $legacy=['source_type'=>'', 'lead_channel'=>'chatbot','utm_source'=>'public_admissions_chat'];
 la(sf_lead_origin($legacy,null)==='chatbot','historical chatbot inferred');
 la(sf_lead_page_version('https://example.com/enquire')==='' && sf_lead_page_version('not-a-url')==='','no made-up page versions');
 echo "PASS: source classification, v2/v3, first/latest touch, duplication, backwards compatibility\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);
}
