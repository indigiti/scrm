<?php
declare(strict_types=1);
// Emits an actual PHP-built public embed bootstrap for a browser-model test.
require_once dirname(__DIR__,2).'/app_private/api.php';
$variant=(string)($argv[1]??'linked-one');
if(!in_array($variant,['linked-one','linked-off','linked-conflict','linked-specific','legacy'],true))exit(2);
$dir=sys_get_temp_dir().'/sf-bootstrap-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
$guid='71be8d09-2fc1-4df5-a6dd-c2e87a1e5668';
$campaign='f47ac10a-5a49-4d2e-a5d2-58d254e0901f';
try{
 $store->transaction(static function(array &$s)use($variant,$guid,$campaign):void{
  $s['schools'][]=['id'=>1,'name'=>'School'];
  $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
  $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Admin'];
  $s['sites'][]=['id'=>1,'school_id'=>1,'name'=>'School Website','domain'=>'school.test',
   'enabled'=>true,'platform'=>'WordPress'];
  $campaigns=[['guid'=>$campaign,'scope'=>$variant==='linked-specific'?'page':'website',
   'landing_page'=>$variant==='linked-specific'?'https://school.test/admissions':'https://school.test/',
   'settings'=>['enabled'=>$variant!=='linked-off','trigger'=>'manual','school_ids'=>[1]]]];
  if($variant==='linked-conflict')$campaigns[]=['guid'=>'0a386b29-3444-4c5e-9cd8-b2c8c0608ec3',
    'scope'=>'page','landing_page'=>'https://school.test/admissions',
    'settings'=>['enabled'=>true,'school_ids'=>[1]]];
  $s['group_sites'][]=['id'=>1,'guid'=>$guid,'owner_id'=>1,'name'=>'School form',
   'domain'=>'school.test','enabled'=>true,
   'linked_school_site_id'=>$variant==='legacy'?0:1,
   'routes'=>[['code'=>'campus-1','school_id'=>1,'site_id'=>1]],
   'page_popup_campaigns'=>$campaigns];
 },true);
 echo sf_public_embed_script($store,$guid,dirname(__DIR__,2).'/public_html');
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
