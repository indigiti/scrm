<?php
declare(strict_types=1);
/** End-to-end regression test against the *packaged* DigiOps PHP gateway.
 * In particular, POST {} must reach the demo seeder, while [] must be rejected.
 */
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/app_private/api.php';
$root = dirname(__DIR__);
$gateway = $root . '/dist/digiops-release/index.php';
if (!is_file($gateway)) throw new RuntimeException('Build DigiOps payload first');
$tmp = sys_get_temp_dir() . '/schoolflow-http-json-' . bin2hex(random_bytes(6));
if (!mkdir($tmp . '/data', 0700, true)) throw new RuntimeException('Test data directory failed');
$proc = null;
$pipeHandles = [];
function ht_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('HTTP JSON test failed: ' . $message);
}
function ht_req(int $port, string $method, string $path, ?string $body = null, string $token = ''): array {
    $headers = ["Accept: application/json"];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 2,
    ]]);
    $result = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    if ($result === false) return [0, null];
    $status = (int) (preg_match('/^HTTP\/\d\.\d (\d{3})/', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    $data = json_decode($result, true);
    return [$status, $data];
}
try {
    // Populate isolated testing data with an authenticated admin (not a real account).
    $store = new SchoolFlowStore($tmp . '/data');
    $store->transaction(static function (&$db): void {
        $db['users'][] = [
            'id' => 1, 'name' => 'JSON Test Admin', 'email' => 'json-test@example.invalid',
            'password_hash' => password_hash('only-for-automated-tests', PASSWORD_DEFAULT),
            'active' => true, 'created_at' => sf_time(),
        ];
        $db['schools'][] = ['id' => 1, 'name' => 'Test School', 'created_at' => sf_time()];
        $db['memberships'][] = ['school_id' => 1, 'user_id' => 1, 'role' => 'Admin'];
        $db['sessions'][] = ['token_hash' => sf_hash('json-test-token'), 'user_id' => 1, 'expires_at' => time() + 1800];
    }, true);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not allocate test port: '.$error);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $router = $tmp . '/router.php';
    file_put_contents($router, "<?php\nrequire " . var_export($gateway, true) . ";\n");
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname($gateway), $router];
    $proc = proc_open($command, [0 => ['pipe','r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipeHandles, $root,
        array_merge($_ENV, ['SCHOOLFLOW_DATA_DIR' => $tmp . '/data']));
    if (!is_resource($proc)) throw new RuntimeException('Could not start bundled HTTP test server');
    if (isset($pipeHandles[0]) && is_resource($pipeHandles[0])) fclose($pipeHandles[0]);
    $running = false;
    for ($i = 0; $i < 40; $i++) {
        if (ht_req($port, 'GET', '/scrm/health')[0] === 200) {$running = true; break;}
        usleep(100000);
    }
    ht_assert($running, 'test HTTP server started');
    $endpoint = '/scrm/api/schools/1/demo-data/seed';
    [$status, $result] = ht_req($port, 'POST', $endpoint, '[]', 'json-test-token');
    ht_assert($status === 422 && $result['detail'] === 'JSON object required', 'reject JSON array []');
    [$status, $result] = ht_req($port, 'POST', $endpoint, 'null', 'json-test-token');
    ht_assert($status === 422 && $result['detail'] === 'JSON object required', 'reject JSON null');
    [$status, $result] = ht_req($port, 'POST', $endpoint, '{', 'json-test-token');
    ht_assert($status === 400 && $result['detail'] === 'Malformed JSON', 'reject malformed JSON');
    [$status, $result] = ht_req($port, 'POST', $endpoint, '{ }', 'json-test-token');
    ht_assert($status === 201 && $result['leads'] === 18 && $result['sites'] === 3, 'accept empty JSON object { } and seed data');
    [$status, $result] = ht_req($port, 'GET', '/scrm/api/schools/1/demo-data', null, 'json-test-token');
    ht_assert($status === 200 && $result['leads'] === 18, 'persist and read sample data');
    [$status, $result] = ht_req($port, 'POST', '/scrm/api/schools/1/demo-data/clear', '{"confirm":"CLEAR DEMO"}', 'json-test-token');
    ht_assert($status === 200 && $result['removed_leads'] === 18, 'allow legitimate nonempty JSON object');
    [$status, $result] = ht_req($port, 'POST', $endpoint, '{}', 'json-test-token');
    ht_assert($status === 201 && $result['leads'] === 18, 'accept compact empty object {} after clear');
    echo "PASS: DigiOps HTTP accepts {}, rejects [], and demo seed/clear works\n";
} finally {
    if (is_resource($proc)) {proc_terminate($proc);proc_close($proc);}
    foreach (glob($tmp . '/data/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/data');
    @unlink($tmp . '/router.php');
    @unlink($tmp . '/server.log');
    @rmdir($tmp);
}