<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ConversationLog;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

use function array_key_exists;

/**
 * Formats llm_conversation log records as clean, human-readable lines.
 *
 * Output: [2025-02-07 12:34:56] [<conversationId>] <message>
 */
final class ConversationLogFormatter extends LineFormatter
{
    private const string FORMAT      = "[%datetime%] [%extra.conversationId%] %message%\n";
    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct()
    {
        parent::__construct(self::FORMAT, self::DATE_FORMAT, true, true);
    }

    public function format(LogRecord $record): string
    {
        // Ensure conversationId is present to avoid raw placeholder in output
        if (!array_key_exists('conversationId', $record->extra)) {
            $record->extra['conversationId'] = '—';
        }

        return parent::format($record);
    }
}
