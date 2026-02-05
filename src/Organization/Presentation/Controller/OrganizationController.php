<?php

declare(strict_types=1);

namespace App\Organization\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\AccountInfoDto;
use App\Organization\Domain\Entity\Invitation;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Facade\SymfonyEvent\CurrentlyActiveOrganizationChangedSymfonyEvent;
use App\Organization\Presentation\Service\OrganizationPresentationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationDomainServiceInterface       $organizationDomainService,
        private readonly OrganizationPresentationServiceInterface $organizationPresentationService,
        private readonly EventDispatcherInterface                 $eventDispatcher,
        private readonly EntityManagerInterface                   $entityManager,
        private readonly AccountFacadeInterface                   $accountFacade,
        private readonly Security                                 $security,
        private readonly TranslatorInterface                      $translator
    ) {
    }

    private function getAccountInfo(UserInterface $user): AccountInfoDto
    {
        $accountInfo = $this->accountFacade->getAccountInfoByEmail($user->getUserIdentifier());

        if ($accountInfo === null) {
            throw new RuntimeException('Account not found for authenticated user');
        }

        return $accountInfo;
    }

    #[Route(
        path: '/organization',
        name: 'organization.presentation.dashboard',
        methods: [Request::METHOD_GET]
    )]
    #[IsGranted('ROLE_USER')]
    public function dashboardAction(): Response
    {
        /** @var UserInterface|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo                   = $this->getAccountInfo($user);
        $userId                        = $accountInfo->id;
        $organizationName              = null;
        $currentOrganization           = null;
        $currentlyActiveOrganizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($currentlyActiveOrganizationId !== null) {
            $currentOrganization = $this->organizationDomainService->getOrganizationById($currentlyActiveOrganizationId);
            if ($currentOrganization !== null) {
                $organizationName = $this->organizationDomainService->getOrganizationName($currentOrganization);
            }
        }

        // Get all organizations for switching
        $allOrganizations = $this->organizationDomainService->getAllOrganizationsForUser($userId);

        // Check if user can rename/invite for current organization (owner of active org)
        $canRenameCurrentOrganization   = false;
        $canInviteToCurrentOrganization = false;
        $currentOrganizationRawName     = null;
        $pendingInvitations             = [];
        $members                        = [];

        if ($currentOrganization !== null) {
            $isOwner                        = $currentOrganization->getOwningUsersId() === $userId;
            $canRenameCurrentOrganization   = $isOwner;
            $canInviteToCurrentOrganization = $isOwner;
            $currentOrganizationRawName     = $currentOrganization->getName();

            // Get pending invitations if owner
            if ($isOwner) {
                $invitations = $this->organizationDomainService->getPendingInvitations($currentOrganization);
                foreach ($invitations as $invitation) {
                    $pendingInvitations[] = [
                        'id'        => $invitation->getId(),
                        'email'     => $invitation->getEmail(),
                        'createdAt' => $invitation->getCreatedAt(),
                    ];
                }
            }

            // Get members of the organization
            $memberIds   = $this->organizationDomainService->getAllUserIdsForOrganization($currentOrganization);
            $ownerUserId = $currentOrganization->getOwningUsersId();

            // Include owner in the list if not already
            if (!in_array($ownerUserId, $memberIds, true)) {
                $memberIds[] = $ownerUserId;
            }

            // Get all groups for this organization
            $orgGroups = $this->organizationDomainService->getGroups($currentOrganization);

            // Build a map of userId -> groupIds for quick lookup
            /** @var array<string, list<string>> $userGroupMap */
            $userGroupMap = [];
            foreach ($orgGroups as $group) {
                $groupMemberIds = $this->organizationDomainService->getGroupMemberIds($group);
                foreach ($groupMemberIds as $memberId) {
                    if (!array_key_exists($memberId, $userGroupMap)) {
                        $userGroupMap[$memberId] = [];
                    }
                    $userGroupMap[$memberId][] = $group->getId();
                }
            }

            $memberInfos = $this->accountFacade->getAccountInfoByIds($memberIds);
            foreach ($memberInfos as $memberInfo) {
                $members[] = [
                    'id'            => $memberInfo->id,
                    'displayName'   => $memberInfo->email,
                    'email'         => $memberInfo->email,
                    'isOwner'       => $memberInfo->id === $ownerUserId,
                    'isCurrentUser' => $memberInfo->id === $userId,
                    'joinedAt'      => $memberInfo->createdAt,
                    'groupIds'      => $userGroupMap[$memberInfo->id] ?? [],
                ];
            }

            // Sort: owner first, then by display name
            usort($members, function (array $a, array $b): int {
                if ($a['isOwner'] !== $b['isOwner']) {
                    return $a['isOwner'] ? -1 : 1;
                }

                return strcasecmp($a['displayName'], $b['displayName']);
            });
        }

        // Build organization list with names
        $organizations = [];
        foreach ($allOrganizations as $org) {
            $organizations[] = [
                'id'       => $org->getId(),
                'name'     => $this->organizationDomainService->getOrganizationName($org),
                'isOwned'  => $org->getOwningUsersId()                       === $userId,
                'isActive' => $currentOrganization !== null && $org->getId() === $currentOrganization->getId(),
            ];
        }

        // Build groups list
        $groups = [];
        if ($currentOrganization !== null) {
            $orgGroups = $this->organizationDomainService->getGroups($currentOrganization);
            foreach ($orgGroups as $group) {
                $groups[] = [
                    'id'        => $group->getId(),
                    'name'      => $group->getName(),
                    'isDefault' => $group->isDefaultForNewMembers(),
                ];
            }
        }

        return $this->render('@organization.presentation/organization_dashboard.html.twig', [
            'organizationName'               => $organizationName,
            'organizations'                  => $organizations,
            'currentOrganizationId'          => $currentlyActiveOrganizationId,
            'canRenameCurrentOrganization'   => $canRenameCurrentOrganization,
            'canInviteToCurrentOrganization' => $canInviteToCurrentOrganization,
            'currentOrganizationRawName'     => $currentOrganizationRawName,
            'pendingInvitations'             => $pendingInvitations,
            'members'                        => $members,
            'groups'                         => $groups,
            'isOrganizationOwner'            => $currentOrganization !== null && $currentOrganization->getOwningUsersId() === $userId,
        ]);
    }

    #[Route(
        path: '/organization/create',
        name: 'organization.presentation.create',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function createAction(Request $request): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo = $this->getAccountInfo($user);
        $userId      = $accountInfo->id;

        try {
            $name = $request->request->get('name');
            $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

            if ($name === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.missing_name', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $organization = $this->organizationDomainService->createOrganization($userId, $name);

            // Switch to the new organization
            $this->eventDispatcher->dispatch(
                new CurrentlyActiveOrganizationChangedSymfonyEvent(
                    $organization->getId(),
                    $userId
                )
            );

            $displayName = $this->organizationDomainService->getOrganizationName($organization);
            $this->addFlash('success', $this->translator->trans('flash.success.created', ['name' => $displayName], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.create_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/rename',
        name: 'organization.presentation.rename',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function renameAction(Request $request): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo    = $this->getAccountInfo($user);
        $userId         = $accountInfo->id;
        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_active_organization_rename', [], 'organization'));

            return $this->redirectToRoute('organization.presentation.dashboard');
        }

        try {
            $organization = $this->organizationDomainService->getOrganizationById($organizationId);

            if ($organization === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.organization_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Only owner can rename
            if ($organization->getOwningUsersId() !== $userId) {
                $this->addFlash('error', $this->translator->trans('flash.error.owner_only_rename', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $name = $request->request->get('name');
            $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

            $this->organizationDomainService->renameOrganization($organization, $name);

            $displayName = $this->organizationDomainService->getOrganizationName($organization);
            $this->addFlash('success', $this->translator->trans('flash.success.renamed', ['name' => $displayName], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.rename_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/switch/{organizationId}',
        name: 'organization.presentation.switch',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function switchAction(Request $request, string $organizationId): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo = $this->getAccountInfo($user);
        $userId      = $accountInfo->id;

        try {
            $organization = $this->organizationDomainService->getOrganizationById($organizationId);

            if ($organization === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.organization_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $this->organizationDomainService->switchOrganization($userId, $organization);

            $organizationName = $this->organizationDomainService->getOrganizationName($organization);
            $this->addFlash('success', $this->translator->trans('flash.success.switched', ['name' => $organizationName], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.switch_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/invite',
        name: 'organization.presentation.invite',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function inviteAction(Request $request): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo    = $this->getAccountInfo($user);
        $userId         = $accountInfo->id;
        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_active_organization_invite', [], 'organization'));

            return $this->redirectToRoute('organization.presentation.dashboard');
        }

        try {
            $organization = $this->organizationDomainService->getOrganizationById($organizationId);

            if ($organization === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.organization_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Only owner can invite
            if ($organization->getOwningUsersId() !== $userId) {
                $this->addFlash('error', $this->translator->trans('flash.error.owner_only_invite', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $email = $request->request->get('email');
            $email = is_string($email) ? trim(mb_strtolower($email)) : '';

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', $this->translator->trans('flash.error.invalid_email', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Check if can be invited
            if (!$this->organizationDomainService->emailCanBeInvitedToOrganization($email, $organization)) {
                $this->addFlash('error', $this->translator->trans('flash.error.email_already_member', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $invitation = $this->organizationDomainService->inviteEmailToOrganization($email, $organization);

            if ($invitation === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.invitation_failed', [], 'organization'));
            } else {
                // Send the invitation email
                $this->organizationPresentationService->sendInvitationMail($invitation);
                $this->addFlash('success', $this->translator->trans('flash.success.invitation_sent', ['email' => $email], 'organization'));
            }
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.invite_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/invitation/{invitationId}/resend',
        name: 'organization.presentation.resend_invitation',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function resendInvitationAction(Request $request, string $invitationId): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo = $this->getAccountInfo($user);
        $userId      = $accountInfo->id;

        try {
            $invitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);

            if ($invitation === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.invitation_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $organization = $invitation->getOrganization();

            // Only owner can resend invitations
            if ($organization->getOwningUsersId() !== $userId) {
                $this->addFlash('error', $this->translator->trans('flash.error.owner_only_resend', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Send the invitation email again
            $this->organizationPresentationService->sendInvitationMail($invitation);

            $this->addFlash('success', $this->translator->trans('flash.success.invitation_resent', ['email' => $invitation->getEmail()], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.resend_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/invitation/{invitationId}',
        name: 'organization.presentation.accept_invitation',
        methods: [Request::METHOD_GET, Request::METHOD_POST]
    )]
    public function acceptInvitationAction(Request $request, string $invitationId): Response
    {
        // Find the invitation
        $invitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);

        if ($invitation === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.invitation_not_found_or_used', [], 'organization'));

            return $this->redirectToRoute('static_pages.presentation.homepage');
        }

        $organization     = $invitation->getOrganization();
        $ownerName        = $this->accountFacade->getAccountCoreEmailById($organization->getOwningUsersId()) ?? $this->translator->trans('fallback.someone', [], 'organization');
        $organizationName = $this->organizationDomainService->getOrganizationName($organization);

        // GET request - show the acceptance page
        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->render('@organization.presentation/accept_invitation.html.twig', [
                'invitationId'     => $invitationId,
                'ownerName'        => $ownerName,
                'organizationName' => $organizationName,
            ]);
        }

        if (!$this->isCsrfTokenValid('accept_invitation', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf', [], 'organization'));

            return $this->redirectToRoute('static_pages.presentation.homepage');
        }

        // POST request - accept the invitation
        try {
            $currentUser = $this->getUser();
            $userId      = $currentUser !== null
                ? $this->getAccountInfo($currentUser)->id
                : null;

            $newUserId = $this->organizationDomainService->acceptInvitation($invitation, $userId);

            if ($newUserId === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.accept_failed', [], 'organization'));

                return $this->redirectToRoute('static_pages.presentation.homepage');
            }

            // If user wasn't logged in and we created a new one, log them in
            if ($currentUser === null) {
                $newUser = $this->accountFacade->getAccountForLogin($newUserId);
                if ($newUser !== null) {
                    $this->security->login($newUser, 'form_login', 'main');
                }

                $this->addFlash('success', $this->translator->trans('flash.success.joined', ['name' => $organizationName], 'organization'));

                // If user was auto-registered via invitation, redirect to set password
                if ($this->accountFacade->mustSetPassword($invitation->getEmail())) {
                    return $this->redirectToRoute('account.presentation.set_password');
                }

                return $this->redirectToRoute('account.presentation.dashboard');
            }

            $this->addFlash('success', $this->translator->trans('flash.success.joined', ['name' => $organizationName], 'organization'));

            return $this->redirectToRoute('organization.presentation.dashboard');
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.accept_invitation_failed', ['message' => $e->getMessage()], 'organization'));

            return $this->redirectToRoute('static_pages.presentation.homepage');
        }
    }

    #[Route(
        path: '/organization/group/{groupId}/add-member',
        name: 'organization.presentation.add_member_to_group',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function addMemberToGroupAction(Request $request, string $groupId): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo    = $this->getAccountInfo($user);
        $userId         = $accountInfo->id;
        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_active_organization', [], 'organization'));

            return $this->redirectToRoute('organization.presentation.dashboard');
        }

        try {
            $organization = $this->organizationDomainService->getOrganizationById($organizationId);

            if ($organization === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.organization_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Only owner can manage groups
            if ($organization->getOwningUsersId() !== $userId) {
                $this->addFlash('error', $this->translator->trans('flash.error.owner_only_groups', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $group = $this->organizationDomainService->getGroupById($groupId);

            if ($group === null || $group->getOrganization()->getId() !== $organizationId) {
                $this->addFlash('error', $this->translator->trans('flash.error.group_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $memberId = $request->request->get('member_id');

            if (!is_string($memberId) || $memberId === '') {
                $this->addFlash('error', $this->translator->trans('flash.error.invalid_member_id', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $this->organizationDomainService->addUserToGroup($memberId, $group);
            $this->addFlash('success', $this->translator->trans('flash.success.member_added', ['group' => $group->getName()], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.add_member_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }

    #[Route(
        path: '/organization/group/{groupId}/remove-member',
        name: 'organization.presentation.remove_member_from_group',
        methods: [Request::METHOD_POST]
    )]
    #[IsGranted('ROLE_USER')]
    public function removeMemberFromGroupAction(Request $request, string $groupId): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('account.presentation.sign_in');
        }

        $accountInfo    = $this->getAccountInfo($user);
        $userId         = $accountInfo->id;
        $organizationId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);

        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_active_organization', [], 'organization'));

            return $this->redirectToRoute('organization.presentation.dashboard');
        }

        try {
            $organization = $this->organizationDomainService->getOrganizationById($organizationId);

            if ($organization === null) {
                $this->addFlash('error', $this->translator->trans('flash.error.organization_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            // Only owner can manage groups
            if ($organization->getOwningUsersId() !== $userId) {
                $this->addFlash('error', $this->translator->trans('flash.error.owner_only_groups', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $group = $this->organizationDomainService->getGroupById($groupId);

            if ($group === null || $group->getOrganization()->getId() !== $organizationId) {
                $this->addFlash('error', $this->translator->trans('flash.error.group_not_found', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $memberId = $request->request->get('member_id');

            if (!is_string($memberId) || $memberId === '') {
                $this->addFlash('error', $this->translator->trans('flash.error.invalid_member_id', [], 'organization'));

                return $this->redirectToRoute('organization.presentation.dashboard');
            }

            $this->organizationDomainService->removeUserFromGroup($memberId, $group);
            $this->addFlash('success', $this->translator->trans('flash.success.member_removed', ['group' => $group->getName()], 'organization'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.remove_member_failed', ['message' => $e->getMessage()], 'organization'));
        }

        return $this->redirectToRoute('organization.presentation.dashboard');
    }
}
