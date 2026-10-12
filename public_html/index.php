<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_private/api.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; connect-src \'self\'; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = '/scrm';
if (!str_starts_with($requestPath, $basePath)) sf_json(['detail'=>'Not found'],404);
$path = substr($requestPath, strlen($basePath)) ?: '/';
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
    try {$payload = json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){sf_json(['detail'=>'Malformed JSON'],400);}
    if (!is_array($payload) || array_is_list($payload)) sf_json(['detail'=>'JSON object required'],422);
}
$headers=[];
foreach (['Authorization'=>'authorization','Idempotency-Key'=>'idempotency-key'] as $header=>$name) {
    $value=$_SERVER['HTTP_'.strtoupper(str_replace('-','_',$header))]??'';
    if (!$value && $header==='Authorization') $value=$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $headers[$name]=$value;
}
try {
    $store=new SchoolFlowStore();
    [$status,$data]=sf_handle($_SERVER['REQUEST_METHOD']??'GET',$path, $payload,$headers, $_SERVER['REMOTE_ADDR']??'unknown',$store);
    sf_json($data,$status);
} catch (SFError $e) {sf_json(['detail'=>$e->getMessage()],$e->httpStatus);}
catch(Throwable $e){error_log('SchoolFlow internal error (message redacted)');sf_json(['detail'=>'Server error; check private storage permissions'],500);}