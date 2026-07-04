<?php 
namespace App\Controller;

use App\Core\EventDispatcher;
use App\Elastic\ElasticClient;
use App\Event\EntityCreatedEvent;
use App\Event\EntityDeletedEvent;
use App\Event\EntityUpdatedEvent;
use App\Http\RequestContext;
use App\Query\SqlQueryHandler;

class EntityController
{
    private static array $isElasticIndex = [
        'edition',
        'standing',
        'game'
    ];

    private static array $dependencies = [
        'edition' => 'edition',
        'edition_status' => 'edition',
        'winner' => 'edition',
    ];
    
    public function __construct(
        private ElasticClient $client,
        private SqlQueryHandler $handler,
        private RequestContext $context,
        private EventDispatcher $dispatcher,
    ) {}

    public function index() {
        echo json_encode(['list editions']);
    }

    public function show(int $id) {
        if ($this->askElastic()) {
            echo json_encode($this->client->find($this->context->entity,$id) ?? []);
        } else {
            echo json_encode($this->handler->find($this->context->entity,$id) ?? []);
        }
    }

    public function insert(array $data) {
        $id = $this->handler->insert($this->context->entity,$data);
        if ($this->needToReindex()) {
            $this->dispatcher->dispatch(
                $this->getEntityEvent($id),
            );
        }
        echo $this->show($id);
    }

    public function update($id, array $data) {
        if ($this->handler->update($this->context->entity,$id,$data)) {
            if ($this->needToReindex()) {
                $this->dispatcher->dispatch(
                    new EntityUpdatedEvent(
                        self::$dependencies[$this->context->entity],
                        $id
                    )
                );
            }
        }
        echo $this->show($id);
    }

    public function delete($id) {
        $this->handler->delete($this->context->entity,$id);
        if ($this->needToReindex()) {
            $this->dispatcher->dispatch(
                new EntityDeletedEvent(
                    self::$dependencies[$this->context->entity],
                    $id
                )
            );
        }
        echo json_encode(['deleted' => $id]);
    }

    private function askElastic(): bool
    {
        return in_array($this->context->entity, self::$isElasticIndex);
    }

    private function needToReindex(): bool
    {
        return in_array($this->context->entity, self::$dependencies);
    }

    private function getEntityEvent(int $id): EntityCreatedEvent | EntityUpdatedEvent {
        return $this->askElastic() ? 
          new EntityCreatedEvent(
              self::$dependencies[$this->context->entity],
              $id
          )
          :
          new EntityUpdatedEvent(
              self::$dependencies[$this->context->entity],
              $id
          );
    }

}