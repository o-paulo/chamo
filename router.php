<?php
declare(strict_types=1);

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('#^/(?:storage|scripts|vendor|tests|backups|banco_de_dados)(?:/|$)#i', $requestPath)
    || preg_match('#(?:^|/)\.#', $requestPath)
    || (strtolower(pathinfo($requestPath, PATHINFO_EXTENSION)) === 'php' && strtolower($requestPath) !== '/api.php')) {
    http_response_code(404);
    exit;
}

if ($requestPath === '/') {
    header('Location: /TelaLogin.html');
    exit;
}

$file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($requestPath, '/'));
$root = realpath(__DIR__);
$resolved = realpath($file);
if ($resolved !== false && $root !== false
    && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
    && is_file($resolved)) {
    return false;
}

http_response_code(404);
echo 'Not Found';
