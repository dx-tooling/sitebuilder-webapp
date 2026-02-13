<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Facade;

use EtfsCodingAgent\Service\WorkspaceToolingServiceInterface as BaseWorkspaceToolingFacadeInterface;

interface WorkspaceToolingServiceInterface extends BaseWorkspaceToolingFacadeInterface
{
    /**
     * Start a shell command asynchronously.
     *
     * Returns a StreamingProcessInterface that can be polled for completion.
     * Output is streamed to any configured callback as it arrives.
     *
     * @param string $workingDirectory The working directory (e.g., /workspace)
     * @param string $command          The command to execute
     *
     * @return StreamingProcessInterface The running process wrapper
     */
    public function runShellCommandAsync(string $workingDirectory, string $command): StreamingProcessInterface;

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

    /**
     * Search remote content asset URLs using a regex pattern on filenames.
     * Fetches all URLs from configured manifests, then filters by pattern.
     * Returns JSON-encoded array of matching URLs. Never throws.
     *
     * @param string $regexPattern PCRE regex pattern (without delimiters) to match filenames
     *
     * @return string JSON array of matching URLs, or error JSON for invalid regex
     */
    public function searchRemoteContentAssetUrls(string $regexPattern): string;

    /**
     * Get information about a remote asset (e.g. image) by URL.
     * Returns JSON with url, width, height, mimeType, sizeInBytes (null when unknown).
     * On failure returns JSON object with an "error" key. Never throws.
     */
    public function getRemoteAssetInfo(string $url): string;

    /**
     * Get all workspace rules from .sitebuilder/rules/ folders.
     *
     * Scans the workspace for all .sitebuilder/rules/ directories (at any depth),
     * reads all .md files within them, and returns a JSON object where keys are
     * filenames without the .md extension and values are the file contents.
     *
     * @return string JSON object: {"rule-name": "content", ...}. Returns "{}" if no rules found.
     */
    public function getWorkspaceRules(): string;

    /**
     * Run build (npm run build) in the specified workspace.
     *
     * This method runs the build process in an isolated Docker container.
     * Unlike the agent's runBuild() method, this does not require an AgentExecutionContext
     * and takes explicit parameters.
     *
     * @param string $workspacePath absolute path to the workspace directory
     * @param string $agentImage    Docker image to use for the build
     *
     * @return string the build output
     */
    public function runBuildInWorkspace(string $workspacePath, string $agentImage): string;
}
