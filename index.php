<?php
declare(strict_types=1);

$html = (string) file_get_contents(__DIR__ . '/index.html');
$script = (string) file_get_contents(__DIR__ . '/app.js');
$html = str_replace(
    '<script src="./app-loader.php?v=08015a9"></script>',
    '<script>' . $script . '</script>',
    $html
);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $html;
