<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建数据库目录。');
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS receipts (
                id TEXT PRIMARY KEY,
                channel TEXT NOT NULL,
                received_at TEXT NOT NULL,
                received_epoch INTEGER NOT NULL,
                method TEXT NOT NULL DEFAULT "",
                uri TEXT NOT NULL DEFAULT "",
                remote_addr TEXT NOT NULL DEFAULT "",
                content_type TEXT NOT NULL DEFAULT "",
                content_length INTEGER NOT NULL DEFAULT 0,
                body_size INTEGER NOT NULL DEFAULT 0,
                body_sha256 TEXT NOT NULL DEFAULT "",
                storage_status TEXT NOT NULL,
                body_path TEXT,
                request_path TEXT,
                error_message TEXT
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_channel_time ON receipts(channel, received_epoch DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_receipts_status ON receipts(storage_status)');
    }

    public function insert(array $record): void
    {
        $columns = [
            'id', 'channel', 'received_at', 'received_epoch', 'method', 'uri',
            'remote_addr', 'content_type', 'content_length', 'body_size',
            'body_sha256', 'storage_status', 'body_path', 'request_path', 'error_message',
        ];
        $sql = 'INSERT INTO receipts (' . implode(',', $columns) . ') VALUES (' .
            implode(',', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')';
        $statement = $this->pdo->prepare($sql);

        foreach ($columns as $column) {
            $statement->bindValue(':' . $column, $record[$column] ?? null);
        }
        $statement->execute();
    }

    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM receipts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return $record ?: null;
    }

    public function delete(string $id, string $channel): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM receipts WHERE id = :id AND channel = :channel');
        $statement->execute(['id' => $id, 'channel' => $channel]);

        return $statement->rowCount() === 1;
    }

    public function latest(string $channel): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM receipts WHERE channel = :channel ORDER BY received_epoch DESC, id DESC LIMIT 1'
        );
        $statement->execute(['channel' => $channel]);
        $record = $statement->fetch();

        return $record ?: null;
    }

    public function counts(string $channel): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN storage_status = "saved" THEN 1 ELSE 0 END) AS saved,
                    SUM(CASE WHEN storage_status = "failed" THEN 1 ELSE 0 END) AS failed
             FROM receipts WHERE channel = :channel'
        );
        $statement->execute(['channel' => $channel]);
        $counts = $statement->fetch() ?: [];

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'saved' => (int) ($counts['saved'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    public function search(string $channel, array $filters, int $page, int $perPage): array
    {
        $where = ['channel = :channel'];
        $parameters = ['channel' => $channel];

        if (in_array($filters['status'] ?? '', ['saved', 'failed'], true)) {
            $where[] = 'storage_status = :status';
            $parameters['status'] = $filters['status'];
        }
        if (!empty($filters['from_epoch'])) {
            $where[] = 'received_epoch >= :from_epoch';
            $parameters['from_epoch'] = $filters['from_epoch'];
        }
        if (!empty($filters['to_epoch'])) {
            $where[] = 'received_epoch <= :to_epoch';
            $parameters['to_epoch'] = $filters['to_epoch'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(id LIKE :q OR remote_addr LIKE :q OR content_type LIKE :q OR body_sha256 LIKE :q)';
            $parameters['q'] = '%' . $filters['q'] . '%';
        }

        $condition = implode(' AND ', $where);
        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM receipts WHERE $condition");
        $this->bindAndExecute($countStatement, $parameters);
        $total = (int) $countStatement->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $statement = $this->pdo->prepare(
            "SELECT * FROM receipts WHERE $condition
             ORDER BY received_epoch DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        $this->bindAndExecute($statement, $parameters + [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        return [
            'items' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private function bindAndExecute(PDOStatement $statement, array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            $type = in_array($key, ['from_epoch', 'to_epoch', 'limit', 'offset'], true)
                ? PDO::PARAM_INT
                : PDO::PARAM_STR;
            $statement->bindValue(':' . $key, $value, $type);
        }
        $statement->execute();
    }
}
