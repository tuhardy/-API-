<?php
declare(strict_types=1);

function config(?string $key = null): mixed
{
    global $config;

    return $key === null ? $config : ($config[$key] ?? null);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function channelDefinitions(): array
{
    return [
        'sms_forward' => [
            'label' => '短信接收',
            'script' => 'sms_forward.php',
            'description' => '查看短信回调的原始文本或 JSON。',
        ],
        'sms_status_report' => [
            'label' => '状态报告',
            'script' => 'sms_status_report.php',
            'description' => '查看发送状态报告的原始 JSON。',
        ],
        'status_note' => [
            'label' => '端口状态',
            'script' => 'status_note.php',
            'description' => '查看设备端口状态的原始 JSON。',
        ],
        'mms_receive' => [
            'label' => '彩信接收',
            'script' => 'mms_receive.php',
            'description' => '查看彩信的完整 MIME 原始数据。',
        ],
    ];
}

function normalizeChannel(?string $channel): string
{
    $channels = channelDefinitions();

    return isset($channels[$channel]) ? $channel : 'sms_forward';
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 2) . ' MB';
}

function formatUtcDate(?string $utc): string
{
    if (!$utc) {
        return '—';
    }

    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(config('timezone')))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $utc;
    }
}

function isUtf8Text(string $body): bool
{
    return !str_contains($body, "\0") && preg_match('//u', $body) === 1;
}

function utf8Limit(string $text, int $characters): string
{
    if (preg_match('/^.{0,' . $characters . '}/us', $text, $matches) === 1) {
        return $matches[0];
    }

    return substr($text, 0, $characters);
}

function rawPreview(?string $path, int $bodySize): string
{
    if ($bodySize === 0) {
        return '[空请求体]';
    }
    if (!$path || !is_file($path)) {
        return '[原始文件不可用]';
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return '[无法读取原始文件]';
    }
    $bytes = fread($handle, (int) config('raw_preview_bytes'));
    fclose($handle);

    if ($bytes === false) {
        return '[无法读取原始文件]';
    }

    $textBytes = $bytes;
    for ($attempt = 0; $attempt < 4 && !isUtf8Text($textBytes); $attempt++) {
        $textBytes = substr($textBytes, 0, -1);
    }
    if (!isUtf8Text($textBytes)) {
        return '[二进制原始数据] ' . formatBytes($bodySize);
    }

    $text = preg_replace('/\s+/u', ' ', $textBytes) ?? $textBytes;
    $text = trim($text);
    $preview = utf8Limit($text, 100);

    return strlen($bytes) < $bodySize || strlen($preview) < strlen($text)
        ? $preview . ' …'
        : $preview;
}

function recordFilePath(array $record, string $key): ?string
{
    $relative = $record[$key] ?? null;
    if (!is_string($relative) || $relative === '') {
        return null;
    }

    $root = realpath(config('storage_path') . DIRECTORY_SEPARATOR . 'raw');
    if ($root === false) {
        return null;
    }
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    $path = realpath($candidate);
    if ($path === false || !is_file($path)) {
        return null;
    }

    return str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : null;
}

function storageStatusLabel(string $status): string
{
    return $status === 'saved' ? '已保存' : '保存失败';
}

function parseStatusLabel(string $status): string
{
    return match ($status) {
        'success' => '解析成功',
        'warning' => '部分识别',
        'empty' => '空请求体',
        default => '解析失败',
    };
}

function queryUrl(string $path, array $parameters = []): string
{
    $query = http_build_query(array_filter(
        $parameters,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    ));

    return $path . ($query ? '?' . $query : '');
}

function valueToString(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ?: '[无法显示]';
}

function hexDump(string $bytes, int $limit = 4096): array
{
    $slice = substr($bytes, 0, $limit);
    $lines = [];
    $length = strlen($slice);

    for ($offset = 0; $offset < $length; $offset += 16) {
        $chunk = substr($slice, $offset, 16);
        $hex = implode(' ', str_split(strtoupper(bin2hex($chunk)), 2));
        $lines[] = sprintf('%08X  %-47s', $offset, $hex);
    }

    return [
        'text' => implode("\n", $lines),
        'truncated' => strlen($bytes) > $limit,
    ];
}
