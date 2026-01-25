<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Adapter\GitHubAdapterInterface;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        private readonly GitHubUrlServiceInterface  $gitHubUrlService,
        private readonly EntityManagerInterface     $entityManager,
        private readonly LoggerInterface            $logger,
    ) {
    }

    /**
     * Commit all changes and push to remote.
     *
     * @param Workspace   $workspace       the workspace to commit changes for
     * @param string      $message         the commit message
     * @param string      $authorEmail     the author's email address for the commit
     * @param string|null $conversationId  optional conversation ID to link in commit message
     * @param string|null $conversationUrl optional conversation URL to link in commit message
     */
    public function commitAndPush(
        Workspace $workspace,
        string    $message,
        string    $authorEmail,
        ?string   $conversationId = null,
        ?string   $conversationUrl = null
    ): void {
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

        // Enhance commit message with user info and conversation link
        $enhancedMessage = $this->enhanceCommitMessage($message, $authorEmail, $conversationId, $conversationUrl);

        // Commit all changes
        $this->logger->debug('Committing changes', [
            'workspaceId' => $workspace->getId(),
            'message'     => $enhancedMessage,
            'authorEmail' => $authorEmail,
        ]);
        $this->gitAdapter->commitAll($workspacePath, $enhancedMessage, $authorName, $authorEmail);

        // Push to remote
        $this->logger->debug('Pushing to remote', [
            'workspaceId' => $workspace->getId(),
            'branchName'  => $branchName,
        ]);
        $this->gitAdapter->push($workspacePath, $branchName, $projectInfo->githubToken);
    }

    /**
     * Find the pull request URL for a workspace branch, if it exists.
     *
     * @return string|null the PR URL if found, null otherwise
     */
    public function findPullRequestUrl(Workspace $workspace): ?string
    {
        $branchName = $workspace->getBranchName();

        if ($branchName === null) {
            return null;
        }

        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());
        $repoInfo    = $this->gitHubUrlService->parseGitUrl($projectInfo->gitUrl);
        $owner       = $repoInfo->owner;
        $repo        = $repoInfo->repo;

        return $this->gitHubAdapter->findPullRequestForBranch(
            $owner,
            $repo,
            $branchName,
            $projectInfo->githubToken
        );
    }

    /**
     * Ensure a pull request exists for the workspace branch.
     * Creates one if it doesn't exist.
     *
     * @param Workspace   $workspace       the workspace
     * @param string|null $conversationId  optional conversation ID to link in PR
     * @param string|null $conversationUrl optional conversation URL to link in PR
     * @param string|null $userEmail       optional user email to include in PR
     *
     * @return string the PR URL
     */
    public function ensurePullRequest(
        Workspace $workspace,
        ?string   $conversationId = null,
        ?string   $conversationUrl = null,
        ?string   $userEmail = null
    ): string {
        $branchName = $workspace->getBranchName();

        if ($branchName === null) {
            throw new RuntimeException('Workspace has no branch name set');
        }

        $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());
        $repoInfo    = $this->gitHubUrlService->parseGitUrl($projectInfo->gitUrl);
        $owner       = $repoInfo->owner;
        $repo        = $repoInfo->repo;

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

            // Cache the PR URL in the workspace entity
            $workspace->setPullRequestUrl($existingPrUrl);
            $this->entityManager->flush();

            return $existingPrUrl;
        }

        // Check if branch has any differences from main before attempting to create PR
        $workspacePath = $this->getWorkspacePath($workspace);
        if (!$this->gitAdapter->hasBranchDifferences($workspacePath, $branchName, 'main')) {
            $this->logger->warning('Cannot create PR: branch has no differences from main', [
                'workspaceId' => $workspace->getId(),
                'branchName'  => $branchName,
            ]);

            throw new RuntimeException('Cannot create pull request: branch has no commits or changes compared to main branch');
        }

        // Create new PR with enhanced title and body
        $title = $this->buildPrTitle($workspace, $projectInfo->name);
        $body  = $this->buildPrBody($workspace, $conversationId, $conversationUrl, $userEmail);

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

        // Cache the PR URL in the workspace entity
        $workspace->setPullRequestUrl($prUrl);
        $this->entityManager->flush();

        return $prUrl;
    }

    private function getWorkspacePath(Workspace $workspace): string
    {
        return $this->workspaceRoot . '/' . $workspace->getId();
    }

    /**
     * Enhance commit message with user info and conversation link.
     */
    private function enhanceCommitMessage(
        string  $baseMessage,
        string  $authorEmail,
        ?string $conversationId,
        ?string $conversationUrl
    ): string {
        $lines = [$baseMessage];

        // Add user info
        $lines[] = '';
        $lines[] = 'SiteBuilder user: ' . $authorEmail;

        // Add conversation link if available
        if ($conversationId !== null) {
            if ($conversationUrl !== null) {
                $lines[] = 'Conversation: ' . $conversationUrl;
            } else {
                $lines[] = 'Conversation ID: ' . $conversationId;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build PR title with project/workspace info.
     */
    private function buildPrTitle(Workspace $workspace, string $projectName): string
    {
        return sprintf('SiteBuilder: %s (workspace %s)', $projectName, substr($workspace->getId() ?? '', 0, 8));
    }

    /**
     * Build PR body with conversation and user info.
     */
    private function buildPrBody(
        Workspace $workspace,
        ?string   $conversationId,
        ?string   $conversationUrl,
        ?string   $userEmail
    ): string {
        $lines = ['Automated pull request from SiteBuilder workspace.'];

        if ($userEmail !== null) {
            $lines[] = '';
            $lines[] = '**SiteBuilder user:** ' . $userEmail;
        }

        if ($conversationId !== null) {
            $lines[] = '';
            if ($conversationUrl !== null) {
                $lines[] = '**Conversation:** [' . $conversationId . '](' . $conversationUrl . ')';
            } else {
                $lines[] = '**Conversation ID:** ' . $conversationId;
            }
        }

        $lines[] = '';
        $lines[] = '**Workspace ID:** ' . ($workspace->getId() ?? 'unknown');

        return implode("\n", $lines);
    }
}
