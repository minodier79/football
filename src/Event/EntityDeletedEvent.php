<?php
namespace App\Event;

class EntityDeletedEvent
{
    public function __construct(
        public string $entity,
        public int $id
    ) {}
}