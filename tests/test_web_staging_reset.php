<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function wr_assert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Web staging reset: '.$message);
}
$rootTmp=sys_get_temp_dir().'/scrm-web-reset-test-'.bin2hex(random_bytes(7));
$tmp=$rootTmp.'/private_html/scrm/data';
mkdir($tmp,0700,true);
$store=new SchoolFlowStore($tmp);
$originalHost=$_SERVER['HTTP_HOST']??null;
function wr_call(SchoolFlowStore $store,string $method,string $path,array $body=[],string $token='keeper'):array{
    try{return sf_handle($method,$path,$body,['authorization'=>'Bearer '.$token],'198.51.100.14',$store);}
    catch(SFError $err){return [$err->httpStatus,['detail'=>$err->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void{
        $s['users']=[
            ['id'=>1,'name'=>'Staging Keeper','email'=>'in.stg.adm@example.invalid','active'=>true,'password_hash'=>password_hash('safe-keeper-password-2026',PASSWORD_DEFAULT)],
            ['id'=>2,'name'=>'Dummy User','email'=>'dummy@example.invalid','active'=>true,'password_hash'=>password_hash('dummy-password-2026',PASSWORD_DEFAULT)],
            ['id'=>3,'name'=>'Other Admin','email'=>'other@example.invalid','active'=>true,'password_hash'=>password_hash('other-password-2026',PASSWORD_DEFAULT)]
        ];
        $s['schools']=[['id'=>11,'name'=>'Old Campus'],['id'=>12,'name'=>'Other Campus']];
        $s['memberships']=[['school_id'=>11,'user_id'=>1,'role'=>'Admin'],['school_id'=>11,'user_id'=>2,'role'=>'Counsellor'],['school_id'=>12,'user_id'=>3,'role'=>'Admin']];
        $s['leads']=[['id'=>1,'school_id'=>11,'name'=>'Dummy Pupil'],['id'=>2,'school_id'=>12,'name'=>'Other Dummy']];
        $s['sessions']=[
            ['token_hash'=>sf_hash('keeper'),'user_id'=>1,'expires_at'=>time()+3600],
            ['token_hash'=>sf_hash('other'),'user_id'=>3,'expires_at'=>time()+3600]
        ];
    },true);
    file_put_contents($tmp.'/messaging.key',random_bytes(32));
    file_put_contents($tmp.'/public-form.key',random_bytes(32));
    $_SERVER['HTTP_HOST']='production.example.invalid';
    wr_assert(wr_call($store,'GET','/api/admin/staging-reset')[0]===404,'non-staging hostname denied');
    $_SERVER['HTTP_HOST']='stage.digiti.in';
    wr_assert(wr_call($store,'GET','/api/admin/staging-reset',[],'other')[0]===403,'other admin denied');
    [$code,$preview]=wr_call($store,'GET','/api/admin/staging-reset');
    wr_assert($code===200 && ($preview['keep_email']??'')==='in.stg.adm@example.invalid','keeper preview');
    wr_assert(($preview['counts']['schools']??0)===2 && ($preview['counts']['leads']??0)===2,'preview contains record counts');
    $original=$store->transaction(static fn(array $s)=>$s);
    wr_assert(wr_call($store,'POST','/api/admin/staging-reset',['fingerprint'=>$preview['fingerprint'],'password'=>'safe-keeper-password-2026'])[0]===422,'confirmation enforced');
    $confirm='RESET STAGING KEEP IN.STG.ADM';
    wr_assert(wr_call($store,'POST','/api/admin/staging-reset',['fingerprint'=>$preview['fingerprint'],'confirmation'=>$confirm,'password'=>'bad'])[0]===403,'wrong password denied');
    wr_assert(wr_call($store,'POST','/api/admin/staging-reset',['fingerprint'=>str_repeat('0',64),'confirmation'=>$confirm,'password'=>'safe-keeper-password-2026'])[0]===409,'stale preview denied');
    wr_assert(count($store->transaction(static fn(array $s)=>$s['leads']))===2,'denied attempts do not delete');
    [$status,$result]=wr_call($store,'POST','/api/admin/staging-reset',[
        'fingerprint'=>$preview['fingerprint'],'confirmation'=>$confirm,'password'=>'safe-keeper-password-2026']);
    wr_assert($status===200 && $result['reset_complete']===true,'web reset succeeds');
    $after=$store->transaction(static fn(array $s)=>$s);
    wr_assert(count($after['users'])===1 && $after['users'][0]['email']==='in.stg.adm@example.invalid','only keeper remains');
    wr_assert(password_verify('safe-keeper-password-2026',$after['users'][0]['password_hash']),'original password kept');
    wr_assert(count($after['schools'])===1 && $after['schools'][0]['id']>12,'one new admin school with unused school ID');
    wr_assert(count($after['memberships'])===1 && $after['memberships'][0]['role']==='Admin','admin membership remains');
    wr_assert(count($after['leads'])===0 && count($after['sessions'])===0,'old enquiries and sessions deleted');
    wr_assert(wr_call($store,'GET','/api/admin/staging-reset')[0]===401,'old token revoked');
    $backup=$tmp.'/'.$result['backup_reference'];
    foreach(['store.json','messaging.key','public-form.key'] as $file)
        wr_assert(is_file($backup.'/'.$file),'backup includes '.$file);
    wr_assert(count(json_decode(file_get_contents($backup.'/store.json'),true,512,JSON_THROW_ON_ERROR)['leads'])===2,'prior leads recoverable');
    wr_assert(hash_file('sha256',$backup.'/messaging.key')===hash_file('sha256',$tmp.'/messaging.key'),'messaging key preserved in backup');
    [$loginStatus,$login]=wr_call($store,'POST','/api/auth/login',[
        'email'=>'in.stg.adm@example.invalid','password'=>'safe-keeper-password-2026']);
    wr_assert($loginStatus===200 && !empty($login['token']),'admin can reauthenticate');
    [$meStatus,$me]=wr_call($store,'GET','/api/me',[],$login['token']);
    wr_assert($meStatus===200 && count($me['schools'])===1,'fresh workspace visible');
    echo "PASS: web staging reset: hostname, keeper identity, password, fingerprint, backup, revoked tokens and re-login\n";
}finally{
    if($originalHost===null)unset($_SERVER['HTTP_HOST']);else $_SERVER['HTTP_HOST']=$originalHost;
    foreach(glob($tmp.'/backup-before-web-reset-*/*')?:[] as $f)@unlink($f);
    foreach(glob($tmp.'/backup-before-web-reset-*')?:[] as $dir)@rmdir($dir);
    foreach(glob($tmp.'/*')?:[] as $f)if(is_file($f))@unlink($f);
    @rmdir($tmp);
    @rmdir(dirname($tmp));
    @rmdir(dirname(dirname($tmp)));
    @rmdir($rootTmp);
}
