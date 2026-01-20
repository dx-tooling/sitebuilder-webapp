<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use Generator;

interface LlmContentEditorFacadeInterface
{
    /**
     * Runs the content editor agent on the given workspace with the given instruction.
     * Yields streaming chunks: event (structured agent feedback), text (LLM output), and done.
     * The caller is responsible for resolving and validating workspacePath (e.g. under an allowed root).
     *
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator;
}
