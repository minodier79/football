<?php
namespace App\Query;

use App\Database\PdoRowIterator;
use PDO;
use Traversable;

class SqlQueryHandler
{
    public function __construct(private PDO $pdo) {}

    public function iterator(string $sql, array $params = []): Traversable
    {
        return new PdoRowIterator($this->pdo, $sql, $params);
    }

    public function query(string $sql): ?array
    {
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    public function fetchOne(string $sql): ?array
    {
        $stmt = $this->pdo->query($sql);

        return $stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }

    public function find(string $table, int $id): ?array
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id', $table);die('');

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'id' => $id
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);

        $placeholders = array_map(
            fn ($column) => ':' . $column,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute($data);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, int $id, array $data): bool 
    {
        $fields = [];
        $params = [
            'id' => $id
        ];
        foreach ($data as $column => $value) {
            $fields[] = sprintf(
                '%s = :%s',
                $column,
                $column
            );
            $params[$column] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :id',
            $table,
            implode(', ', $fields)
        );

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(string $table, int $id): bool {

        $stmt = $this->pdo->prepare(
            sprintf(
                'DELETE FROM %s WHERE id = :id',
                $table
            )
        );

        return $stmt->execute([
            'id' => $id
        ]);
    }
}