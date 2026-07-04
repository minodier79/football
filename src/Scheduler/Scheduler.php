<?php

namespace App\Scheduler;

class Scheduler
{
    public function __construct(
        private iterable $tasks
    ) {}

    public function run(): void
    {
        $now = new \DateTime();

        foreach ($this->tasks as $task) {

            if ($task->shouldRun($now)) {
                $task->run();
            }
        }
    }
}