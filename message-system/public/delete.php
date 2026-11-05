<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
Auth::requireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo '批量删除必须使用 POST 请求。';
    exit;
}

if (!Auth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '页面验证已失效，请返回原始数据列表刷新后重试。';
    exit;
}

$channel = (string) ($_POST['channel'] ?? '');
$ids = $_POST['ids'] ?? [];
if (!isset(channelDefinitions()[$channel]) || !is_array($ids)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '删除请求无效，请返回原始数据列表重新选择。';
    exit;
}

if ($ids === []) {
    $result = ['deleted' => 0, 'failed' => 0, 'errors' => ['请先选择要删除的原始记录。']];
} else {
    try {
        $result = (new Deletion(new Database(config('database_path'))))->delete($ids, $channel);
    } catch (InvalidArgumentException $exception) {
        $result = ['deleted' => 0, 'failed' => count($ids), 'errors' => [$exception->getMessage()]];
    }
}

$_SESSION['deletion_result'] = $result;
session_write_close();
http_response_code(303);
header('Location: ' . queryUrl('index.php', ['channel' => $channel]));
exit;
