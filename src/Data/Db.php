<?php

declare(strict_types=1);

namespace Gdpd\Data;

/**
 * Thin PDO wrapper for the MySQL database. Every call opens its own
 * statement against a shared long-lived PDO connection (one per PHP
 * request -- there is no persistent-connection sharing across requests to
 * worry about here, unlike the legacy server's threaded Delphi process).
 */
final class Db
{
    private \PDO $pdo;

    public function __construct(string $host, int $port, string $database, string $user, string $password)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $this->pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>} rows and the ordered column names of the result set
     */
    public function queryWithColumns(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        $columns = [];
        $columnCount = $statement->columnCount();
        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = $meta['name'] ?? ('col' . $i);
        }

        return [$rows, $columns];
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }
}
