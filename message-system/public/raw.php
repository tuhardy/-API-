<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
Auth::requireAccess();

$database = new Database(config('database_path'));
$record = $database->find((string) ($_GET['id'] ?? ''));
$file = (string) ($_GET['file'] ?? 'body');
$key = $file === 'request' ? 'request_path' : 'body_path';

if (!$record || !in_array($file, ['body', 'request'], true)) {
    http_response_code(404);
    echo '文件不存在。';
    exit;
}
$path = recordFilePath($record, $key);
if (!$path) {
    http_response_code(404);
    echo '原始文件不可用。';
    exit;
}

$filename = $record['id'] . ($file === 'request' ? '-request.json' : '-body.bin');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
