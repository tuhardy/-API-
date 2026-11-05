<?php
declare(strict_types=1);

final class Parser
{
    public function parse(string $channel, string $body, array $metadata): array
    {
        if ($body === '') {
            return $this->result('empty', '请求体为空，原始空文件已保留。');
        }

        return match ($channel) {
            'sms_forward' => $this->parseSms($body),
            'sms_status_report' => $this->parseStatusReport($body),
            'status_note' => $this->parsePortStatus($body),
            'mms_receive' => $this->parseMms($body, $metadata),
            default => $this->result('error', '未知解析类型。'),
        };
    }

    private function parseSms(string $body): array
    {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && ($decoded['type'] ?? '') === 'recv-sms') {
                $result = $this->result('success', '识别为 Gateway recv-sms JSON。');
                $result['fields'] = $this->fields([
                    '消息类型' => $decoded['type'],
                    '短信数量' => $decoded['sms_num'] ?? null,
                ]);
                $rows = [];
                foreach (($decoded['sms'] ?? []) as $message) {
                    if (!is_array($message)) {
                        continue;
                    }
                    $content = $message[5] ?? '';
                    $decodedContent = base64_decode((string) $content, true);
                    $rows[] = [
                        ($message[0] ?? 0) == 1 ? '送达报告' : '普通短信',
                        $message[1] ?? '',
                        $this->timestampText($message[2] ?? null),
                        $message[3] ?? '',
                        $message[4] ?? '',
                        $decodedContent === false ? $content : $decodedContent,
                    ];
                }
                $result['tables'][] = [
                    'title' => '短信明细',
                    'columns' => ['类型', '接收端口', '设备接收时间', 'Sender', 'Receiver', '正文'],
                    'rows' => $rows,
                ];

                return $result;
            }

            return $this->genericJson($decoded, 'JSON 格式有效，但不是已识别的 recv-sms 结构。');
        }

        $first = substr(ltrim($body), 0, 1);
        if ($first === '{' || $first === '[') {
            return $this->result('error', 'JSON 解析失败：' . json_last_error_msg());
        }

        $names = [
            'Sender' => '发件人 Sender',
            'Receiver' => '接收地址 Receiver',
            'SMSC' => '短信中心 SMSC',
            'SCTS' => '服务中心时间 SCTS',
            'Slot' => '卡槽 Slot',
        ];
        $result = $this->result('warning', '未识别到短信文本头字段，请核对原始数据。');
        $found = false;
        foreach ($names as $key => $label) {
            if (preg_match('/^' . preg_quote($key, '/') . ':[ \t]*(.*)$/mi', $body, $matches) === 1) {
                $result['fields'][] = ['label' => $label, 'value' => rtrim($matches[1])];
                $found = true;
            }
        }

        $parts = preg_split('/\r\n\r\n|\n\n|\r\r/', $body, 2);
        $messageBody = count($parts) === 2 ? $parts[1] : '';
        if ($messageBody !== '') {
            $result['texts'][] = ['title' => '短信正文', 'content' => $messageBody];
        }
        if ($found) {
            $result['status'] = 'success';
            $result['message'] = '识别为现有设备使用的短信文本格式。';
        }

        return $result;
    }

    private function parseStatusReport(string $body): array
    {
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->result('error', '状态报告不是有效 JSON：' . json_last_error_msg());
        }
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'status-report') {
            return $this->genericJson($decoded, 'JSON 格式有效，但不是已识别的 status-report 结构。');
        }

        $result = $this->result('success', '识别为 Gateway status-report。累计计数与增量明细分别展示。');
        $result['fields'] = $this->fields([
            '消息类型' => $decoded['type'],
            '请求序号 cseq' => $decoded['cseq'] ?? null,
            '报告数量 rpt_num' => $decoded['rpt_num'] ?? null,
        ]);
        $summaryRows = [];
        $successRows = [];
        $failureRows = [];

        foreach (($decoded['rpts'] ?? []) as $report) {
            if (!is_array($report)) {
                continue;
            }
            $summaryRows[] = [
                $report['tid'] ?? '',
                $report['sending'] ?? '',
                $report['sent'] ?? '',
                $report['failed'] ?? '',
                $report['unsent'] ?? '',
            ];
            foreach (($report['sdr'] ?? []) as $row) {
                if (is_array($row)) {
                    $successRows[] = [
                        $report['tid'] ?? '',
                        $row[0] ?? '',
                        $row[1] ?? '',
                        $row[2] ?? '',
                        $this->timestampText($row[3] ?? null),
                    ];
                }
            }
            foreach (($report['fdr'] ?? []) as $row) {
                if (is_array($row)) {
                    $failureRows[] = [
                        $report['tid'] ?? '',
                        $row[0] ?? '',
                        $row[1] ?? '',
                        $row[2] ?? '',
                        $this->timestampText($row[3] ?? null),
                        $row[4] ?? '',
                        $row[5] ?? '',
                    ];
                }
            }
        }

        $result['tables'][] = [
            'title' => '任务累计计数',
            'columns' => ['TID', '发送中 sending', '成功 sent', '失败 failed', '未发送 unsent'],
            'rows' => $summaryRows,
        ];
        if ($successRows) {
            $result['tables'][] = [
                'title' => '本次新增成功明细 sdr',
                'columns' => ['TID', '号码序号', '号码', '发送端口', '发送时间（UTC）'],
                'rows' => $successRows,
            ];
        }
        if ($failureRows) {
            $result['tables'][] = [
                'title' => '本次新增失败明细 fdr',
                'columns' => ['TID', '号码序号', '号码', '发送端口', '发送时间（UTC）', '进度原因', '运营商原因'],
                'rows' => $failureRows,
            ];
        }

        return $result;
    }

    private function parsePortStatus(string $body): array
    {
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->result('error', '端口状态不是有效 JSON：' . json_last_error_msg());
        }
        if (!is_array($decoded) || !in_array($decoded['type'] ?? '', ['port-status', 'dev-status'], true)) {
            return $this->genericJson($decoded, 'JSON 格式有效，但不是已识别的 port-status / dev-status 结构。');
        }

        $type = $decoded['type'];
        $result = $this->result('success', '识别为 Gateway ' . $type . '。');
        if ($type === 'dev-status') {
            $result['fields'] = $this->fields([
                '消息类型' => $type,
                '设备 MAC' => $decoded['mac'] ?? null,
                '设备 IP' => $decoded['ip'] ?? null,
                '序列号 seq' => $decoded['seq'] ?? null,
                '有效期 expires' => $decoded['expires'] ?? null,
                '最大端口数' => $decoded['max-ports'] ?? null,
                '最大卡槽数' => $decoded['max-slots'] ?? null,
            ]);
            $rows = [];
            foreach (($decoded['status'] ?? []) as $port) {
                if (is_array($port)) {
                    $rows[] = [
                        $port['port'] ?? '',
                        $this->portState($port['st'] ?? null),
                        $port['sn'] ?? '',
                        $port['opr'] ?? '',
                        $port['sig'] ?? '',
                        $port['imei'] ?? '',
                    ];
                }
            }
            $result['tables'][] = [
                'title' => '端口明细',
                'columns' => ['端口', '状态', 'SIM 号码', '运营商', '信号', 'IMEI'],
                'rows' => $rows,
            ];

            return $result;
        }

        $labels = [
            'type' => '消息类型',
            'mac' => '设备 MAC',
            'port' => '端口',
            'sim' => 'SIM 标识',
            'seq' => '序列号',
            'st' => '状态码',
            'imei' => 'IMEI',
            'active' => '当前卡 active',
            'inserted' => '已插卡 inserted',
            'slot_active' => '卡槽启用 slot_active',
            'led' => 'LED',
            'iccid' => 'ICCID',
            'imsi' => 'IMSI',
            'sn' => 'SIM 号码',
            'opr' => '运营商',
            'bal' => '余额',
            'sig' => '信号',
            'dataflow_tot' => '总流量',
            'dataflow_day' => '当日流量',
            'dataflow_24hours' => '24 小时流量',
            'dataflow_1hour' => '1 小时流量',
            'dataflow_last' => '最近流量',
            'dataflow_url' => '流量地址',
        ];
        foreach ($labels as $key => $label) {
            $value = $decoded[$key] ?? null;
            if ($key === 'st') {
                $value = $this->portState($value);
            }
            $result['fields'][] = ['label' => $label, 'value' => valueToString($value)];
        }

        return $result;
    }

    private function parseMms(string $body, array $metadata): array
    {
        $headers = $metadata['headers'] ?? [];
        $contentType = $metadata['content_type'] ?? $this->header($headers, 'Content-Type') ?? '';
        $result = $this->result('success', '彩信 MIME 解析完成。');
        $result['fields'] = $this->fields([
            '主题' => $this->decodeEmmsHeader($this->header($headers, 'Emms-Subject')),
            '发件人' => $this->decodeEmmsHeader($this->header($headers, 'Emms-From')),
            '收件人' => $this->decodeEmmsHeader($this->header($headers, 'Emms-To')),
            '设备发送时间' => $this->header($headers, 'Date'),
            'MIME 类型' => $contentType,
        ]);

        try {
            $parts = self::extractMmsParts($body, $contentType);
        } catch (Throwable $exception) {
            $result['status'] = 'error';
            $result['message'] = $exception->getMessage();

            return $result;
        }

        if (!$parts) {
            $result['status'] = 'warning';
            $result['message'] = 'MIME 中没有识别到有效内容段。';

            return $result;
        }

        foreach ($parts as $index => $part) {
            $mime = strtolower(strtok($part['content_type'], ';') ?: 'application/octet-stream');
            if ($mime === 'text/plain') {
                $result['texts'][] = [
                    'title' => '彩信文本 ' . ($index + 1),
                    'content' => $this->decodeText($part['body'], $part['content_type']),
                ];
                continue;
            }
            if ($mime === 'application/smil') {
                $result['texts'][] = [
                    'title' => 'SMIL 内容',
                    'content' => $this->decodeText($part['body'], $part['content_type']),
                ];
                continue;
            }

            $result['attachments'][] = [
                'part' => $index,
                'filename' => $part['filename'] ?: 'attachment-' . ($index + 1),
                'mime' => $mime,
                'size' => strlen($part['body']),
                'preview' => in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true),
            ];
        }

        return $result;
    }

    public static function extractMmsParts(string $body, string $contentType): array
    {
        if (preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $contentType, $matches) !== 1) {
            throw new RuntimeException('Content-Type 中缺少 multipart boundary。');
        }
        $boundary = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
        if ($boundary === '') {
            throw new RuntimeException('multipart boundary 为空。');
        }

        $segments = explode('--' . $boundary, $body);
        $parts = [];
        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue;
            }
            if (str_starts_with($segment, '--')) {
                break;
            }
            if (str_starts_with($segment, "\r\n")) {
                $segment = substr($segment, 2);
            } elseif (str_starts_with($segment, "\n")) {
                $segment = substr($segment, 1);
            }
            if (str_ends_with($segment, "\r\n")) {
                $segment = substr($segment, 0, -2);
            } elseif (str_ends_with($segment, "\n")) {
                $segment = substr($segment, 0, -1);
            }
            if ($segment === '') {
                continue;
            }

            $separator = str_contains($segment, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
            $position = strpos($segment, $separator);
            if ($position === false) {
                continue;
            }
            $headerBlock = substr($segment, 0, $position);
            $partBody = substr($segment, $position + strlen($separator));
            $headers = self::parsePartHeaders($headerBlock);
            $partContentType = $headers['content-type'] ?? 'application/octet-stream';

            if (strtolower($headers['content-transfer-encoding'] ?? '') === 'base64') {
                $decoded = base64_decode((string) preg_replace('/\s+/', '', $partBody), true);
                if ($decoded === false) {
                    throw new RuntimeException('彩信附件的 Base64 内容无效。');
                }
                $partBody = $decoded;
            }

            $filename = self::headerParameter($headers['content-disposition'] ?? '', 'filename')
                ?: self::headerParameter($partContentType, 'name')
                ?: trim($headers['content-location'] ?? '', '<> ');
            $parts[] = [
                'headers' => $headers,
                'content_type' => $partContentType,
                'filename' => $filename,
                'body' => $partBody,
            ];
        }

        return $parts;
    }

    private static function parsePartHeaders(string $block): array
    {
        $headers = [];
        $current = '';
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            if (preg_match('/^[ \t]+/', $line) === 1 && $current !== '') {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $current = strtolower(trim(substr($line, 0, $position)));
            $headers[$current] = trim(substr($line, $position + 1));
        }

        return $headers;
    }

    private static function headerParameter(string $header, string $name): string
    {
        $pattern = '/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|([^;\s]*))/i';
        if (preg_match($pattern, $header, $matches) !== 1) {
            return '';
        }

        return $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
    }

    private function genericJson(mixed $decoded, string $message): array
    {
        $result = $this->result('warning', $message);
        $result['texts'][] = [
            'title' => '解码后的 JSON',
            'content' => json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '[无法显示]',
        ];

        return $result;
    }

    private function result(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'fields' => [],
            'tables' => [],
            'texts' => [],
            'attachments' => [],
        ];
    }

    private function fields(array $values): array
    {
        $fields = [];
        foreach ($values as $label => $value) {
            $fields[] = ['label' => $label, 'value' => valueToString($value)];
        }

        return $fields;
    }

    private function header(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $wanted) === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    private function decodeEmmsHeader(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $base64 = trim(explode(';', $value, 2)[0]);
        if ($base64 === '') {
            return '—';
        }
        $decoded = base64_decode($base64, true);

        return $decoded === false ? $value : $decoded;
    }

    private function decodeText(string $body, string $contentType): string
    {
        $charset = self::headerParameter($contentType, 'charset') ?: 'UTF-8';
        if (!in_array(strtoupper($charset), ['UTF-8', 'UTF8'], true) && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $body;
    }

    private function timestampText(mixed $timestamp): string
    {
        if (!is_numeric($timestamp)) {
            return valueToString($timestamp);
        }

        return gmdate('Y-m-d H:i:s', (int) $timestamp) . ' (' . $timestamp . ')';
    }

    private function portState(mixed $state): string
    {
        $states = [
            0 => '无 SIM 卡',
            1 => 'SIM 空闲',
            2 => '注册中',
            3 => '已注册',
            4 => '通话中',
            5 => '无余额或告警',
            6 => '注册失败',
            7 => '设备锁卡',
            8 => '运营商锁卡',
            9 => 'SIM 识别错误',
            11 => '已检测到卡',
            12 => '用户锁定',
            13 => '端口内部通话',
            14 => '内部通话保持',
            15 => '已接入移动网络',
            16 => '模块响应超时',
        ];
        $code = is_numeric($state) ? (int) $state : null;

        return $code === null
            ? valueToString($state)
            : $code . ' ' . ($states[$code] ?? '未知状态');
    }
}
