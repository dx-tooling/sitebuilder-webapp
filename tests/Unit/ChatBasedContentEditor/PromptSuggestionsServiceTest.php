<?php

declare(strict_types=1);

namespace Tests\Unit\ChatBasedContentEditor;

use App\ChatBasedContentEditor\Presentation\Service\PromptSuggestionsService;
use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;

final class PromptSuggestionsServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/prompt-suggestions-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $path): void
    {
        if (is_dir($path)) {
            $files = array_diff(scandir($path) ?: [], ['.', '..']);
            foreach ($files as $file) {
                $this->recursiveDelete($path . '/' . $file);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function createSuggestionsFile(string $content): void
    {
        $dir = $this->tempDir . '/.sitebuilder';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/prompt-suggestions.md', $content);
    }

    private function readSuggestionsFile(): string
    {
        return file_get_contents($this->tempDir . '/.sitebuilder/prompt-suggestions.md') ?: '';
    }

    // ─── getSuggestions ─────────────────────────────────────────────

    public function testReturnsEmptyArrayWhenWorkspacePathIsNull(): void
    {
        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions(null);

        self::assertSame([], $result);
    }

    public function testReturnsEmptyArrayWhenWorkspacePathDoesNotExist(): void
    {
        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions('/non/existent/path');

        self::assertSame([], $result);
    }

    public function testReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame([], $result);
    }

    public function testReturnsEmptyArrayWhenFileIsEmpty(): void
    {
        $this->createSuggestionsFile('');

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame([], $result);
    }

    public function testParsesLinesAsSuggestions(): void
    {
        $this->createSuggestionsFile("Create a new landingpage for topic <topic>\nMake a suggestion to improve landingpage <foo>");

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame([
            'Create a new landingpage for topic <topic>',
            'Make a suggestion to improve landingpage <foo>',
        ], $result);
    }

    public function testFiltersEmptyLines(): void
    {
        $this->createSuggestionsFile("First suggestion\n\nSecond suggestion\n\n\nThird suggestion");

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame([
            'First suggestion',
            'Second suggestion',
            'Third suggestion',
        ], $result);
    }

    public function testTrimsWhitespaceFromLines(): void
    {
        $this->createSuggestionsFile("  Suggestion with leading spaces  \n\tTabbed suggestion\t");

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame([
            'Suggestion with leading spaces',
            'Tabbed suggestion',
        ], $result);
    }

    public function testHandlesSingleLineSuggestion(): void
    {
        $this->createSuggestionsFile('Single suggestion');

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame(['Single suggestion'], $result);
    }

    public function testHandlesTrailingNewline(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $result = $service->getSuggestions($this->tempDir);

        self::assertSame(['First', 'Second'], $result);
    }

    // ─── addSuggestion ─────────────────────────────────────────────

    public function testAddSuggestionPrependsToExistingFile(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();
        $result  = $service->addSuggestion($this->tempDir, 'Third');

        self::assertSame(['Third', 'First', 'Second'], $result);
        self::assertSame("Third\nFirst\nSecond\n", $this->readSuggestionsFile());
    }

    public function testAddSuggestionCreatesFileIfNotExists(): void
    {
        $service = new PromptSuggestionsService();
        $result  = $service->addSuggestion($this->tempDir, 'Brand new suggestion');

        self::assertSame(['Brand new suggestion'], $result);
        self::assertSame("Brand new suggestion\n", $this->readSuggestionsFile());
    }

    public function testAddSuggestionTrimsText(): void
    {
        $service = new PromptSuggestionsService();
        $result  = $service->addSuggestion($this->tempDir, '  Trimmed suggestion  ');

        self::assertSame(['Trimmed suggestion'], $result);
    }

    public function testAddSuggestionThrowsOnEmptyText(): void
    {
        $service = new PromptSuggestionsService();

        $this->expectException(InvalidArgumentException::class);
        $service->addSuggestion($this->tempDir, '');
    }

    public function testAddSuggestionThrowsOnWhitespaceOnlyText(): void
    {
        $service = new PromptSuggestionsService();

        $this->expectException(InvalidArgumentException::class);
        $service->addSuggestion($this->tempDir, '   ');
    }

    // ─── updateSuggestion ──────────────────────────────────────────

    public function testUpdateSuggestionReplacesAtIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\nThird\n");

        $service = new PromptSuggestionsService();
        $result  = $service->updateSuggestion($this->tempDir, 1, 'Updated second');

        self::assertSame(['First', 'Updated second', 'Third'], $result);
        self::assertSame("First\nUpdated second\nThird\n", $this->readSuggestionsFile());
    }

    public function testUpdateSuggestionReplacesFirstItem(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();
        $result  = $service->updateSuggestion($this->tempDir, 0, 'New first');

        self::assertSame(['New first', 'Second'], $result);
    }

    public function testUpdateSuggestionReplacesLastItem(): void
    {
        $this->createSuggestionsFile("First\nSecond\nThird\n");

        $service = new PromptSuggestionsService();
        $result  = $service->updateSuggestion($this->tempDir, 2, 'New third');

        self::assertSame(['First', 'Second', 'New third'], $result);
    }

    public function testUpdateSuggestionTrimsText(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();
        $result  = $service->updateSuggestion($this->tempDir, 0, '  Trimmed  ');

        self::assertSame(['Trimmed', 'Second'], $result);
    }

    public function testUpdateSuggestionThrowsOnEmptyText(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $this->expectException(InvalidArgumentException::class);
        $service->updateSuggestion($this->tempDir, 0, '');
    }

    public function testUpdateSuggestionThrowsOnNegativeIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $this->expectException(OutOfRangeException::class);
        $service->updateSuggestion($this->tempDir, -1, 'Text');
    }

    public function testUpdateSuggestionThrowsOnOutOfBoundsIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $this->expectException(OutOfRangeException::class);
        $service->updateSuggestion($this->tempDir, 5, 'Text');
    }

    // ─── deleteSuggestion ──────────────────────────────────────────

    public function testDeleteSuggestionRemovesAtIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\nThird\n");

        $service = new PromptSuggestionsService();
        $result  = $service->deleteSuggestion($this->tempDir, 1);

        self::assertSame(['First', 'Third'], $result);
        self::assertSame("First\nThird\n", $this->readSuggestionsFile());
    }

    public function testDeleteSuggestionRemovesFirstItem(): void
    {
        $this->createSuggestionsFile("First\nSecond\nThird\n");

        $service = new PromptSuggestionsService();
        $result  = $service->deleteSuggestion($this->tempDir, 0);

        self::assertSame(['Second', 'Third'], $result);
    }

    public function testDeleteSuggestionRemovesLastItem(): void
    {
        $this->createSuggestionsFile("First\nSecond\nThird\n");

        $service = new PromptSuggestionsService();
        $result  = $service->deleteSuggestion($this->tempDir, 2);

        self::assertSame(['First', 'Second'], $result);
    }

    public function testDeleteSuggestionRemovesOnlyItem(): void
    {
        $this->createSuggestionsFile("Only one\n");

        $service = new PromptSuggestionsService();
        $result  = $service->deleteSuggestion($this->tempDir, 0);

        self::assertSame([], $result);
        self::assertSame("\n", $this->readSuggestionsFile());
    }

    public function testDeleteSuggestionThrowsOnNegativeIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $this->expectException(OutOfRangeException::class);
        $service->deleteSuggestion($this->tempDir, -1);
    }

    public function testDeleteSuggestionThrowsOnOutOfBoundsIndex(): void
    {
        $this->createSuggestionsFile("First\nSecond\n");

        $service = new PromptSuggestionsService();

        $this->expectException(OutOfRangeException::class);
        $service->deleteSuggestion($this->tempDir, 5);
    }
}
