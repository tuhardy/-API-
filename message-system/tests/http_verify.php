<?php
declare(strict_types=1);

if (!getenv('TEST_CONSOLE_STORAGE')) {
    putenv('TEST_CONSOLE_STORAGE=' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'http-smoke');
}

require dirname(__DIR__) . '/app/bootstrap.php';

$base = getenv('TEST_CONSOLE_URL') ?: 'http://127.0.0.1:8765';
$database = new Database(config('database_path'));
$checks = 0;
$records = [];
$cookieHeader = '';

$request = static function (string $url, string $method = 'GET', string $body = '', array $headers = [], int $expectedStatus = 200) use (&$checks, &$cookieHeader): string {
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    if ($cookieHeader !== '') {
        $headerLines[] = 'Cookie: ' . $cookieHeader;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'content' => $body,
        'header' => implode("\r\n", $headerLines),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $response = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches)) {
            $status = (int) $matches[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $line, $matches)) {
            $cookieHeader = $matches[1];
        }
    }
    $checks++;
    if ($response === false || $status !== $expectedStatus) {
        throw new RuntimeException("HTTP 请求失败：$method $url，状态 $status，预期 $expectedStatus");
    }

    return $response;
};

$assertContains = static function (string $needle, string $haystack, string $message) use (&$checks): void {
    $checks++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . '，缺少内容：' . $needle);
    }
};

$channels = [
    'sms_forward' => ['sms.txt', 'HTTP 原始短信测试 👋'],
    'sms_status_report' => ['status-report.json', '65533'],
    'status_note' => ['port-status.json', '3 已注册'],
    'mms_receive' => ['mms-payload.bin', 'HTTP 彩信正文测试'],
];

foreach ($channels as $channel => [$fixture, $expected]) {
    $headers = ['Content-Type' => 'application/json; charset=UTF-8'];
    if ($channel === 'sms_forward') {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
    }
    if ($channel === 'mms_receive') {
        $headers = [
            'Content-Type' => 'multipart/related; boundary=http-smoke-boundary; type=application/smil',
            'Emms-Subject' => 'dGVzdA==; charset=UTF-8',
            'Emms-From' => 'MTAwMDE=; type=PLMN; charset=UTF-8',
            'Emms-To' => 'MTAwMDI=; type=PLMN; charset=UTF-8',
        ];
    }
    $script = channelDefinitions()[$channel]['script'];
    $fixtureBody = file_get_contents(dirname(__DIR__) . '/tests/fixtures/' . $fixture);
    $request($base . '/' . $script, 'POST', $fixtureBody, $headers);

    $recordSearch = $database->search($channel, ['q' => hash('sha256', $fixtureBody)], 1, 1);
    $record = $recordSearch['items'][0] ?? null;
    if (!$record) {
        throw new RuntimeException("$channel 没有接收记录");
    }
    $records[$channel] = $record;
    $list = $request($base . '/index.php?channel=' . $channel);
    $assertContains('原始数据接收列表', $list, "$channel 应首先显示原始列表");
    $assertContains($record['id'], $list, "$channel 列表应包含完整记录 ID 的链接");
    $assertContains('✓ 已保存', $list, "$channel 列表应显示原始数据已保存");

    $detail = $request($base . '/view.php?id=' . $record['id']);
    $assertContains('原始记录解析', $detail, "$channel 应有独立解析页面");
    $assertContains('解析成功', $detail, "$channel 应解析成功");
    $assertContains($expected, $detail, "$channel 解析字段不正确");

    $downloaded = $request($base . '/raw.php?id=' . $record['id'] . '&file=body');
    $fixtureBody = file_get_contents(dirname(__DIR__) . '/tests/fixtures/' . $fixture);
    $checks++;
    if ($downloaded !== $fixtureBody) {
        throw new RuntimeException("$channel 下载的原始文件与发送内容不一致");
    }
}

$mms = $records['mms_receive'];
$png = $request($base . '/media.php?id=' . $mms['id'] . '&part=2');
$expectedPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$checks++;
if ($png !== $expectedPng) {
    throw new RuntimeException('彩信附件输出与原始 PNG 字节不一致');
}

$invalidResponse = $request(
    $base . '/sms_forward.php',
    'POST',
    '{invalid',
    ['Content-Type' => 'application/json']
);
$assertContains('Data has been logged.', $invalidResponse, '无效 JSON 也应先保存原始数据');
$invalidSearch = $database->search('sms_forward', ['q' => hash('sha256', '{invalid')], 1, 1);
$invalid = $invalidSearch['items'][0] ?? null;
if (!$invalid) {
    throw new RuntimeException('找不到无效 JSON 的原始记录');
}
$invalidDetail = $request($base . '/view.php?id=' . $invalid['id']);
$assertContains('✓ 原始数据已保存', $invalidDetail, '解析失败不能改变原始接收成功状态');
$assertContains('解析失败', $invalidDetail, '独立解析页应显示解析失败');

$deletionList = $request($base . '/index.php?channel=sms_forward');
$assertContains('data-select-all', $deletionList, '原始列表应提供本页全选复选框');
$checks++;
if (preg_match('/name="csrf_token" value="([^"]+)"/', $deletionList, $matches) !== 1) {
    throw new RuntimeException('原始列表缺少批量删除 CSRF 令牌');
}
$csrfToken = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
$request($base . '/delete.php', 'GET', '', [], 405);
$request($base . '/delete.php', 'POST', http_build_query([
    'channel' => 'sms_forward',
    'ids' => [$records['sms_forward']['id']],
]), ['Content-Type' => 'application/x-www-form-urlencoded'], 400);
$checks++;
if ($database->find($records['sms_forward']['id']) === null) {
    throw new RuntimeException('缺少 CSRF 令牌的请求不应删除记录');
}
$deleteRecords = [$records['sms_forward'], $invalid];
$deletePaths = [];
foreach ($deleteRecords as $deleteRecord) {
    $bodyPath = recordFilePath($deleteRecord, 'body_path');
    $deletePaths[] = [$bodyPath, dirname($bodyPath)];
}
$deleteBody = http_build_query([
    'channel' => 'sms_forward',
    'csrf_token' => $csrfToken,
    'ids' => array_column($deleteRecords, 'id'),
]);
$deletedList = $request($base . '/delete.php', 'POST', $deleteBody, [
    'Content-Type' => 'application/x-www-form-urlencoded',
]);
$assertContains('已删除 2 条原始记录', $deletedList, '批量删除后应显示实际删除数量');
foreach ($deleteRecords as $index => $deleteRecord) {
    $checks++;
    if ($database->find($deleteRecord['id']) !== null) {
        throw new RuntimeException('批量删除后数据库索引仍存在');
    }
    $checks++;
    if (file_exists($deletePaths[$index][0]) || is_dir($deletePaths[$index][1])) {
        throw new RuntimeException('批量删除后原始文件或记录目录仍存在');
    }
}

echo "HTTP 页面与原始文件验证通过：{$checks} 项检查。\n";
