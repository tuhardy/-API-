<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
Auth::requireAccess();

$database = new Database(config('database_path'));
$record = $database->find((string) ($_GET['id'] ?? ''));
$partIndex = filter_var($_GET['part'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

if (!$record || $record['channel'] !== 'mms_receive' || $partIndex === false || $partIndex === null) {
    http_response_code(404);
    echo '附件不存在。';
    exit;
}
$bodyPath = recordFilePath($record, 'body_path');
$requestPath = recordFilePath($record, 'request_path');
$body = $bodyPath ? file_get_contents($bodyPath) : '';
$metadata = $requestPath ? json_decode((string) file_get_contents($requestPath), true) : [];
$contentType = is_array($metadata) ? (string) ($metadata['content_type'] ?? '') : '';

try {
    $parts = Parser::extractMmsParts($body, $contentType);
} catch (Throwable) {
    $parts = [];
}
if (!isset($parts[$partIndex])) {
    http_response_code(404);
    echo '附件不存在。';
    exit;
}

$part = $parts[$partIndex];
$mime = strtolower(strtok($part['content_type'], ';') ?: 'application/octet-stream');
$download = isset($_GET['download']);
$inline = !$download && in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true);
$safeMime = preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mime) ? $mime : 'application/octet-stream';
$filename = $part['filename'] ?: 'attachment-' . ($partIndex + 1);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'attachment.bin';

header('Content-Type: ' . ($inline ? $safeMime : 'application/octet-stream'));
header('Content-Length: ' . strlen($part['body']));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src "none"; sandbox');
header('Cache-Control: private, no-store');
echo $part['body'];
