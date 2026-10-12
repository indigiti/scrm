<?php
// Browser-only setup: GitHub identity proof, non-owner refusal, rate limiting and first-admin lockout.
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_private/api.php';
$dir = sys_get_temp_dir() . '/sf-github-bootstrap-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$store = new SchoolFlowStore($dir);
function ws_assert(bool $result, string $message): void {if (!$result) throw new RuntimeException('FAIL: ' . $message);}
function ws_call(SchoolFlowStore $s, string $path, array $payload=[], ?callable $verifier=null): array {
    try {return sf_handle('POST',$path,$payload,[],'198.51.100.22',$s,$verifier);}
    catch (SFError $e) {return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
    $token = 'mock-owner-identity-proof-00000000000000000000';
    $data = ['github_token'=>$token,'name'=>'Browser Owner','school_name'=>'Demo School',
        'email'=>'admin@example.test','password'=>'very-secure-test-pass123','confirm_password'=>'very-secure-test-pass123'];
    ws_assert(ws_call($store,'/api/setup/admin',$data, fn() => false)[0]===403,'Reject different GitHub identity');
    ws_assert(ws_call($store,'/api/setup/admin',array_merge($data,['password'=>'small','confirm_password'=>'small']),fn() => true)[0]===422,'Reject short password');
    $verifiedToken='';
    [$status,$created] = ws_call($store,'/api/setup/admin',$data,function($provided)use(&$verifiedToken){$verifiedToken=$provided;return true;});
    ws_assert($status===201 && strlen($created['token'])===80,'GitHub owner creates admin session');
    ws_assert($verifiedToken===$token,'Only supplied proof is checked');
    $state = $store->transaction(fn($s)=>$s);
    $dbtext = file_get_contents($dir.'/store.json');
    ws_assert(!str_contains($dbtext,$token)&&!isset($state['github_token']),'GitHub token never persists');
    ws_assert($state['users'][0]['email']==='admin@example.test','New admin created');
    ws_assert(ws_call($store,'/api/setup/admin',$data,fn()=>true)[0]===409,'No second administrator bootstrap');
    ws_assert(sf_handle('GET','/api/setup/status',[],[],'198.51.100.22',$store)[1]['needs_setup']===false,'Setup form hidden afterward');
    echo "PASS: Web-only GitHub owner verification, private token, admin setup and lockout\n";
} finally {
    foreach(glob($dir.'/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}