<?php

// Serves an 'uploads/...' file from disk or from the stored_files table.
// uploads/.htaccess routes requests for files missing on disk here, so stored file URLs stay the same.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

$path = ltrim(str_replace('\\', '/', (string)($_GET['path'] ?? '')), '/');
if (!preg_match('#^uploads/[A-Za-z0-9_\-]+/[A-Za-z0-9_.\-]+$#', $path) || strpos($path, '..') !== false) {
    http_response_code(404);
    exit;
}

$content = null;
$diskPath = resolve_upload_disk_path($path);
if ($diskPath !== null) {
    $content = file_get_contents($diskPath);
} else {
    $stored = fetch_stored_file($path);
    $content = $stored['content'] ?? null;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($content === null || $content === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

header('Content-Type: ' . upload_mime_type($path));
header('Content-Length: ' . strlen($content));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header_remove('Pragma');
header_remove('Expires');
echo $content;
