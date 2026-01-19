<?php

declare(strict_types=1);

namespace App\ContentProjectEditorChat\Facade;

use App\ContentProjectEditorChat\Facade\Dto\ChatMessageDto;
use App\ContentProjectEditorChat\Facade\Dto\StreamHandleDto;

interface ContentProjectEditorChatFacadeInterface
{
    public function sendMessage(string $sessionId, string $userMessage): void;

    /**
     * @return list<ChatMessageDto>
     */
    public function getChatHistory(string $sessionId): array;

    public function getStreamHandle(string $sessionId): ?StreamHandleDto;

    public function clearChatHistory(string $sessionId): void;
}
