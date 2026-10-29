<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function health_assert(bool $yes,string $msg):void{if(!$yes)throw new RuntimeException('ready: '.$msg);}
$dir=sys_get_temp_dir().'/scrm-ready-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
try{
    $store=new SchoolFlowStore($dir);
    $empty=sf_handle('GET','/health/ready',[],[],'127.0.0.1',$store);
    health_assert($empty[0]===503&&$empty[1]['ok']===false,'uninitialized system is not ready');
    $store->transaction(static function(array &$s){
        $s['users']=[['id'=>1,'active'=>true,'email'=>'admin@example.invalid']];
        $s['schools']=[['id'=>1,'name'=>'Test']];
        $s['memberships']=[['school_id'=>1,'user_id'=>1,'role'=>'Admin']];
    },true);
    $healthy=sf_handle('GET','/health/ready',[],[],'127.0.0.1',$store);
    health_assert($healthy[0]===200&&$healthy[1]['ok']===true,'active admin and school ready');
    health_assert(!isset($healthy[1]['users'])&&!isset($healthy[1]['schools']),'no PII or counts exposed');
    $store->transaction(static function(array &$s){$s['users'][0]['active']=false;},true);
    $disabled=sf_handle('GET','/health/ready',[],[],'127.0.0.1',$store);
    health_assert($disabled[0]===503,'disabled admin not ready');
    echo "PASS: v2.4 privacy-safe health/readiness endpoint\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $f)@unlink($f);
    @rmdir($dir);
}
