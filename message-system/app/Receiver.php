<?php
declare(strict_types=1);

final class Receiver
{
    public function __construct(
        private readonly Database $database,
        private readonly array $settings
    ) {
    }

    public function receive(string $channel, string $body, array $server, array $headers): array
    {
        $record = $this->baseRecord($channel, $server);
        $record['body_size'] = strlen($body);
        $record['body_sha256'] = hash('sha256', $body);

        if (strlen($body) > (int) $this->settings['max_body_bytes']) {
            return $this->reject(
                $channel,
                $server,
                '请求体超过允许大小，未保存为完整原始数据。',
                strlen($body)
            );
        }

        $relativeDirectory = $channel . '/' . gmdate('Y/m/d') . '/' . $record['id'];
        $absoluteDirectory = $this->settings['storage_path'] . DIRECTORY_SEPARATOR . 'raw' .
            DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        $bodyPath = $absoluteDirectory . DIRECTORY_SEPARATOR . 'body.bin';
        $requestPath = $absoluteDirectory . DIRECTORY_SEPARATOR . 'request.json';

        try {
            if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
                throw new RuntimeException('无法创建原始数据目录。');
            }

            $this->writeNewFile($bodyPath, $body);
            clearstatcache(true, $bodyPath);
            if (filesize($bodyPath) !== strlen($body)) {
                throw new RuntimeException('原始请求体写入后的长度校验失败。');
            }

            $metadata = [
                'id' => $record['id'],
                'channel' => $channel,
                'received_at_utc' => $record['received_at'],
                'method' => $record['method'],
                'uri' => $record['uri'],
                'remote_addr' => $record['remote_addr'],
                'request_protocol' => $server['SERVER_PROTOCOL'] ?? '',
                'content_type' => $record['content_type'],
                'content_length' => $record['content_length'],
                'body_size' => $record['body_size'],
                'body_sha256' => $record['body_sha256'],
                'headers' => $headers,
            ];
            $json = json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($json === false) {
                throw new RuntimeException('请求元数据无法编码为 JSON。');
            }
            $this->writeNewFile($requestPath, $json);

            $record['storage_status'] = 'saved';
            $record['body_path'] = $relativeDirectory . '/body.bin';
            $record['request_path'] = $relativeDirectory . '/request.json';
            $this->database->insert($record);

            return ['ok' => true, 'id' => $record['id'], 'status' => 200, 'message' => 'Data has been logged.'];
        } catch (Throwable $exception) {
            $record['storage_status'] = 'failed';
            $record['error_message'] = $exception->getMessage();
            $record['body_path'] = is_file($bodyPath) ? $relativeDirectory . '/body.bin' : null;
            $record['request_path'] = is_file($requestPath) ? $relativeDirectory . '/request.json' : null;
            $this->recordFailure($record);

            return ['ok' => false, 'id' => $record['id'], 'status' => 500, 'message' => 'Raw data storage failed.'];
        }
    }

    public function reject(string $channel, array $server, string $error, int $bodySize = 0): array
    {
        $record = $this->baseRecord($channel, $server);
        $record['body_size'] = $bodySize;
        $record['storage_status'] = 'failed';
        $record['error_message'] = $error;
        $this->recordFailure($record);

        return ['ok' => false, 'id' => $record['id'], 'status' => 413, 'message' => 'Request body rejected.'];
    }

    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    private function baseRecord(string $channel, array $server): array
    {
        if (!isset(channelDefinitions()[$channel])) {
            throw new InvalidArgumentException('未知回调类型。');
        }

        return [
            'id' => bin2hex(random_bytes(16)),
            'channel' => $channel,
            'received_at' => gmdate('Y-m-d H:i:s'),
            'received_epoch' => time(),
            'method' => (string) ($server['REQUEST_METHOD'] ?? ''),
            'uri' => (string) ($server['REQUEST_URI'] ?? ''),
            'remote_addr' => (string) ($server['REMOTE_ADDR'] ?? ''),
            'content_type' => (string) ($server['CONTENT_TYPE'] ?? ''),
            'content_length' => (int) ($server['CONTENT_LENGTH'] ?? 0),
            'body_size' => 0,
            'body_sha256' => '',
            'storage_status' => 'failed',
            'body_path' => null,
            'request_path' => null,
            'error_message' => null,
        ];
    }

    private function recordFailure(array $record): void
    {
        try {
            $this->database->insert($record);
        } catch (Throwable $exception) {
            $this->log($exception->getMessage());
        }
        $this->log($record['id'] . ' ' . ($record['error_message'] ?? '原始数据保存失败。'));
    }

    private function writeNewFile(string $path, string $contents): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('无法创建原始文件，文件不会被覆盖。');
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('原始文件写入不完整。');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('原始文件刷新到存储失败。');
            }
        } finally {
            fclose($handle);
        }
    }

    private function log(string $message): void
    {
        $directory = $this->settings['storage_path'] . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'app.log',
            '[' . gmdate('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
