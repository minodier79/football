<?php
namespace App\Elastic;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

class ElasticClient
{
    public function __construct(
        private Client $client
    ) {}
    
    public function find(string $entity, int $id): ?array
    {
        try {
            $response = $this->client->get([
                'index' => sprintf('%ss_current', $entity),
                'id' => $id
            ]);

            return $response->asArray()['_source']
                ?? null;

        } catch (ClientResponseException $e) {
            /**
             * DOCUMENT NOT FOUND
             */
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function insert(string $index, int $id, array $document): array {
        return $this->client->index([
            'index' => $index,
            'id' => $id,
            'op_type' => 'create',
            'body' => $document
        ])->asArray();
    }

    public function update(string $index, int $id, array $document): array {
        $response = $this->client->update([
            'index' => $index,
            'id' => $id,
            'body' => [
                'doc' => $document
            ]
        ]);

        return $response->asArray();
    }

    public function delete(string $index, int $id): array {
        return $this->client->delete([
            'index' => $index,
            'id' => $id
        ])->asArray();
    }

    public function createIndex(string $indexName): void
    {
        $this->client->indices()->create([
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => $_ENV['ES_REPLICAS'],
                    'refresh_interval' => '-1'
                ]
            ]
        ]);
    }

    public function sendBulk(array &$bulk): void
    {
        if (empty($bulk['body'])) return;

        $this->client->bulk($bulk);

        $bulk = ['body' => []];
    }

    public function restoreSettings($indexName): void
    {        
        $this->client->indices()->putSettings([
            'index' => $indexName,
            'body' => [
                'refresh_interval' => '1s',
                'number_of_replicas' => $_ENV['ES_REPLICAS']
            ]
        ]);
    }

    public function indexExists(string $indexName): bool
    {
        try {
            $response = $this->client->indices()->exists([
                'index' => $indexName
            ]);

            return $response->getStatusCode() === 200;
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    public function getAliases(string $aliasName): array
    {
        try {
            $response = $this->client->indices()->getAlias([
                    'name' => $aliasName
            ]);
        } catch (\Exception $e) {
            return [];
        }
        
        return $response->asArray();
    }

    public function updateIndexAliases(array $actions): void {
        try {
            $this->client->indices()->updateAliases([
                'body' => [
                    'actions' => $actions
                ]
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    public function getIndices(string $pattern): array
    {
        try {
            $response = $this->client->indices()->get([
                'index' => $pattern
            ]);

            return array_keys($response->asArray());
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }
    }

    public function deleteIndex(string $index): void {
        try {
            $this->client->indices()->delete([
                'index' => $index
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }
}