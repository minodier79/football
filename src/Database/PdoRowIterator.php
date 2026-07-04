<?php
namespace App\Database;

use \Generator;
use \IteratorAggregate;
use \PDO;
use \PDOException;
use \PDOStatement;
use \RuntimeException;

class PdoRowIterator implements IteratorAggregate
{
    private PDO $pdo;
    private string $sql;
    private array $params;
    private int $fetchMode;
    private ?int $chunkSize;

    public function __construct(
        PDO $pdo,
        string $sql,
        array $params = [],
        int $fetchMode = PDO::FETCH_ASSOC,
        ?int $chunkSize = null
    ) {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->params = $params;
        $this->fetchMode = $fetchMode;
        $this->chunkSize = $chunkSize;
    }

    public function getIterator(): Generator
    {
        if ($this->chunkSize !== null && $this->chunkSize <= 0) {
            throw new RuntimeException('chunkSize must be > 0');
        }

        try {
            $stmt = $this->pdo->prepare($this->sql);
            $stmt->execute($this->params);

            $fetchMode = $this->fetchMode;

            if ($this->chunkSize === null) {
                while (($row = $stmt->fetch($fetchMode)) !== false) {
                    yield $row;
                }
            } else {
                yield from $this->fetchChunked($stmt, $fetchMode);
            }

        } catch (PDOException $e) {
            throw new RuntimeException(
                "Error executing query: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    private function fetchChunked(PDOStatement $stmt, int $fetchMode): Generator
    {
        while (true) {
            $chunk = [];

            for ($i = 0; $i < $this->chunkSize; $i++) {
                $row = $stmt->fetch($fetchMode);

                if ($row === false) {
                    break;
                }

                $chunk[] = $row;
            }

            if (!$chunk) {
                break;
            }

            yield $chunk;
        }
    }
}