<?php
namespace App\Event;

class EntityUpdatedEvent
{
    public function __construct(
        public string $entity,
        public int $id
    ) {}
}