<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$testRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tests' .
    DIRECTORY_SEPARATOR . 'runtime-' . bin2hex(random_bytes(6));
$config['storage_path'] = $testRoot;
$config['database_path'] = $testRoot . DIRECTORY_SEPARATOR . 'app.sqlite';

$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$parser = new Parser();

$sms = "Sender: 10001\r\nReceiver: \"1.01\"\r\nSMSC: 10086\r\nSCTS: 250327005127A8\r\nSlot: \"01\"\r\n\r\n测试短信 abcd 👋";
$smsResult = $parser->parse('sms_forward', $sms, []);
$assertSame('success', $smsResult['status'], '文本短信应解析成功');
$assertSame('发件人 Sender', $smsResult['fields'][0]['label'], '短信首个字段应为 Sender');
$assertSame('10001', $smsResult['fields'][0]['value'], 'Sender 值应正确');
$assertSame('测试短信 abcd 👋', $smsResult['texts'][0]['content'], '短信正文和编码应保持不变');

$jsonSms = json_encode([
    'type' => 'recv-sms',
    'sms_num' => 1,
    'sms' => [[0, '1.01', 1754358667, '10001', '10002', base64_encode('JSON 短信')]],
], JSON_UNESCAPED_UNICODE);
$jsonSmsResult = $parser->parse('sms_forward', $jsonSms, []);
$assertSame('success', $jsonSmsResult['status'], 'JSON 短信应解析成功');
$assertSame('JSON 短信', $jsonSmsResult['tables'][0]['rows'][0][5], 'recv-sms 正文应 Base64 解码');

$report = json_encode([
    'type' => 'status-report',
    'cseq' => 2,
    'rpt_num' => 1,
    'rpts' => [[
        'tid' => 201107,
        'sending' => 0,
        'sent' => 1,
        'failed' => 1,
        'unsent' => 0,
        'sdr' => [[0, '10001', '1.01', 1754358667]],
        'fdr' => [[1, '10002', '1.02', 1754358668, '6', '65533']],
    ]],
]);
$reportResult = $parser->parse('sms_status_report', $report, []);
$assertSame('success', $reportResult['status'], '状态报告应解析成功');
$assertSame(3, count($reportResult['tables']), '状态报告应包含汇总、成功和失败三个表');
$assertSame('65533', $reportResult['tables'][2]['rows'][0][6], '失败报告的运营商原因应保留');

$port = json_encode([
    'type' => 'port-status',
    'mac' => '00-00-00-00-00-01',
    'port' => '1.01',
    'st' => 3,
    'sn' => '10001',
    'opr' => 'Test Operator',
    'sig' => 20,
]);
$portResult = $parser->parse('status_note', $port, []);
$assertSame('success', $portResult['status'], '端口状态应解析成功');
$assertSame('3 已注册', $portResult['fields'][5]['value'], '端口状态码应转换为可读文本');

$invalidJson = $parser->parse('sms_status_report', '{invalid', []);
$assertSame('error', $invalidJson['status'], '无效 JSON 应返回解析失败');

$boundary = 'test-boundary-123';
$jpeg = "\xFF\xD8\x00\x20\x09\xFF\xD9";
$mms = '--' . $boundary . "\r\nContent-Type: application/smil\r\n\r\n<smil>test</smil>" .
    "\r\n--" . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n测试彩信" .
    "\r\n--" . $boundary . "\r\nContent-Type: image/jpeg; name=\"test.jpg\"\r\n\r\n" . $jpeg .
    "\r\n--" . $boundary . "--\r\n";
$contentType = 'multipart/related; boundary="' . $boundary . '"; type="application/smil"';
$metadata = [
    'content_type' => $contentType,
    'headers' => [
        'Content-Type' => $contentType,
        'Emms-Subject' => base64_encode('测试主题') . '; charset=UTF-8',
        'Emms-From' => base64_encode('10001') . '; type=PLMN; charset=UTF-8',
        'Emms-To' => base64_encode('10002') . '; type=PLMN; charset=UTF-8',
        'Date' => 'Thu, 19 Dec 2024 03:58:06 GMT',
    ],
];
$mmsResult = $parser->parse('mms_receive', $mms, $metadata);
$assertSame('success', $mmsResult['status'], '彩信应解析成功');
$assertSame('测试主题', $mmsResult['fields'][0]['value'], '彩信主题应 Base64 解码');
$assertSame(1, count($mmsResult['attachments']), '彩信应包含一个附件');
$parts = Parser::extractMmsParts($mms, $contentType);
$assertSame($jpeg, $parts[2]['body'], 'MIME 图片二进制不应被 trim 或改变');
$assertSame('test.jpg', $parts[2]['filename'], '彩信附件名应正确解析');

$database = new Database($config['database_path']);
$receiver = new Receiver($database, $config);
$server = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/mms_receive.php',
    'REMOTE_ADDR' => '127.0.0.1',
    'CONTENT_TYPE' => $contentType,
    'CONTENT_LENGTH' => strlen($mms),
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];
$received = $receiver->receive('mms_receive', $mms, $server, $metadata['headers']);
$assertTrue($received['ok'], '原始彩信应保存成功');
$record = $database->find($received['id']);
$assertSame('saved', $record['storage_status'], '数据库接收状态应为已保存');
$storedBody = recordFilePath($record, 'body_path');
$assertSame($mms, file_get_contents($storedBody), '保存后的原始请求体必须逐字节一致');
$assertSame(hash('sha256', $mms), $record['body_sha256'], '原始请求 SHA-256 应正确');
$assertTrue(recordFilePath($record, 'request_path') !== null, '请求信息文件应存在');

$receivedAgain = $receiver->receive('mms_receive', $mms, $server, $metadata['headers']);
$assertTrue($receivedAgain['ok'], '重复请求也应保存');
$assertTrue($receivedAgain['id'] !== $received['id'], '重复请求必须生成不同原始记录，不覆盖旧文件');

$empty = $receiver->receive('sms_forward', '', $server, []);
$assertTrue($empty['ok'], '空请求体也应保存为空原始文件');
$emptyRecord = $database->find($empty['id']);
$assertSame(0, (int) $emptyRecord['body_size'], '空请求体大小应为零');

$counts = $database->counts('mms_receive');
$assertSame(2, $counts['saved'], '彩信功能应有两条已保存记录');
$search = $database->search('mms_receive', ['q' => '127.0.0.1'], 1, 20);
$assertSame(2, $search['total'], '按来源 IP 筛选应找到两条彩信');

$deletion = new Deletion($database);
$secondRecord = $database->find($receivedAgain['id']);
$secondBody = recordFilePath($secondRecord, 'body_path');
$secondDirectory = dirname($secondBody);
$wrongChannel = $deletion->delete([$receivedAgain['id']], 'sms_forward');
$assertSame(0, $wrongChannel['deleted'], '不能通过其他功能删除原始记录');
$assertSame(1, $wrongChannel['failed'], '跨功能删除应返回失败数量');
$assertTrue(is_file($secondBody), '跨功能删除失败后原始文件必须保留');

$unexpectedFile = $secondDirectory . DIRECTORY_SEPARATOR . 'unexpected.txt';
file_put_contents($unexpectedFile, 'must be preserved');
$blockedDeletion = $deletion->delete([$receivedAgain['id']], 'mms_receive');
$assertSame(0, $blockedDeletion['deleted'], '记录目录含额外文件时应停止删除');
$assertTrue(is_file($secondBody) && is_file($unexpectedFile), '安全停止时原始文件和额外文件都必须保留');
unlink($unexpectedFile);

$deleted = $deletion->delete([$receivedAgain['id']], 'mms_receive');
$assertSame(1, $deleted['deleted'], '选中的原始记录应删除成功');
$assertSame(0, $deleted['failed'], '正常批量删除不应有失败记录');
$assertTrue($database->find($receivedAgain['id']) === null, '删除后数据库索引应不存在');
$assertTrue(!file_exists($secondBody) && !is_dir($secondDirectory), '删除后原始文件和记录目录应不存在');
$assertTrue($database->find($received['id']) !== null && is_file($storedBody), '批量删除不能影响未选中的记录');

$emptyDeleted = $deletion->delete([$empty['id']], 'sms_forward');
$assertSame(1, $emptyDeleted['deleted'], '空请求体记录也应可删除');

echo "全部测试通过：{$checks} 项检查。\n";

$deletion = null;
$receiver = null;
$database = null;
gc_collect_cycles();

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($testRoot);
