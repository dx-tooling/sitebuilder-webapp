<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitHubAdapterInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function preg_match;

/**
 * Handles git operations for workspaces:
 * - Commit and push changes
 * - Ensure pull requests exist.
 */
final class WorkspaceGitService
{
    public function __construct(
        #[Autowire(param: 'workspace_mgmt.workspace_root')]
        private readonly string                     $workspaceRoot,
        private readonly GitAdapterInterface        $gitAdapter,
        private readonly GitHubAdapterInterface     $gitHubAdapter,
        private readonly ProjectMgmtFacadeInterface $projectMgmtFacade,
        private readonly LoggerInterface            $logger,
    ) {
    }

    /**
     * Commit all changes and push to remote.
     *
     * @param Workspace $workspace   the workspace to commit changes for
     * @param string    $message     the commit message
     * @param string    $authorEmail the author's email address for the commit
     */
    public function commitAndPush(Workspace $workspace, string $message, string $authorEmail): void
    {
        $workspacePath = $this->getWorkspacePath($workspace);
        $branchName    = $workspace->getBranchName();

        if ($branchName === null) {
            throw new RuntimeException('Workspace has no branch name set');
        }

        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());

        // Check if there are changes to commit
        if (!$this->gitAdapter->hasChanges($workspacePath)) {
            $this->logger->debug('No changes to commit', [
                'workspaceId' => $workspace->getId(),
            ]);

            return;
        }

        // Build author name in format "SiteBuilder user <email>"
        $authorName = 'SiteBuilder user ' . $authorEmail;

        // Commit all changes
        $this->logger->debug('Committing changes', [
            'workspaceId' => $workspace->getId(),
            'message'     => $message,
            'authorEmail' => $authorEmail,
        ]);
        $this->gitAdapter->commitAll($workspacePath, $message, $authorName, $authorEmail);

        // Push to remote
        $this->logger->debug('Pushing to remote', [
            'workspaceId' => $workspace->getId(),
            'branchName'  => $branchName,
        ]);
        $this->gitAdapter->push($workspacePath, $branchName, $projectInfo->githubToken);
    }

    /**
     * Ensure a pull request exists for the workspace branch.
     * Creates one if it doesn't exist.
     *
     * @return string the PR URL
     */
    public function ensurePullRequest(Workspace $workspace): string
    {
        $branchName = $workspace->getBranchName();

        if ($branchName === null) {
            throw new RuntimeException('Workspace has no branch name set');
        }

        $projectInfo    = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());
        [$owner, $repo] = $this->parseGitUrl($projectInfo->gitUrl);

        // Check if PR already exists
        $existingPrUrl = $this->gitHubAdapter->findPullRequestForBranch(
            $owner,
            $repo,
            $branchName,
            $projectInfo->githubToken
        );

        if ($existingPrUrl !== null) {
            $this->logger->debug('Found existing PR', [
                'workspaceId' => $workspace->getId(),
                'prUrl'       => $existingPrUrl,
            ]);

            return $existingPrUrl;
        }

        // Create new PR
        $title = 'Changes from workspace ' . $workspace->getId();
        $body  = 'Automated pull request from SiteBuilder workspace.';

        $prUrl = $this->gitHubAdapter->createPullRequest(
            $owner,
            $repo,
            $branchName,
            $title,
            $body,
            $projectInfo->githubToken
        );

        $this->logger->info('Created new PR', [
            'workspaceId' => $workspace->getId(),
            'prUrl'       => $prUrl,
        ]);

        return $prUrl;
    }

    private function getWorkspacePath(Workspace $workspace): string
    {
        return $this->workspaceRoot . '/' . $workspace->getId();
    }

    /**
     * Parse a git URL to extract owner and repo.
     *
     * @return array{0: string, 1: string} [owner, repo]
     */
    private function parseGitUrl(string $gitUrl): array
    {
        // Handle: https://github.com/owner/repo.git
        if (preg_match('#github\.com[/:]([^/]+)/([^/.]+)(?:\.git)?$#', $gitUrl, $matches)) {
            return [$matches[1], $matches[2]];
        }

        throw new RuntimeException('Unable to parse git URL: ' . $gitUrl);
    }
}
