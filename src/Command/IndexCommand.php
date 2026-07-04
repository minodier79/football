<?php
namespace App\Command;

use App\Elastic\ElasticClient;
use App\Query\SqlQueryHandler;

class IndexCommand
{
    private array $actions = [];

    public function __construct(
        private ElasticClient $client,    
        private SqlQueryHandler $handler,
    ) {}

    public function full(array $params = []): void
    {
        $type = $params['type'] ?? 'edition';
        
        $className = sprintf(
            'App\\Elastic\\Indexer\\%sIndexer',
            ucfirst($type)
        );

        $indexer = new $className($this->client, $this->handler);

        $indexName = $indexer->getIndexName();
        
        $aliasName = sprintf('%ss_current', $type);

        echo sprintf("Creating index %s...\n", $indexName);

        $this->client->createIndex($indexName);

        echo sprintf("Indexing %ss...\n", $type);

        $bulk = ['body' => []];
        $count = 0;

        foreach ($this->handler->iterator($indexer->getIndexQuery()) as $row) {
            $bulk['body'][] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => (int)$row['id']
                ]
            ];
            $bulk['body'][] = $indexer->buildDocument($row);

            $count++;

            if ($count % 1000 === 0) {
                $response = $this->client->sendBulk($bulk);
                
                if (!empty($response['errors'])) {
                    $this->handleError($response);
                }

                $bulk = ['body' => []];

                echo sprintf("Indexed: %d\n", $count);
            }
        }

        if (!empty($bulk['body'])) {

            $response = $this->client->sendBulk($bulk);

            $bulk = ['body' => []];
        }

        $this->client->restoreSettings($indexName);
        
        $aliases = $this->client->getAliases($aliasName);

        $this->switchAlias($aliases, $aliasName, $indexName);

        if (!empty($aliases)) {
            $this->deleteOldIndexes($aliases);
        }

        echo sprintf("DONE 🚀 (%d indexed)\n", $count);
    }

    public function switchAlias(array $aliases, string $aliasName, string $indexName): void
    {
        try {
            $this->removeOldIndexes($aliases, $aliasName);
        } catch (\Exception $e) {
            // alias inexistant
        }
        
        $this->addNewIndex($indexName, $aliasName);
        
        echo sprintf("Alias switched to %s\n", $indexName);
    }

    private function removeOldIndexes(array $aliases, string $aliasName): void
    {
        foreach (array_keys($aliases) as $oldIndex) {

            $this->actions[] = [
                'remove' => [
                    'index' => $oldIndex,
                    'alias' => $aliasName
                ]
            ];
        }
    }

    private function addNewIndex(string $indexName, string $aliasName): void
    {
        $this->actions[] = [
            'add' => [
                'index' => $indexName,
                'alias' => $aliasName
            ]
        ];

        $this->client->updateIndexAliases($this->actions);
    }

    private function deleteOldIndexes($aliases): void
    {
        foreach (array_keys($aliases) as $oldIndex) {

            echo sprintf("Deleting old index %s\n", $oldIndex);

            $this->client->deleteIndex($oldIndex);
        }
    }

    private function handleError(array $response): void
    {
        foreach ($response['items'] as $item) {

            $action = current($item);

            if (isset($action['error'])) {
                echo sprintf(
                    "ERROR ID %s: %s\n",
                    $action['_id'] ?? '?',
                    json_encode($action['error'])
                );
            }
        }
    }
}