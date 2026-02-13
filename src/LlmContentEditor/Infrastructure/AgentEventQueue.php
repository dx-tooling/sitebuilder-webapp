<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure;

use App\AgenticContentEditor\Facade\Dto\AgentEventDto;

/**
 * Typed queue for AgentEventDto used to collect events from the observer.
 */
final class AgentEventQueue
{
    /** @var list<AgentEventDto> */
    private array $items = [];

    public function add(AgentEventDto $event): void
    {
        $this->items[] = $event;
    }

    /**
     * @return list<AgentEventDto>
     */
    public function drain(): array
    {
        $out         = $this->items;
        $this->items = [];

        return $out;
    }
}
