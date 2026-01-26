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
}
