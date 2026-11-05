<?php
function generateUniqueId() {
    return uniqid('file_', true); // 生成唯一 ID
}

// 解析复杂头字段（如 Base64 编码）
function parseHeaderValue($headerValue) {
    $base64Part = explode(';', $headerValue);
    $base64Part = $base64Part[0]; // 提取 ";" 前的部分
    return base64_decode(trim($base64Part));
}

// 创建 uploads 目录（如果不存在）
$uploadDir = '/var/www/html/uploads';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true); // 创建目录，权限为 0755
}

// 获取所有请求头
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}
$headers = getallheaders();
$outputText = "";

// 处理 Date
if (isset($headers['Date'])) {
    $date = trim($headers['Date']);
    $timestamp = strtotime($date);
    $formattedDate = date('D, d M Y H:i:s T', $timestamp);
    $outputText .= "Date: $formattedDate\n";
}

// 处理 Emms-Subject
if (isset($headers['Emms-Subject'])) {
    $subject = parseHeaderValue($headers['Emms-Subject']);
    $outputText .= "Emms-Subject: $subject\n";
}

// 处理 Emms-From
if (isset($headers['Emms-From'])) {
    $from = parseHeaderValue($headers['Emms-From']);
    $outputText .= "Emms-From: $from\n";
}

// 处理 Emms-To
if (isset($headers['Emms-To'])) {
    $to = parseHeaderValue($headers['Emms-To']);
    $outputText .= "Emms-To: $to\n";
}

// 获取原始 POST 数据
$rawData = file_get_contents("php://input");

// 提取 boundary
if (!isset($headers['Content-Type']) || !preg_match('/boundary=([^;]+)/', $headers['Content-Type'], $matches)) {
    die("无法找到 boundary");
}
$boundary = $matches[1];

// 解析 MIME 数据
$parts = explode('--' . $boundary, $rawData);
foreach ($parts as $part) {
    if (trim($part) === '' || $part === "--") {
        continue;
    }

    list($headersPart, $body) = explode("\r\n\r\n", $part, 2);
    $headersPart = explode("\r\n", $headersPart);

    $contentHeaders = array();
    foreach ($headersPart as $header) {
        if (strpos($header, ':') !== false) {
            list($key, $value) = explode(':', $header, 2);
            $contentHeaders[trim($key)] = trim($value);
        }
    }

    // 处理 Content-Type: text
    if (isset($contentHeaders['Content-Type']) && strpos($contentHeaders['Content-Type'], 'text/plain') !== false) {
        $outputText .= "Text Content: " . trim($body) . "\n";
    }

    // 处理 Content-Type: image
    if (isset($contentHeaders['Content-Type']) && strpos($contentHeaders['Content-Type'], 'image/jpeg') !== false) {
        $uniqueId = generateUniqueId();
        $imageFilename = $uploadDir . '/' . $uniqueId . '.jpg';
        file_put_contents($imageFilename, trim($body));
    }
}

// 保存文本内容
if ($outputText) {
    $uniqueId = generateUniqueId();
    $textFilename = $uploadDir . '/' . $uniqueId . '.txt';
    file_put_contents($textFilename, $outputText);
    echo "文本内容已保存为: $textFilename\n";
} else {
    echo "没有发现文本内容\n";
}
?>
