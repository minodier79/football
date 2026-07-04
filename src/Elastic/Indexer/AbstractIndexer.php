<?php
namespace App\Elastic\Indexer;

use App\Elastic\ElasticClient;
use App\Query\SqlQueryHandler;

abstract class AbstractIndexer
{
    public function __construct(
        private ElasticClient $client,
        private SqlQueryHandler $handler,
    ) {
    }

    public function getIndexName(): string 
    {
        return sprintf(
            '%ss_%s',
            strtolower(
                str_replace(
                    'Indexer',
                    '',
                    substr(strrchr(static::class, '\\'), 1)
                )
            ),
            date('Ymd_His')
        );
    }

    public function addDocument(string $entity, int $id): void {
        $this->client->insert(sprintf('%ss_current',$entity), $id, $this->getDocument($id));
    }

    public function updateDocument(string $entity, int $id): void {
        $this->client->update(sprintf('%ss_current',$entity), $id, $this->getDocument($id));
    }

    public function deleteDocument(string $entity, int $id): void {
        $this->client->delete(sprintf('%ss_current',$entity), $id);
    }

    private function getDocument(int $id): array {
      $row = $this->handler->fetchOne($this->getIndexQuery($id));
      
      return $this->buildDocument($row);
    }

    abstract protected function buildDocument(
        array $row
    ): array;

    abstract protected function getIndexQuery(
        ?int $id
    ): string;
}