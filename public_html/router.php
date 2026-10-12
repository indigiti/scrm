<?php
// PHP built-in server only: php -S 127.0.0.1:8100 -t public_html public_html/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.$path)) return false;
require __DIR__.'/index.php';