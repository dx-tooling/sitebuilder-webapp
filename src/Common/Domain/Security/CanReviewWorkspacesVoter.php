<?php

declare(strict_types=1);

namespace App\Common\Domain\Security;

use App\Account\Facade\AccountFacadeInterface;
use App\Organization\Facade\OrganizationFacadeInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter for checking if the current user can review workspaces.
 *
 * Access is granted if the user is:
 * - The owner of their currently active organization
 * - A member of the "Administrators" group (has FULL_ACCESS)
 * - A member of the "Reviewers" group (has REVIEW_WORKSPACES access right)
 *
 * @extends Voter<string, null>
 */
final class CanReviewWorkspacesVoter extends Voter
{
    /**
     * The attribute string to use with IsGranted or Security::isGranted().
     * Use this string directly instead of referencing this constant to avoid
     * cross-vertical architecture boundary violations.
     */
    public const string CAN_REVIEW_WORKSPACES = 'CAN_REVIEW_WORKSPACES';

    public function __construct(
        private readonly AccountFacadeInterface      $accountFacade,
        private readonly OrganizationFacadeInterface $organizationFacade
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::CAN_REVIEW_WORKSPACES;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        $email  = $user->getUserIdentifier();
        $userId = $this->accountFacade->getAccountCoreIdByEmail($email);

        if ($userId === null) {
            return false;
        }

        return $this->organizationFacade->userCanReviewWorkspaces($userId);
    }
}
