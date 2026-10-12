<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_private/api.php';
$dir = sys_get_temp_dir() . '/schoolflow-bootstrap-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$store = new SchoolFlowStore($dir);
function verify(bool $yes, string $message): void { if (!$yes) throw new RuntimeException('FAIL: ' . $message); }
function callSetup(SchoolFlowStore $store, string $method, string $path, array $data = []): array {
    try {return sf_handle($method, $path, $data, [], '127.0.0.1', $store);}
    catch (SFError $e) {return [$e->httpStatus, ['detail'=>$e->getMessage()]];}
}
try {
    [$status, $data] = callSetup($store, 'GET', '/api/setup/status');
    verify($status === 200 && $data['needs_setup'] && !$data['code_ready'], 'first-run detection');
    [$status] = callSetup($store, 'POST', '/api/setup/admin', ['setup_code'=>'bogus1234567890']);
    verify($status === 403, 'missing issued code denies bootstrap');
    $code = bin2hex(random_bytes(16));
    $store->transaction(function (&$s) use ($code) {$s['setup_code_hash'] = sf_hash($code);}, true);
    [, $data] = callSetup($store, 'GET', '/api/setup/status');
    verify($data['needs_setup'] && $data['code_ready'] && !str_contains(json_encode($data),$code), 'code availability without leakage');
    $payload = ['setup_code'=>$code,'name'=>'First Administrator','email'=>'first@example.test',
        'school_name'=>'School One','password'=>'example-long-password-2026','confirm_password'=>'example-long-password-2026'];
    verify(callSetup($store,'POST','/api/setup/admin',array_merge($payload,['setup_code'=>'not-the-real-code']))[0] === 403, 'incorrect code denied');
    verify(callSetup($store,'POST','/api/setup/admin',array_merge($payload,['confirm_password'=>'does-not-match']))[0] === 422, 'password mismatch denied');
    verify(callSetup($store,'POST','/api/setup/admin',array_merge($payload,['password'=>'short','confirm_password'=>'short']))[0] === 422, 'short password denied');
    [$status, $created] = callSetup($store,'POST','/api/setup/admin',$payload);
    verify($status === 201 && strlen($created['token']) === 80, 'bootstrap creates authenticated session');
    [$status, $me] = sf_handle('GET','/api/me',[],['authorization'=>'Bearer '.$created['token']], '127.0.0.1',$store);
    verify($status === 200 && $me['user']['email'] === $payload['email'] && $me['schools'][0]['role'] === 'Admin', 'new admin has school workspace');
    [$status, $data] = callSetup($store, 'GET', '/api/setup/status');
    verify($status === 200 && !$data['needs_setup'] && !$data['code_ready'], 'form disappears after setup');
    verify(callSetup($store,'POST','/api/setup/admin',$payload)[0] === 409, 'code cannot be reused');
    $state = $store->transaction(fn($s) => $s);
    verify(!array_key_exists('setup_code_hash',$state),'setup code consumed');
    verify(!str_contains(file_get_contents($dir.'/store.json'),$code),'no raw setup code stored');
    echo "PASS: first-admin setup, code gating, session, single use and signup lockout\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}