<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\NoteToSelf;

use App\LlmContentEditor\Infrastructure\NoteToSelf\NoteToSelfParser;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
 */
final class NoteToSelfParserTest extends TestCase
{
    private NoteToSelfParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NoteToSelfParser();
    }

    public function testParsesNoteAtEndOfContent(): void
    {
        $content = "I've added the footer section.\n\n[NOTE TO SELF: I added the footer.]";

        $result = $this->parser->parse($content);

        self::assertNotNull($result);
        self::assertSame("I've added the footer section.", $result->visibleContent);
        self::assertSame('I added the footer.', $result->noteContent);
    }

    public function testReturnsNullWhenNoBlockPresent(): void
    {
        $content = 'Just a normal assistant response with no note.';

        $result = $this->parser->parse($content);

        self::assertNull($result);
    }

    public function testTakesLastOccurrenceWhenMultipleBlocks(): void
    {
        $content = 'First part [NOTE TO SELF: first note] middle [NOTE TO SELF: last note]';

        $result = $this->parser->parse($content);

        self::assertNotNull($result);
        self::assertSame('First part [NOTE TO SELF: first note] middle ', $result->visibleContent);
        self::assertSame('last note', $result->noteContent);
    }

    public function testVisibleContentTrimmed(): void
    {
        $content = "  Done.  \n\n[NOTE TO SELF: done]  ";

        $result = $this->parser->parse($content);

        self::assertNotNull($result);
        self::assertSame('Done.', $result->visibleContent);
        self::assertSame('done', $result->noteContent);
    }

    public function testReturnsNullWhenMarkerNotClosedWithBracket(): void
    {
        $content = 'Text [NOTE TO SELF: unclosed';

        $result = $this->parser->parse($content);

        self::assertNull($result);
    }
}
