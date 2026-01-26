<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceTooling;

use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use PHPUnit\Framework\TestCase;

final class AgentExecutionContextTest extends TestCase
{
    public function testGetSuggestedCommitMessageReturnsNullByDefault(): void
    {
        $context = new AgentExecutionContext();

        self::assertNull($context->getSuggestedCommitMessage());
    }

    public function testSetSuggestedCommitMessageStoresMessage(): void
    {
        $context = new AgentExecutionContext();

        $context->setSuggestedCommitMessage('Add hero section to homepage');

        self::assertSame('Add hero section to homepage', $context->getSuggestedCommitMessage());
    }

    public function testSetSuggestedCommitMessageOverwritesPreviousMessage(): void
    {
        $context = new AgentExecutionContext();

        $context->setSuggestedCommitMessage('First message');
        $context->setSuggestedCommitMessage('Updated message');

        self::assertSame('Updated message', $context->getSuggestedCommitMessage());
    }

    public function testClearContextResetsCommitMessage(): void
    {
        $context = new AgentExecutionContext();

        $context->setContext('workspace-id', '/path/to/workspace', 'conversation-id', 'project-name', 'agent-image');
        $context->setSuggestedCommitMessage('Some commit message');
        $context->clearContext();

        self::assertNull($context->getSuggestedCommitMessage());
    }

    public function testSuggestedCommitMessageIsIndependentOfOtherContextFields(): void
    {
        $context = new AgentExecutionContext();

        // Set commit message without setting context
        $context->setSuggestedCommitMessage('Standalone commit message');

        self::assertSame('Standalone commit message', $context->getSuggestedCommitMessage());
        self::assertNull($context->getWorkspaceId());
    }
}
