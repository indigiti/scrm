<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_private/api.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'report-sample\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; connect-src \'self\'; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = '/scrm';
if (!str_starts_with($requestPath, $basePath)) sf_json(['detail'=>'Not found'],404);
$path = substr($requestPath, strlen($basePath)) ?: '/';
if (str_starts_with($path,'/api/public/forms/')) {
    try {
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if (!sf_public_allowed_origin_path(new SchoolFlowStore(),$path,$origin))
            sf_json(['detail'=>'Website origin not authorized'],403);
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Idempotency-Key');
        header('Access-Control-Max-Age: 300');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD']??'')==='OPTIONS') sf_json(['ok'=>true]);
    } catch(Throwable $e) { sf_json(['detail'=>'Public form unavailable'],503); }
}

if ($path !== '/' && !str_starts_with($path, '/')) sf_json(['detail'=>'Not found'],404);
if ($path === '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/static/index.html');
    exit;
}
if (!str_starts_with($path,'/api/') && $path !== '/health') sf_json(['detail'=>'Not found'],404);
if (strlen($_SERVER['CONTENT_LENGTH']??'')>10 && (int)($_SERVER['CONTENT_LENGTH']??0)>32768) sf_json(['detail'=>'Request too large'],413);
$raw=file_get_contents('php://input');
if (strlen($raw)>32768) sf_json(['detail'=>'Request too large'],413);
$payload=[];
if (($raw??'')!=='') {
    try {
        // Decode once as a JSON object to distinguish {} from []. With associative
        // decoding both become an empty PHP array and array_is_list([]) is true.
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) sf_json(['detail'=>'JSON object required'],422);
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        sf_json(['detail'=>'Malformed JSON'],400);
    }
}
$headers=[];
foreach (['Authorization'=>'authorization','Idempotency-Key'=>'idempotency-key','Origin'=>'origin'] as $header=>$name) {
    $value=$_SERVER['HTTP_'.strtoupper(str_replace('-','_',$header))]??'';
    if (!$value && $header==='Authorization') $value=$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $headers[$name]=$value;
}
try {
    $store=new SchoolFlowStore();
    [$status,$data]=sf_handle($_SERVER['REQUEST_METHOD']??'GET',$path . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']!=='' ? '?'.$_SERVER['QUERY_STRING'] : ''), $payload,$headers, $_SERVER['REMOTE_ADDR']??'unknown',$store);
    if($status===200 && is_string($data) && preg_match('#^/api/schools/(\d+|[0-9a-f-]{36})/exports/admissions\.xlsx$#iD',$path)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="schoolflow-admissions-'.gmdate('Ymd').'.xlsx"');
        header('Content-Length: '.strlen($data));
        http_response_code(200);
        echo $data;
        exit;
    }
    sf_json($data,$status);
} catch (SFError $e) {sf_json(['detail'=>$e->getMessage()],$e->httpStatus);}
catch(Throwable $e){error_log('SchoolFlow internal error (message redacted)');sf_json(['detail'=>'Server error; check private storage permissions'],500);}