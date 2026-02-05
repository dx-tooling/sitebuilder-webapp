<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;

use function mb_strtolower;
use function mb_substr;
use function str_replace;

/**
 * Generates human-friendly workspace branch names.
 *
 * Format: <YYYY-MM-DD H:i:s>-usermailATdomainDOTtld-SHORTWORKSPACEID
 */
final class BranchNameGenerator implements BranchNameGeneratorInterface
{
    public function generate(string $workspaceId, string $userEmail): string
    {
        $timestamp = DateAndTimeService::getDateTimeImmutable()->format('Y-m-d H:i:s');
        $sanitized = $this->sanitizeEmailForBranchName($userEmail);
        $shortId   = mb_substr($workspaceId, 0, 8);

        return $timestamp . '-' . $sanitized . '-' . $shortId;
    }

    /**
     * Sanitize email for use in branch name: @ → AT, . → DOT, lowercase.
     */
    public function sanitizeEmailForBranchName(string $email): string
    {
        $lower = mb_strtolower($email, 'UTF-8');

        return str_replace(['@', '.'], ['AT', 'DOT'], $lower);
    }
}
