<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Infrastructure\ProgressMessageResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProgressMessageResolverTest extends TestCase
{
    private function createResolver(TranslatorInterface $translator): ProgressMessageResolver
    {
        return new ProgressMessageResolver($translator);
    }

    public function testInferenceStartReturnsTranslatedThinking(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('thinking', [], 'progress', 'en')
            ->willReturn('Thinking…');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('inference_start');

        self::assertSame('Thinking…', $resolver->messageForEvent($event, 'en'));
    }

    public function testInferenceStartUsesGivenLocale(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('thinking', [], 'progress', 'de')
            ->willReturn('Denke nach…');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('inference_start');

        self::assertSame('Denke nach…', $resolver->messageForEvent($event, 'de'));
    }

    public function testToolCallingGetFileContentWithPathTranslatesWithLabel(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('reading_file', ['%label%' => 'about.html'], 'progress', 'en')
            ->willReturn('Reading about.html');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto(
            'tool_calling',
            'get_file_content',
            [new ToolInputEntryDto('path', '/workspace/dist/about.html')],
        );

        self::assertSame('Reading about.html', $resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingReplaceInFileUsesBasenameForDisplay(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('editing_file', ['%label%' => 'landing-1.html'], 'progress', 'en')
            ->willReturn('Editing landing-1.html');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto(
            'tool_calling',
            'replace_in_file',
            [new ToolInputEntryDto('path', '/workspace/dist/landing-1.html')],
        );

        self::assertSame('Editing landing-1.html', $resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingRunBuildWithoutPathTranslatesWithoutLabel(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('running_build', [], 'progress', 'en')
            ->willReturn('Running build');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('tool_calling', 'run_build', []);

        self::assertSame('Running build', $resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingGetWorkspaceRulesTranslates(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('loading_workspace_rules', [], 'progress', 'en')
            ->willReturn('Loading workspace rules');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('tool_calling', 'get_workspace_rules');

        self::assertSame('Loading workspace rules', $resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingFetchRemoteWebPageTranslates(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('fetching_remote_web_page', [], 'progress', 'en')
            ->willReturn('Fetching remote web page');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('tool_calling', 'fetch_remote_web_page');

        self::assertSame('Fetching remote web page', $resolver->messageForEvent($event, 'en'));
    }

    public function testInferenceStopReturnsNull(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('inference_stop');

        self::assertNull($resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingUnknownToolWithPathReturnsRunningToolOn(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('running_tool_on', ['%tool%' => 'custom_tool', '%label%' => 'foo.txt'], 'progress', 'en')
            ->willReturn('Running custom_tool on foo.txt');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto(
            'tool_calling',
            'custom_tool',
            [new ToolInputEntryDto('path', '/workspace/foo.txt')],
        );

        self::assertSame('Running custom_tool on foo.txt', $resolver->messageForEvent($event, 'en'));
    }

    public function testToolCallingUnknownToolWithoutPathReturnsNull(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $resolver = $this->createResolver($translator);
        $event    = new AgentEventDto('tool_calling', 'unknown_tool', []);

        self::assertNull($resolver->messageForEvent($event, 'en'));
    }
}
