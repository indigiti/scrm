<?php
declare(strict_types=1);
/** Build the DigiOps artifact: public files only, with PHP runtime code bundled in the gateway.
 * Private student/guardian JSON state is ALWAYS stored under private_html/scrm/data.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$output = $root . '/dist/digiops-release';
if (!is_dir($output . '/static') && !mkdir($output . '/static', 0755, true)) throw new RuntimeException('Cannot make output directory');
function phpBody(string $file): string {
    $s = file_get_contents($file);
    if ($s === false) throw new RuntimeException("Missing source: $file");
    $s = preg_replace('/^<\?php\s*(?:declare\(strict_types=1\);\s*)?/D', '', $s, 1);
    if (!is_string($s)) throw new RuntimeException('Cannot process PHP source');
    return $s;
}
$store = phpBody($root . '/app_private/store.php');
$api = phpBody($root . '/app_private/api.php');
$messaging = phpBody($root . '/app_private/messaging.php');
$api = preg_replace('~^require_once __DIR__ \. \'/store\.php\';\s*~m', '', $api, 1);
$api = preg_replace('~^require_once __DIR__ \. \'/messaging\.php\';\s*~m', '', $api, 1);
$gateway = phpBody($root . '/public_html/index.php');
$gateway = preg_replace('~^require_once dirname\(__DIR__\) \. \'/app_private/api\.php\';\s*~m', '', $gateway, 1);
$gateway = str_replace("__DIR__ . '/static/index.html'", "__DIR__ . '/static/index.html'", $gateway);

$cli = <<<'CLI'
if (PHP_SAPI === 'cli') {
    // The browser cannot create an admin without a secret printed privately over Cloudways SSH.
    if (($argv[1] ?? '') === 'setup-code' && $argc === 2) {
        try {
            $code = bin2hex(random_bytes(16));
            (new SchoolFlowStore())->transaction(function (&$db) use ($code): void {
                if (!empty($db['users'])) throw new RuntimeException('SchoolFlow is already initialized');
                $db['setup_code_hash'] = sf_hash($code);
            }, true);
            echo "One-time SchoolFlow setup code (keep private): $code\n";
            echo "Enter it in the Create First Admin form at /scrm/.\n";
            exit(0);
        } catch (Throwable $e) {fwrite(STDERR, "Setup code error: ".$e->getMessage()."\n");exit(1);}
    }
    // Legacy trusted SSH bootstrap is still available for a headless installation.
    if (($argv[1] ?? '') !== 'create-admin' || $argc !== 5) {
        fwrite(STDERR, "Usage: php index.php setup-code  OR  php index.php create-admin admin@example.com 'Admin Name' 'School Name'\n");
        exit(2);
    }
    $email=sf_email($argv[2]);$name=trim($argv[3]);$school=trim($argv[4]);
    $password=getenv('SCHOOLFLOW_ADMIN_PASSWORD');
    if ($password===false || strlen($password)<12 || strlen($password)>1024) {
        fwrite(STDERR,"Set SCHOOLFLOW_ADMIN_PASSWORD to a password of 12+ characters.\n");exit(2);
    }
    if (strlen($name)<2 || strlen($school)<3) {fwrite(STDERR,"Name or school name is too short.\n");exit(2);}
    try {
        (new SchoolFlowStore())->transaction(function (&$db) use ($email,$name,$school,$password) {
            if ($db['users']) throw new RuntimeException('Administrator already exists; cannot bootstrap twice');
            $user=['id'=>sf_id($db,'users'),'name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
            $schoolRow=['id'=>sf_id($db,'schools'),'name'=>$school,'created_at'=>sf_time()];
            $db['users'][]=$user;$db['schools'][]=$schoolRow;
            $db['memberships'][]=['school_id'=>$schoolRow['id'],'user_id'=>$user['id'],'role'=>'Admin'];
        },true);
        echo "SchoolFlow administrator created successfully.\n";
        exit(0);
    } catch (Throwable $e) {fwrite(STDERR,"Setup failed: ".$e->getMessage()."\n");exit(1);}
}
CLI;
$built = "<?php\ndeclare(strict_types=1);\n" .
    "// Generated DigiOps payload. Editable PHP source resides in /app_private.\n" .
    "define('SCHOOLFLOW_DEFAULT_DATA_DIR', dirname(__DIR__, 2) . '/private_html/scrm/data');\n\n" .
    $store . "\n" . $messaging . "\n" . $api . "\n" . $cli . "\n" . $gateway;
if (file_put_contents($output . '/index.php', $built) === false) throw new RuntimeException('Cannot write release gateway');
foreach (['.htaccess','static/app.js','static/index.html','static/style.css'] as $asset) {
    if (!copy($root . '/public_html/' . $asset,$output . '/' . $asset)) throw new RuntimeException('Failed to package '.$asset);
}
// The public URL remains stable (/scrm/), but JavaScript/CSS must be versioned.
// This prevents a previous CRM UI from remaining cached after a DigiOps deploy.
$htmlPath = $output . '/static/index.html';
$html = file_get_contents($htmlPath);
if (!is_string($html)) throw new RuntimeException('Cannot read packaged HTML');
foreach (['static/app.js', 'static/style.css'] as $asset) {
    $hash = hash_file('sha256', $output . '/' . $asset);
    if (!is_string($hash)) throw new RuntimeException('Cannot hash asset: ' . $asset);
    $unversioned = '/scrm/' . $asset . '"';
    if (substr_count($html, $unversioned) !== 1) throw new RuntimeException('Expected a single asset reference: ' . $asset);
    $versioned = '/scrm/' . $asset . '?v=' . substr($hash, 0, 12) . '"';
    $html = str_replace($unversioned, $versioned, $html);
}
if (file_put_contents($htmlPath, $html) === false) throw new RuntimeException('Cannot write versioned HTML');
$exposedFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file) $exposedFiles[] = str_replace($output.'/','',$file->getPathname());
sort($exposedFiles);
if ($exposedFiles !== ['.htaccess','index.php','static/app.js','static/index.html','static/style.css']) throw new RuntimeException('Unexpected public payload contents: '.implode(', ',$exposedFiles));
echo "DigiOps public-only payload: ".implode(', ',$exposedFiles)."\n";
