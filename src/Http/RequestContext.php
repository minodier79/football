<?php

namespace App\Http;

class RequestContext
{
    public function __construct(
        public string $entity,
        public ?int $limit = 100,
        public ?int $start = 0,
        public ?string $search = null,
        public ?string $sort = null,
        public ?string $order = 'asc'
    ) {}
}