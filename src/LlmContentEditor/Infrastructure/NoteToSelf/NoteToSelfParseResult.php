<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\NoteToSelf;

/**
 * Result of parsing note-to-self block from assistant content.
 *
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
 */
readonly class NoteToSelfParseResult
{
    public function __construct(
        public string $visibleContent,
        public string $noteContent,
    ) {
    }
}
