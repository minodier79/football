<?php

namespace App\Scheduler;

class IndexFullTask implements TaskInterface
{
    public function shouldRun(
        \DateTime $now
    ): bool {
        return $now->format('H:i') === '05:00';
    }

    public function run(): void
    {
        foreach (['edition', 'standing'/*, 'game'*/] as $type) {
          exec(sprintf(
              '%s bin/console index:full --type=%s >> %s/football.log 2>&1',
              $_ENV['PHP_BINARY'],
              $type,
              $_ENV['LOG_PATH']
          ));
        }
    }
}