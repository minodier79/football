<?php
namespace App\Listener;

use App\Core\Container;
use App\Event\EntityCreatedEvent;
use App\Event\EntityDeletedEvent;
use App\Event\EntityUpdatedEvent;
use App\Elastic\ElasticClient;

class EntityIndexerListener
{
    public function __construct(
        private Container $container,
        private ElasticClient $client,
    ) {}

    public function onCreated(
        EntityCreatedEvent $event
    ): void {
        $indexer = $this->resolveIndexer(
            $event->entity
        );
        $indexer->addDocument($event->entity, $event->id);
    }

    public function onUpdated(
        EntityUpdatedEvent $event
    ): void {
        $indexer = $this->resolveIndexer(
            $event->entity
        );
        $indexer->updateDocument($event->entity, $event->id);
    }

    public function onDeleted(
        EntityDeletedEvent $event
    ): void {

        $indexer = $this->resolveIndexer(
            $event->entity
        );

        $indexer->deleteDocument($event->id);
    }

    private function resolveIndexer(
        string $entity
    ): object {
        $className = sprintf(
            'App\\Elastic\\Indexer\\%sIndexer',
            ucfirst($entity)
        );

        return $this->container->get(
            $className
        );
    }
}