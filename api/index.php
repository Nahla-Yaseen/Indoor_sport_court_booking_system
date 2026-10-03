<?php
/**
 * api/index.php — Vercel PHP Router
 * Routes all requests to the appropriate PHP file in the project root.
 */

// Get the requested path
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove leading slash
$filePath = ltrim($path, '/');

// Default to index.php if root is requested
if (empty($filePath) || $filePath === '/') {
    $filePath = 'index.php';
}

// If no file extension, try adding .php
if (!pathinfo($filePath, PATHINFO_EXTENSION)) {
    $filePath .= '.php';
}

// Build full path relative to project root (one level up from api/)
$rootDir = dirname(__DIR__);
$fullPath = $rootDir . '/' . $filePath;

// If file doesn't exist, return 404
if (!file_exists($fullPath)) {
    http_response_code(404);
    echo '<h1>404 - Page Not Found</h1>';
    exit;
}

// Change working directory to the target file's directory
// This ensures all relative require/include paths work correctly
chdir(dirname($fullPath));

// Include the target PHP file
include $fullPath;
