<?php
declare(strict_types=1);

final class Deletion
{
    public function __construct(private readonly Database $database)
    {
    }

    public function delete(array $ids, string $channel): array
    {
        if (!isset(channelDefinitions()[$channel])) {
            throw new InvalidArgumentException('未知数据功能。');
        }
        if (count($ids) > 100) {
            throw new InvalidArgumentException('每次最多删除 100 条记录。');
        }

        $result = ['deleted' => 0, 'failed' => 0, 'errors' => []];
        $normalizedIds = array_map(
            static fn (mixed $id): string => is_string($id) ? $id : '',
            $ids
        );
        foreach (array_unique($normalizedIds) as $id) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                $result['failed']++;
                $result['errors'][] = '包含无效的记录 ID。';
                continue;
            }

            $record = $this->database->find($id);
            if (!$record || $record['channel'] !== $channel) {
                $result['failed']++;
                $result['errors'][] = substr($id, 0, 12) . '…：记录不存在或不属于当前功能。';
                continue;
            }

            try {
                $this->deleteRecord($record);
                $result['deleted']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['errors'][] = substr($id, 0, 12) . '…：' . $exception->getMessage();
            }
        }

        return $result;
    }

    private function deleteRecord(array $record): void
    {
        $paths = [];
        $directory = null;
        $allowedNames = [];

        foreach (['body_path' => 'body.bin', 'request_path' => 'request.json'] as $key => $filename) {
            $relative = $record[$key] ?? null;
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $path = $this->safePath($record, $relative, $filename);
            $paths[] = $path;
            $allowedNames[] = $filename;
            $directory ??= dirname($path);
        }

        if ($directory !== null && is_dir($directory)) {
            $entries = scandir($directory);
            if ($entries === false) {
                throw new RuntimeException('无法读取原始记录目录。');
            }
            $unexpected = array_diff($entries, ['.', '..'], $allowedNames);
            if ($unexpected !== []) {
                throw new RuntimeException('记录目录包含额外文件，为避免误删已停止操作。');
            }

            foreach ($paths as $path) {
                if ((file_exists($path) || is_link($path)) && is_dir($path) && !is_link($path)) {
                    throw new RuntimeException('原始文件路径异常。');
                }
                if ((file_exists($path) || is_link($path)) && !is_writable($path)) {
                    throw new RuntimeException('原始文件不可写，无法删除。');
                }
            }
            if (!is_writable($directory)) {
                throw new RuntimeException('原始记录目录不可写，无法删除。');
            }
        }

        foreach ($paths as $path) {
            if ((file_exists($path) || is_link($path)) && !unlink($path)) {
                throw new RuntimeException('删除原始文件失败。');
            }
        }

        if ($directory !== null && is_dir($directory) && !rmdir($directory)) {
            throw new RuntimeException('删除原始记录目录失败。');
        }

        if (!$this->database->delete($record['id'], $record['channel'])) {
            throw new RuntimeException('删除数据库索引失败。');
        }
    }

    private function safePath(array $record, string $relative, string $filename): string
    {
        $pattern = '#^' . preg_quote($record['channel'], '#') .
            '/\d{4}/\d{2}/\d{2}/' . preg_quote($record['id'], '#') .
            '/' . preg_quote($filename, '#') . '$#D';
        if (preg_match($pattern, $relative) !== 1) {
            throw new RuntimeException('原始文件路径不符合存储规则。');
        }

        $root = config('storage_path') . DIRECTORY_SEPARATOR . 'raw';
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $rootReal = realpath($root);
        $parentReal = realpath(dirname($candidate));
        if ($parentReal !== false && ($rootReal === false ||
            !str_starts_with($parentReal, $rootReal . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('原始文件路径超出私有存储目录。');
        }

        return $candidate;
    }
}
