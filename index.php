<?php
declare(strict_types=1);

$html = (string) file_get_contents(__DIR__ . '/index.html');
$script = (string) file_get_contents(__DIR__ . '/app.js');
$html = str_replace(
    '<script src="./app-loader.php?v=08015a9"></script>',
    '<script>' . $script . '</script>',
    $html
);
// Cada CSS/JS estático lleva la fecha de modificación en la URL: al cambiar el archivo,
// el navegador (sobre todo Safari en iPhone, que cachea varios días) pide la versión nueva.
$html = (string) preg_replace_callback(
    '~(href|src)="\./([^"?]+\.(?:css|js))(?:\?[^"]*)?"~',
    static function (array $match): string {
        $version = (int) @filemtime(__DIR__ . '/' . $match[2]);
        return $match[1] . '="./' . $match[2] . '?v=' . $version . '"';
    },
    $html
);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $html;
