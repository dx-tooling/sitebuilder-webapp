<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure;

use App\LlmContentEditor\Facade\Dto\AgentEventDto;
use App\LlmContentEditor\Facade\Dto\ToolInputEntryDto;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps agent events to short, human-readable progress messages for the chat UI.
 * Used to make the agent feel "chatty" while working on large tasks.
 * Messages are translated according to the given locale (matches app UI language).
 */
final readonly class ProgressMessageResolver
{
    private const string DOMAIN = 'progress';

    public function __construct(
        private TranslatorInterface $translator
    ) {
    }

    public function messageForEvent(AgentEventDto $event, string $locale): ?string
    {
        return match ($event->kind) {
            'inference_start' => $this->trans('thinking', [], $locale),
            'tool_calling'    => $this->messageForToolCalling($event, $locale),
            default           => null,
        };
    }

    private function messageForToolCalling(AgentEventDto $event, string $locale): ?string
    {
        $toolName = $event->toolName ?? '';
        $path     = $this->getInputValue($event->toolInputs, 'path');
        $label    = $path !== null ? $this->basenameForDisplay($path) : null;

        $key = match ($toolName) {
            'get_workspace_rules'              => 'loading_workspace_rules',
            'get_folder_content'               => $label !== null ? 'listing_folder' : 'listing_folder_only',
            'get_file_content'                 => $label !== null ? 'reading_file' : 'reading_file_only',
            'get_file_info'                    => $label !== null ? 'checking_file' : 'checking_file_only',
            'get_file_lines'                   => $label !== null ? 'reading_lines' : 'reading_lines_only',
            'search_in_file'                   => $label !== null ? 'searching_in_file' : 'searching_in_file_only',
            'replace_in_file'                  => $label !== null ? 'editing_file' : 'editing_file_only',
            'apply_diff_to_file'               => $label !== null ? 'applying_changes' : 'applying_changes_only',
            'run_quality_checks'               => 'running_quality_checks',
            'run_tests'                        => 'running_tests',
            'run_build'                        => 'running_build',
            'list_remote_content_asset_urls'   => 'fetching_remote_asset_urls',
            'search_remote_content_asset_urls' => 'searching_remote_assets',
            'get_remote_asset_info'            => 'getting_remote_asset_info',
            'fetch_remote_web_page'            => 'fetching_remote_web_page',
            'suggest_commit_message'           => 'suggesting_commit_message',
            'get_preview_url'                  => $label !== null ? 'getting_preview_url' : 'getting_preview_url_only',
            default                            => $label !== null ? 'running_tool_on' : null,
        };

        if ($key === null) {
            return null;
        }

        $params = $label !== null ? ['%label%' => $label] : [];
        if ($key === 'running_tool_on' && $label !== null) {
            $params['%tool%']  = $toolName;
            $params['%label%'] = $label;
        }

        return $this->trans($key, $params, $locale);
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $id, array $params, string $locale): string
    {
        return $this->translator->trans($id, $params, self::DOMAIN, $locale);
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
