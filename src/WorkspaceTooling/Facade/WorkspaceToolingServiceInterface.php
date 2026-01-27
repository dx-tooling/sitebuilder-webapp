<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use EtfsCodingAgent\Service\WorkspaceToolingServiceInterface as BaseWorkspaceToolingFacadeInterface;

interface WorkspaceToolingServiceInterface extends BaseWorkspaceToolingFacadeInterface
{
    public function runQualityChecks(string $pathToFolder): string;

    public function runTests(string $pathToFolder): string;

    public function runBuild(string $pathToFolder): string;

    /**
     * Suggest a commit message for the changes made during the edit session.
     *
     * The agent should call this after making file changes to suggest an optimal
     * git commit message. The message will be used when committing to the work branch.
     *
     * @param string $message The suggested commit message (50-72 chars, imperative mood)
     *
     * @return string Confirmation that the message was recorded
     */
    public function suggestCommitMessage(string $message): string;

    /**
     * Get the browser preview URL for a file in the workspace.
     *
     * Translates a sandbox path (e.g., /workspace/dist/page.html) to a browser-accessible
     * preview URL (e.g., /workspaces/{uuid}/dist/page.html).
     *
     * @param string $sandboxPath The path as seen from inside the sandbox (e.g., /workspace/dist/foo.html)
     *
     * @return string The browser preview URL, or an error message if context is not set
     */
    public function getPreviewUrl(string $sandboxPath): string;

    /**
     * Return the list of remote content asset URLs from all configured manifest URLs.
     * Fetches each manifest (JSON with "urls" array of absolute URIs), merges and deduplicates.
     * Returns JSON-encoded array of strings. Never throws; returns "[]" if no manifests or all fail.
     */
    public function listRemoteContentAssetUrls(): string;
}
