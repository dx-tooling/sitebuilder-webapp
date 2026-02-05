<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

/**
 * Generates human-friendly workspace branch names.
 */
interface BranchNameGeneratorInterface
{
    /**
     * Generate a branch name in format: &lt;YYYY-MM-DD H:i:s&gt;-usermailATdomainDOTtld-SHORTWORKSPACEID.
     */
    public function generate(string $workspaceId, string $userEmail): string;
}
