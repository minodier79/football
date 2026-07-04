<?php
namespace App\Event;

class EntityCreatedEvent
{
    public function __construct(
        public string $entity,
        public int $id
    ) {}
}