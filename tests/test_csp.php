<?php
declare(strict_types=1);
/** CSP regression check for the DigiOps public release, not a report of live browser extensions. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$htmlPath = $root . '/dist/digiops-release/static/index.html';
$phpPath = $root . '/dist/digiops-release/index.php';
if (!is_file($htmlPath) || !is_file($phpPath)) {
    fwrite(STDERR, "Build the release with php tools/build-digiops.php first.\n"); exit(1);
}
$html = file_get_contents($htmlPath);
$gateway = file_get_contents($phpPath);
if (!is_string($html) || !is_string($gateway)) throw new RuntimeException('Unable to read release files');
if (!preg_match_all('~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $scripts, PREG_SET_ORDER)) {
    throw new RuntimeException('Expected an external JavaScript entrypoint');
}
if (count($scripts) !== 1) throw new RuntimeException('Unexpected number of script tags');
foreach ($scripts as $script) {
    if (trim($script[2]) !== '') throw new RuntimeException('Inline script body is not CSP-compliant');
    if (!preg_match('~\bsrc\s*=\s*(["\'])/scrm/static/app\.js\?v=([0-9a-f]{12})\1~i', $script[1], $jsMatch)) {
        throw new RuntimeException('JavaScript must use a content-versioned /scrm/static/app.js URL');
    }
    $jsHash = hash_file('sha256', $root . '/dist/digiops-release/static/app.js');
    if (!is_string($jsHash) || $jsMatch[2] !== substr($jsHash, 0, 12)) throw new RuntimeException('JavaScript cache version is stale');
}
if (preg_match('~\s(?:on[a-z]+)\s*=~i', $html)) throw new RuntimeException('Inline event handler found');
if (!preg_match('~href=(["\'])/scrm/static/style\.css\?v=([0-9a-f]{12})\1~i', $html, $cssMatch)) {
    throw new RuntimeException('Stylesheet must use a content-versioned URL');
}
$cssHash = hash_file('sha256', $root . '/dist/digiops-release/static/style.css');
if (!is_string($cssHash) || $cssMatch[2] !== substr($cssHash, 0, 12)) throw new RuntimeException('Stylesheet cache version is stale');
if (!str_contains($gateway, "script-src \\'self\\' \\'report-sample\\'")) {
    // Generated PHP string escapes its single quotes; the literal source must match exactly.
    throw new RuntimeException('Unexpected CSP in PHP release gateway');
}
if (preg_match('~script-src\s+([^;]+);~', $gateway, $cspPart) && str_contains($cspPart[1], 'unsafe-inline')) {
    throw new RuntimeException('Inline JavaScript must not be enabled');
}
if (!is_file($root . '/dist/digiops-release/static/app.js')) throw new RuntimeException('Missing external JavaScript');
echo "PASS: DigiOps release uses only same-origin external JavaScript, with strict script CSP.\n";