<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
Auth::requireAccess();

$channels = channelDefinitions();
$activeChannel = normalizeChannel($_GET['channel'] ?? null);
$definition = $channels[$activeChannel];
$database = new Database(config('database_path'));
$csrfToken = Auth::csrfToken();
$deletionResult = $_SESSION['deletion_result'] ?? null;
unset($_SESSION['deletion_result']);

$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'from_epoch' => null,
    'to_epoch' => null,
];
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$timezone = new DateTimeZone(config('timezone'));
try {
    if ($from !== '') {
        $filters['from_epoch'] = (new DateTimeImmutable($from, $timezone))->getTimestamp();
    }
    if ($to !== '') {
        $end = new DateTimeImmutable($to, $timezone);
        if (strlen($to) === 16) {
            $end = $end->setTime((int) $end->format('H'), (int) $end->format('i'), 59);
        }
        $filters['to_epoch'] = $end->getTimestamp();
    }
} catch (Throwable) {
    // 无效日期不进入查询，用户可修改后重新筛选。
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $database->search($activeChannel, $filters, $page, (int) config('per_page'));
$latest = $database->latest($activeChannel);
$counts = $database->counts($activeChannel);
$pageTitle = $definition['label'] . ' · 原始数据';

require __DIR__ . '/../app/views/header.php';
require __DIR__ . '/../app/views/list.php';
require __DIR__ . '/../app/views/footer.php';
