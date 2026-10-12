<?php
declare(strict_types=1);
/** Regression check: generated DigiOps bundle includes fixed drawer and responsive workspace styles. */
$root=dirname(__DIR__);
$css=file_get_contents($root.'/dist/digiops-release/static/style.css');
if (!is_string($css)) throw new RuntimeException('DigiOps CSS not built');
foreach (['.drawer-overlay', '.drawer-overlay .drawer', '.drawer-head', '.app', '.content', '@media(max-width:650px)'] as $selector) {
    if (!str_contains($css, $selector)) throw new RuntimeException('Missing layout CSS: '.$selector);
}
if (!preg_match('/\.drawer-overlay\s*\{[^}]*position\s*:\s*fixed\s*;/s', $css)) throw new RuntimeException('Drawer backdrop must be fixed');
if (!preg_match('/\.app\s*\{[^}]*max-width\s*:\s*none\s*;/s', $css)) throw new RuntimeException('Workspace must not be capped on desktop');
if (!preg_match('/\.drawer-overlay\s+\.drawer\s*\{[^}]*overflow-y\s*:\s*auto\s*;/s', $css)) throw new RuntimeException('Drawer needs independent scroll');
$js=file_get_contents($root.'/dist/digiops-release/static/app.js');
if (!is_string($js) || !str_contains($js, 'class="drawer-overlay"') || !str_contains($js, 'class="drawer-head"')) throw new RuntimeException('Drawer markup does not match CSS selectors');
echo "PASS: full-width workspace and fixed scrollable detail drawer\n";