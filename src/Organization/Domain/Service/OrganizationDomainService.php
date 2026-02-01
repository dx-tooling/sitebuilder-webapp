<?php

declare(strict_types=1);

namespace App\Organization\Domain\Service;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\UserRegistrationDto;
use App\Organization\Domain\Entity\Group;
use App\Organization\Domain\Entity\Invitation;
use App\Organization\Domain\Entity\Organization;
use App\Organization\Domain\Enum\AccessRight;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use App\Organization\Infrastructure\Repository\OrganizationRepositoryInterface;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Exception;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class OrganizationDomainService implements OrganizationDomainServiceInterface
{
    public function __construct(
        private TranslatorInterface             $translator,
        private EntityManagerInterface          $entityManager,
        private OrganizationRepositoryInterface $organizationRepository,
        private AccountFacadeInterface          $accountFacade,
        private EventDispatcherInterface        $eventDispatcher
    ) {
    }

    /** @return list<Organization> */
    public function getAllOrganizationsForUser(string $userId): array
    {
        return $this->organizationRepository->getAllOrganizationsForUser($userId);
    }

    public function userHasJoinedOrganizations(string $userId): bool
    {
        return $this->organizationRepository->userHasJoinedOrganizations($userId);
    }

    public function userHasJoinedOrganization(
        string $userId,
        string $organizationId
    ): bool {
        return $this->organizationRepository->userHasJoinedOrganization($userId, $organizationId);
    }

    public function userCanCreateOrManageOrganization(string $userId): bool
    {
        if (!$this->userHasJoinedOrganizations($userId)) {
            return true;
        }

        return false;
    }

    public function getOrganizationById(string $organizationId): ?Organization
    {
        return $this->organizationRepository->findById($organizationId);
    }

    /**
     * @throws Exception
     */
    public function createOrganization(string $userId, ?string $name = null): Organization
    {
        $organization = new Organization($userId);

        if ($name !== null && trim($name) !== '') {
            $organization->setName($name);
        }

        $adminGroup = new Group(
            $organization,
            'Administrators',
            [AccessRight::FULL_ACCESS],
            false
        );

        $this->entityManager->persist($adminGroup);

        $teamMemberGroup = new Group(
            $organization,
            'Team Members',
            [AccessRight::SEE_ORGANIZATION_GROUPS_AND_MEMBERS],
            true
        );

        $this->entityManager->persist($teamMemberGroup);

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return $organization;
    }

    public function renameOrganization(Organization $organization, ?string $name): void
    {
        $organization->setName($name);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();
    }

    public function emailCanBeInvitedToOrganization(
        string       $email,
        Organization $organization
    ): bool {
        $userId = $this->accountFacade->getAccountCoreIdByEmail($email);

        if ($userId === null) {
            return true;
        }

        $userAlreadyOwnsOrganization = $organization->getOwningUsersId() === $userId;
        if ($userAlreadyOwnsOrganization) {
            return false;
        }

        if ($this->userHasJoinedOrganization($userId, $organization->getId())) {
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function inviteEmailToOrganization(
        string       $email,
        Organization $organization
    ): ?Invitation {
        $email = trim(mb_strtolower($email));
        if (!$this->emailCanBeInvitedToOrganization($email, $organization)) {
            return null;
        }

        /** @var EntityRepository<Invitation> $repo */
        $repo = $this->entityManager->getRepository(Invitation::class);

        /** @var Invitation|null $invitation */
        $invitation = $repo->findOneBy(['email' => $email]);

        if ($invitation === null) {
            $invitation = new Invitation($organization, $email);
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
        } else {
            if ($invitation->getOrganization()->getId() !== $organization->getId()) {
                return null;
            }
        }

        // TODO: Send invitation email via presentation service
        // $this->organizationPresentationService->sendInvitationMail($invitation);

        return $invitation;
    }

    /**
     * @throws Exception
     */
    public function acceptInvitation(
        Invitation $invitation,
        ?string    $userId
    ): ?string {
        $organizationId = $invitation->getOrganization()->getId();

        // Check if user already exists by the invitation email
        $existingUserId = $this->accountFacade->getAccountCoreIdByEmail($invitation->getEmail());

        if ($existingUserId !== null) {
            // User already exists
            $userId = $existingUserId;

            // Check if already joined
            if ($this->userHasJoinedOrganization($userId, $organizationId)) {
                // Already a member, just clean up invitation
                $this->entityManager->remove($invitation);
                $this->entityManager->flush();

                return $userId;
            }
        } else {
            // New user - register them automatically (they'll get their own org via event)
            // They must set a password since they're being created via invitation
            $result = $this->accountFacade->register(
                new UserRegistrationDto(
                    $invitation->getEmail(),
                    null,  // No password - will be set later
                    true   // mustSetPassword = true
                )
            );

            if (!$result->isSuccess || $result->userId === null) {
                throw new Exception($result->errorMessage ?? 'Registration failed');
            }

            $userId = $result->userId;
        }

        // At this point, $userId is guaranteed to be non-null
        // Add user to the inviting organization
        $this->organizationRepository->addUserToOrganization(
            $userId,
            $organizationId
        );

        // Set the inviting organization as currently active
        $this->eventDispatcher->dispatch(
            new CurrentlyActiveOrganizationChangedSymfonyEvent(
                $organizationId,
                $userId
            )
        );

        // Add to default group
        $defaultGroup = $this->getDefaultGroupForNewMembers(
            $invitation->getOrganization()
        );
        $this->organizationRepository->addMemberToGroup($userId, $defaultGroup->getId());

        // Clean up invitation
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();

        return $userId;
    }

    public function getOrganizationName(Organization $organization): string
    {
        if ($organization->getName() === null) {
            return $this->translator->trans(
                'default_organization_name',
                [],
                'organization',
            );
        }

        return $organization->getName();
    }

    public function hasPendingInvitations(
        Organization $organization
    ): bool {
        return count($this->getPendingInvitations($organization)) > 0;
    }

    /** @return list<Invitation> */
    public function getPendingInvitations(
        Organization $organization
    ): array {
        /** @var EntityRepository<Invitation> $repo */
        $repo = $this->entityManager->getRepository(Invitation::class);

        /* @var list<Invitation> */
        return $repo->findBy(
            ['organization' => $organization],
            ['createdAt' => Criteria::DESC]
        );
    }

    public function resendInvitation(Invitation $invitation): void
    {
        // TODO: Send invitation email via presentation service
        // $this->organizationPresentationService->sendInvitationMail($invitation);
    }

    /**
     * @return list<string>
     */
    public function getAllUserIdsForOrganization(Organization $organization): array
    {
        return array_merge(
            [$organization->getOwningUsersId()],
            $this->organizationRepository->getJoinedUserIdsForOrganization($organization->getId())
        );
    }

    /** @return list<Group> */
    public function getGroups(
        Organization $organization
    ): array {
        /** @var EntityRepository<Group> $repo */
        $repo = $this->entityManager->getRepository(Group::class);

        /* @var list<Group> */
        return $repo->findBy(
            ['organization' => $organization],
            ['createdAt' => Criteria::DESC]
        );
    }

    /**
     * @return list<Group>
     *
     * @throws Exception
     */
    public function getGroupsOfUserForCurrentlyActiveOrganization(
        string $userId
    ): array {
        $currentlyActiveOrganizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($currentlyActiveOrganizationId === null) {
            throw new Exception('No currently active organization found for user with id ' . $userId);
        }

        $organization = $this->getOrganizationById($currentlyActiveOrganizationId);

        /** @var EntityRepository<Group> $repo */
        $repo = $this->entityManager->getRepository(Group::class);

        /** @var list<Group> $allGroups */
        $allGroups = $repo->findBy(
            ['organization' => $organization],
            ['createdAt' => Criteria::DESC]
        );

        /** @var list<Group> $foundGroups */
        $foundGroups = [];
        foreach ($allGroups as $group) {
            if ($this->organizationRepository->userIsMemberOfGroup($userId, $group->getId())) {
                $foundGroups[] = $group;
            }
        }

        return $foundGroups;
    }

    /**
     * @throws Exception
     */
    public function getDefaultGroupForNewMembers(
        Organization $organization
    ): Group {
        /** @var EntityRepository<Group> $repo */
        $repo = $this->entityManager->getRepository(Group::class);

        /** @var Group|null $group */
        $group = $repo->findOneBy(
            [
                'organization'           => $organization,
                'isDefaultForNewMembers' => true
            ]
        );

        if ($group === null) {
            throw new Exception(
                "Organization '{$organization->getId()}' does not have default group for new members."
            );
        }

        return $group;
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function getGroupMemberIds(Group $group): array
    {
        return $this->organizationRepository->getMemberIdsOfGroup($group->getId());
    }

    public function addUserToGroup(string $userId, Group $group): void
    {
        if (!$this->organizationRepository->userIsMemberOfGroup($userId, $group->getId())) {
            $this->organizationRepository->addMemberToGroup($userId, $group->getId());
        }
    }

    public function removeUserFromGroup(string $userId, Group $group): void
    {
        $this->organizationRepository->removeMemberFromGroup($userId, $group->getId());
    }

    public function getGroupById(string $groupId): ?Group
    {
        /* @var Group|null */
        return $this->entityManager->getRepository(Group::class)->find($groupId);
    }

    /**
     * @throws Exception
     */
    public function moveUserToAdministratorsGroup(
        string       $userId,
        Organization $organization
    ): void {
        $groups = $this->getGroups($organization);

        foreach ($groups as $group) {
            if ($group->isAdministratorsGroup()) {
                if (!$this->organizationRepository->userIsMemberOfGroup($userId, $group->getId())) {
                    $this->organizationRepository->addMemberToGroup($userId, $group->getId());
                }
            } else {
                $this->organizationRepository->removeMemberFromGroup($userId, $group->getId());
            }
        }
    }

    /**
     * @throws Exception
     */
    public function moveUserToTeamMembersGroup(
        string       $userId,
        Organization $organization
    ): void {
        $groups = $this->getGroups($organization);

        foreach ($groups as $group) {
            if ($group->isTeamMembersGroup()) {
                if (!$this->organizationRepository->userIsMemberOfGroup($userId, $group->getId())) {
                    $this->organizationRepository->addMemberToGroup($userId, $group->getId());
                }
            } else {
                $this->organizationRepository->removeMemberFromGroup($userId, $group->getId());
            }
        }
    }

    /**
     * @throws Exception
     */
    public function userHasAccessRight(
        string      $userId,
        AccessRight $accessRight
    ): bool {
        if ($this->currentlyActiveOrganizationIsOwnOrganization($userId)) {
            return true;
        }

        foreach ($this->getGroupsOfUserForCurrentlyActiveOrganization($userId) as $group) {
            foreach ($group->getAccessRights() as $groupAccessRight) {
                if ($groupAccessRight    === AccessRight::FULL_ACCESS
                    || $groupAccessRight === $accessRight
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function currentlyActiveOrganizationIsOwnOrganization(
        string $userId
    ): bool {
        $currentlyActiveOrganizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($currentlyActiveOrganizationId === null) {
            throw new Exception('No currently active organization found for user with id ' . $userId);
        }

        $organization = $this->getOrganizationById($currentlyActiveOrganizationId);

        return $organization !== null && $organization->getOwningUsersId() === $userId;
    }

    public function userCanSwitchOrganizations(string $userId): bool
    {
        return count($this->getAllOrganizationsForUser($userId)) > 1;
    }

    /** @return list<Organization> */
    public function organizationsUserCanSwitchTo(string $userId): array
    {
        return $this->getAllOrganizationsForUser($userId);
    }

    /**
     * @throws Exception
     */
    public function switchOrganization(
        string       $userId,
        Organization $organization
    ): void {
        foreach ($this->organizationsUserCanSwitchTo($userId) as $switchableOrganization) {
            if ($switchableOrganization->getId() === $organization->getId()) {
                $this->eventDispatcher->dispatch(
                    new CurrentlyActiveOrganizationChangedSymfonyEvent(
                        $organization->getId(),
                        $userId
                    )
                );

                return;
            }
        }
    }
}
