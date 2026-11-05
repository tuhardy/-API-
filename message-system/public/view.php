<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
Auth::requireAccess();

$channels = channelDefinitions();
$database = new Database(config('database_path'));
$id = (string) ($_GET['id'] ?? '');
$record = $database->find($id);

if (!$record) {
    http_response_code(404);
    $activeChannel = 'sms_forward';
    $pageTitle = '原始记录不存在';
    require __DIR__ . '/../app/views/header.php';
    echo '<section class="empty-state"><h1>找不到这条原始记录</h1><p>记录可能不属于当前数据库，请返回原始数据列表重新选择。</p><a class="button" href="index.php">返回短信接收</a></section>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$activeChannel = $record['channel'];
$definition = $channels[$activeChannel];
$pageTitle = $definition['label'] . ' · 解析原始记录';
$bodyPath = recordFilePath($record, 'body_path');
$requestPath = recordFilePath($record, 'request_path');
$body = $bodyPath ? file_get_contents($bodyPath) : false;
$requestJson = $requestPath ? file_get_contents($requestPath) : false;
$metadata = is_string($requestJson) ? json_decode($requestJson, true) : [];
if (!is_array($metadata)) {
    $metadata = [];
}
if (is_string($requestJson)) {
    $decodedRequest = json_decode($requestJson, true);
    $requestJson = json_encode(
        $decodedRequest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: $requestJson;
} else {
    $requestJson = json_encode([
        'id' => $record['id'],
        'channel' => $record['channel'],
        'received_at_utc' => $record['received_at'],
        'method' => $record['method'],
        'uri' => $record['uri'],
        'remote_addr' => $record['remote_addr'],
        'content_type' => $record['content_type'],
        'error_message' => $record['error_message'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if ($record['storage_status'] === 'saved' && is_string($body)) {
    $parsed = (new Parser())->parse($activeChannel, $body, $metadata);
} else {
    $parsed = [
        'status' => 'error',
        'message' => $record['error_message'] ?: '原始文件不可用，无法解析。',
        'fields' => [],
        'tables' => [],
        'texts' => [],
        'attachments' => [],
    ];
}

$rawIsText = is_string($body) && isUtf8Text($body);
$rawDisplay = '';
$rawTruncated = false;
$hexPreview = null;
if (is_string($body) && $rawIsText) {
    $rawDisplay = substr($body, 0, (int) config('raw_display_limit'));
    $rawTruncated = strlen($body) > strlen($rawDisplay);
    while ($rawDisplay !== '' && preg_match('//u', $rawDisplay) !== 1) {
        $rawDisplay = substr($rawDisplay, 0, -1);
    }
} elseif (is_string($body) && $body !== '') {
    $hexPreview = hexDump($body);
}

require __DIR__ . '/../app/views/header.php';
require __DIR__ . '/../app/views/detail.php';
require __DIR__ . '/../app/views/footer.php';
