<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bin/reset-staging.php';
function reset_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('staging reset: '.$message);}
$dir=sys_get_temp_dir().'/scrm-reset-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
try{
    $store=new SchoolFlowStore($dir);
    $store->transaction(static function(array &$s){
        $s['users']=[
            ['id'=>1,'email'=>'in.stg.adm@example.invalid','name'=>'Keep','active'=>true,'password_hash'=>'preserve-hash'],
            ['id'=>2,'email'=>'delete@example.invalid','name'=>'Delete','active'=>true,'password_hash'=>'delete-hash']];
        $s['schools']=[['id'=>1,'name'=>'Old Campus'],['id'=>2,'name'=>'Dummy']];
        $s['memberships']=[['school_id'=>1,'user_id'=>1,'role'=>'Admin'],['school_id'=>2,'user_id'=>2,'role'=>'Admin']];
        $s['leads']=[['id'=>10,'school_id'=>1,'name'=>'Dummy 1'],['id'=>11,'school_id'=>2,'name'=>'Dummy 2']];
        $s['sessions']=[['token_hash'=>sf_hash('old'),'user_id'=>1,'expires_at'=>time()+3600]];
        $s['counters']=['users'=>2,'schools'=>2,'leads'=>11];
    },true);
    file_put_contents($dir.'/messaging.key',random_bytes(32));
    file_put_contents($dir.'/public-form.key',random_bytes(32));
    $before=$store->transaction(static fn(array $s)=>$s);
    $preview=sf_reset_preview($before,'in.stg.adm');
    reset_assert($preview['keep_id']===1,'keeps correct admin');
    try{sf_reset_preview($before,'none');throw new RuntimeException('Missing account accepted');}
    catch(RuntimeException $e){reset_assert(str_contains($e->getMessage(),'exactly one'),'unknown account fails closed');}
    try{sf_reset_run($store,$dir,'in.stg.adm',str_repeat('0',64));throw new RuntimeException('Old fingerprint accepted');}
    catch(RuntimeException $e){reset_assert(str_contains($e->getMessage(),'changed since preview'),'stale preview fails closed');}
    $r=sf_reset_run($store,$dir,'in.stg.adm',$preview['fingerprint']);
    $after=$store->transaction(static fn(array $s)=>$s);
    reset_assert(count($after['users'])===1&&$after['users'][0]['password_hash']==='preserve-hash','preserves only intended credentials');
    reset_assert(count($after['schools'])===1&&count($after['memberships'])===1,'fresh admin school');
    reset_assert($after['memberships'][0]['role']==='Admin','admin role persists');
    reset_assert(count($after['leads'])===0&&count($after['sessions'])===0,'dummy leads and sessions cleared');
    reset_assert($after['schools'][0]['id']>2,'does not reuse campus ID');
    foreach(['store.json','public-form.key','messaging.key'] as $file){
        reset_assert(is_file($r['backup_directory'].'/'.$file),'backup '.$file);
        if($file==='store.json'){
            $original=json_decode(file_get_contents($r['backup_directory'].'/'.$file),true,512,JSON_THROW_ON_ERROR);
            reset_assert(count($original['leads'])===2,'original records recoverable');
        }else{
            reset_assert(hash_file('sha256',$r['backup_directory'].'/'.$file)===hash_file('sha256',$dir.'/'.$file),'backup key hash');
        }
    }
    echo "PASS: staging reset, admin hash, school membership, backup and preview safety\n";
}finally{
    foreach(glob($dir.'/backup-before-stage-reset-*/*')?:[] as $file)@unlink($file);
    foreach(glob($dir.'/backup-before-stage-reset-*')?:[] as $path)@rmdir($path);
    foreach(glob($dir.'/*')?:[] as $file)if(is_file($file))@unlink($file);
    @rmdir($dir);
}
