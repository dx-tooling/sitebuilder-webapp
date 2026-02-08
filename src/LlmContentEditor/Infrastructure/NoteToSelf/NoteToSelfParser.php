<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\NoteToSelf;

/**
 * Parses [NOTE TO SELF: ...] blocks from assistant response content.
 *
 * Uses the last occurrence if multiple blocks are present.
 * The note text must not contain ']' (closing bracket terminates the block).
 *
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
 */
final class NoteToSelfParser
{
    private const string MARKER = '[NOTE TO SELF:';

    public function parse(string $assistantContent): ?NoteToSelfParseResult
    {
        $pos = strrpos($assistantContent, self::MARKER);

        if ($pos === false) {
            return null;
        }

        $afterMarker = $pos + strlen(self::MARKER);
        $rest        = substr($assistantContent, $afterMarker);
        $bracketPos  = strpos($rest, ']');

        if ($bracketPos === false) {
            return null;
        }

        $noteContent    = trim(substr($rest, 0, $bracketPos));
        $blockStart     = $pos;
        $blockEnd       = $afterMarker + $bracketPos + 1;
        $beforeBlock    = substr($assistantContent, 0, $blockStart);
        $afterBlock     = substr($assistantContent, $blockEnd);
        $visibleContent = trim($beforeBlock . $afterBlock);

        return new NoteToSelfParseResult($visibleContent, $noteContent);
    }
}
