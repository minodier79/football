<?php
namespace App\Core;

class EventDispatcher
{
    private array $listeners = [];

    public function listen(
        string $event,
        $listener
    ): void {

        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $eventClass = $event::class;

        foreach (
            $this->listeners[$eventClass] ?? []
            as $listener
        ) {
            call_user_func($listener,$event);
        }
    }
}