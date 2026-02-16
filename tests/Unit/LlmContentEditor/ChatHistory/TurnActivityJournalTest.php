<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor\ChatHistory;

use App\LlmContentEditor\Infrastructure\ChatHistory\TurnActivityJournal;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

final class TurnActivityJournalTest extends TestCase
{
    public function testEmptyJournalReturnsEmptyString(): void
    {
        $journal = new TurnActivityJournal();
        self::assertSame('', $journal->getSummary());
        self::assertSame(0, $journal->count());
    }

    public function testSingleToolCallIsRecorded(): void
    {
        $journal = new TurnActivityJournal();

        $tool = Tool::make('list_directory', 'List directory')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace/src'])
            ->setResult('file1.html, file2.html, file3.css');

        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        self::assertSame(1, $journal->count());

        $summary = $journal->getSummary();
        self::assertStringContainsString('1. [list_directory]', $summary);
        self::assertStringContainsString('path="/workspace/src"', $summary);
        self::assertStringContainsString('file1.html, file2.html, file3.css', $summary);
    }

    public function testMultipleToolCallsAreNumberedSequentially(): void
    {
        $journal = new TurnActivityJournal();

        $tool1 = Tool::make('list_directory', 'List')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace'])
            ->setResult('src/, dist/, package.json');

        $tool2 = Tool::make('get_file_content', 'Read')
            ->setCallId('call_2')
            ->setInputs(['path' => '/workspace/src/index.html'])
            ->setResult('<html><body>Hello</body></html>');

        $journal->recordToolResults(new ToolCallResultMessage([$tool1]));
        $journal->recordToolResults(new ToolCallResultMessage([$tool2]));

        self::assertSame(2, $journal->count());

        $summary = $journal->getSummary();
        self::assertStringContainsString('1. [list_directory]', $summary);
        self::assertStringContainsString('2. [get_file_content]', $summary);
    }

    public function testMultipleToolsInSingleMessage(): void
    {
        $journal = new TurnActivityJournal();

        $tool1 = Tool::make('get_file_content', 'Read')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace/a.html'])
            ->setResult('content a');

        $tool2 = Tool::make('get_file_content', 'Read')
            ->setCallId('call_2')
            ->setInputs(['path' => '/workspace/b.html'])
            ->setResult('content b');

        $journal->recordToolResults(new ToolCallResultMessage([$tool1, $tool2]));

        self::assertSame(2, $journal->count());

        $summary = $journal->getSummary();
        self::assertStringContainsString('1. [get_file_content] path="/workspace/a.html"', $summary);
        self::assertStringContainsString('2. [get_file_content] path="/workspace/b.html"', $summary);
    }

    public function testToolWithNoInputsFormatsCleanly(): void
    {
        $journal = new TurnActivityJournal();

        $tool = Tool::make('get_workspace_rules', 'Get rules')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult('{"styleguide": "use TailwindCSS"}');

        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        $summary = $journal->getSummary();
        // Should not have dangling space between name and arrow
        self::assertStringContainsString('1. [get_workspace_rules] →', $summary);
    }

    public function testLongParameterValuesAreTruncated(): void
    {
        $journal = new TurnActivityJournal();

        $longPath = '/workspace/' . str_repeat('a', 200);
        $tool     = Tool::make('get_file_content', 'Read')
            ->setCallId('call_1')
            ->setInputs(['path' => $longPath])
            ->setResult('file content');

        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        $summary = $journal->getSummary();
        self::assertStringContainsString('...', $summary);
        // The truncated value should be shorter than the original
        self::assertStringNotContainsString($longPath, $summary);
    }

    public function testLongResultsAreTruncated(): void
    {
        $journal = new TurnActivityJournal();

        $longResult = str_repeat('line of content; ', 100);
        $tool       = Tool::make('get_file_content', 'Read')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace/big.html'])
            ->setResult($longResult);

        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        $summary = $journal->getSummary();
        self::assertStringContainsString('...', $summary);
        self::assertStringNotContainsString($longResult, $summary);
    }

    public function testToolWithNullResultShowsNoOutput(): void
    {
        $journal = new TurnActivityJournal();

        // Tool with no result set (result is null, getResult returns null cast to string)
        $tool = Tool::make('some_tool', 'Does something')
            ->setCallId('call_1')
            ->setInputs(['param' => 'value']);
        // Do NOT call setResult — result stays null

        // We need to handle this gracefully. The Tool::getResult() returns $this->result
        // which is null by default. We cast to string in the journal, giving "".
        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        $summary = $journal->getSummary();
        self::assertStringContainsString('(no output)', $summary);
    }

    public function testJournalCollapsesOldEntriesWhenMaxSizeExceeded(): void
    {
        $journal = new TurnActivityJournal();

        // Add many entries to exceed the 4000 char limit
        for ($i = 0; $i < 100; ++$i) {
            $tool = Tool::make('get_file_content', 'Read')
                ->setCallId('call_' . $i)
                ->setInputs(['path' => '/workspace/src/page-' . $i . '.html'])
                ->setResult('<!DOCTYPE html><html><head><title>Page ' . $i . '</title></head><body>Content</body></html>');

            $journal->recordToolResults(new ToolCallResultMessage([$tool]));
        }

        self::assertSame(100, $journal->count());

        $summary = $journal->getSummary();
        self::assertLessThanOrEqual(4200, mb_strlen($summary), 'Summary should be capped near the max length');
        self::assertStringContainsString('... and', $summary);
        self::assertStringContainsString('earlier actions', $summary);

        // The most recent entries should still be present
        self::assertStringContainsString('[get_file_content]', $summary);
    }

    public function testEntriesAccumulateAcrossMultipleRecordCalls(): void
    {
        $journal = new TurnActivityJournal();

        // Simulate multiple rounds of tool calls (as in the agentic loop)
        $tool1 = Tool::make('list_directory', 'List')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace'])
            ->setResult('src/, dist/');
        $journal->recordToolResults(new ToolCallResultMessage([$tool1]));

        $tool2 = Tool::make('get_file_content', 'Read')
            ->setCallId('call_2')
            ->setInputs(['path' => '/workspace/src/index.html'])
            ->setResult('<html>content</html>');
        $journal->recordToolResults(new ToolCallResultMessage([$tool2]));

        $tool3 = Tool::make('write_to_file', 'Write')
            ->setCallId('call_3')
            ->setInputs(['path' => '/workspace/src/craftsmen.html', 'content' => '<html>new page</html>'])
            ->setResult('The file has been successfully written.');
        $journal->recordToolResults(new ToolCallResultMessage([$tool3]));

        self::assertSame(3, $journal->count());

        $summary = $journal->getSummary();
        self::assertStringContainsString('1. [list_directory]', $summary);
        self::assertStringContainsString('2. [get_file_content]', $summary);
        self::assertStringContainsString('3. [write_to_file]', $summary);

        // Verify parameter formatting
        self::assertStringContainsString('path="/workspace/src/craftsmen.html"', $summary);
    }

    public function testMultipleParametersAreAllShown(): void
    {
        $journal = new TurnActivityJournal();

        $tool = Tool::make('write_to_file', 'Write')
            ->setCallId('call_1')
            ->setInputs([
                'path'    => '/workspace/file.html',
                'content' => '<html>hello</html>',
            ])
            ->setResult('Written successfully.');

        $journal->recordToolResults(new ToolCallResultMessage([$tool]));

        $summary = $journal->getSummary();
        self::assertStringContainsString('path="/workspace/file.html"', $summary);
        self::assertStringContainsString('content="<html>hello</html>"', $summary);
    }
}
