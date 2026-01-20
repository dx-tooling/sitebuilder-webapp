<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

/**
 * DTO representing tool input parameters.
 * Tool inputs are a map of parameter names to values.
 */
readonly class ToolInputsDto
{
    /**
     * @param list<ToolInputEntryDto> $entries
     */
    public function __construct(
        public array $entries
    ) {
    }

    /**
     * Convert to associative array format expected by tools.
     * This is an internal conversion method, not crossing boundaries.
     *
     * @return array<string, mixed>
     *
     * @phpstan-ignore-next-line - Internal conversion method, returns associative array for tool compatibility
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            $result[$entry->name] = $entry->value;
        }

        return $result;
    }

    /**
     * Create from associative array.
     * This is a factory method that converts from array to DTO.
     *
     * @param array<string, mixed> $inputs
     *
     * @phpstan-ignore-next-line - Factory method that converts associative array to DTO
     */
    public static function fromArray(array $inputs): self
    {
        $entries = [];
        foreach ($inputs as $name => $value) {
            $entries[] = new ToolInputEntryDto($name, $value);
        }

        return new self($entries);
    }
}
