<?php
declare(strict_types=1);

$projectRoot = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $projectRoot . '/api/index.php';
    return true;
}

if (preg_match('~(^|/)(?:\.env|\.git)(?:/|$)|\.sql$~i', $path)) {
    http_response_code(404);
    return true;
}

if ($path === '/' || $path === '/index.php') {
    require $projectRoot . '/index.php';
    return true;
}

$file = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($path, '/'));
if ($file !== false && str_starts_with($file, $projectRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'No encontrado';
return true;
