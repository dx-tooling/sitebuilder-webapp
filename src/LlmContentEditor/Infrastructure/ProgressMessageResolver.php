<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure;

use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;

/**
 * Maps agent events to short, human-readable progress messages for the chat UI.
 * Used to make the agent feel "chatty" while working on large tasks.
 */
final readonly class ProgressMessageResolver
{
    public function messageForEvent(AgentEventDto $event): ?string
    {
        return match ($event->kind) {
            'inference_start' => 'Thinking…',
            'tool_calling'    => $this->messageForToolCalling($event),
            default           => null,
        };
    }

    private function messageForToolCalling(AgentEventDto $event): ?string
    {
        $toolName = $event->toolName ?? '';
        $path     = $this->getInputValue($event->toolInputs, 'path');
        $label    = $path !== null ? $this->basenameForDisplay($path) : null;

        $message = match ($toolName) {
            'get_workspace_rules' => 'Loading workspace rules',
            'get_folder_content'  => $label !== null ? sprintf('Listing folder %s', $label) : 'Listing folder',
            'get_file_content'   => $label !== null ? sprintf('Reading %s', $label) : 'Reading file',
            'get_file_info'      => $label !== null ? sprintf('Checking file %s', $label) : 'Checking file',
            'get_file_lines'     => $label !== null ? sprintf('Reading lines from %s', $label) : 'Reading lines',
            'search_in_file'     => $label !== null ? sprintf('Searching in %s', $label) : 'Searching in file',
            'replace_in_file'    => $label !== null ? sprintf('Editing %s', $label) : 'Editing file',
            'apply_diff_to_file' => $label !== null ? sprintf('Applying changes to %s', $label) : 'Applying changes',
            'run_quality_checks' => 'Running quality checks',
            'run_tests'          => 'Running tests',
            'run_build'          => 'Running build',
            'list_remote_content_asset_urls' => 'Fetching remote asset URLs',
            'search_remote_content_asset_urls' => 'Searching remote assets',
            'get_remote_asset_info' => 'Getting remote asset info',
            'suggest_commit_message' => 'Suggesting commit message',
            'get_preview_url'    => $label !== null ? sprintf('Getting preview URL for %s', $label) : 'Getting preview URL',
            default              => $label !== null ? sprintf('Running %s on %s', $toolName, $label) : null,
        };

        return $message;
    }

    /**
     * @param list<ToolInputEntryDto>|null $inputs
     */
    private function getInputValue(?array $inputs, string $key): ?string
    {
        if ($inputs === null) {
            return null;
        }
        foreach ($inputs as $entry) {
            if ($entry->key === $key && $entry->value !== '') {
                return $entry->value;
            }
        }

        return null;
    }

    private function basenameForDisplay(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '…';
        }
        $base = basename($path);

        return $base !== '' ? $base : $path;
    }
}
