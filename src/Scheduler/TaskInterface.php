<?php

namespace App\Scheduler;

interface TaskInterface
{
    public function shouldRun(
        \DateTime $now
    ): bool;

    public function run(): void;
}