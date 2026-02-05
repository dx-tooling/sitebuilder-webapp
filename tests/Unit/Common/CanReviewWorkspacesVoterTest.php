<?php

declare(strict_types=1);

namespace App\Tests\Unit\Common;

use App\Account\Facade\AccountFacadeInterface;
use App\Common\Domain\Security\CanReviewWorkspacesVoter;
use App\Organization\Facade\OrganizationFacadeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CanReviewWorkspacesVoterTest extends TestCase
{
    private AccountFacadeInterface&MockObject $accountFacade;
    private OrganizationFacadeInterface&MockObject $organizationFacade;
    private CanReviewWorkspacesVoter $voter;

    protected function setUp(): void
    {
        $this->accountFacade      = $this->createMock(AccountFacadeInterface::class);
        $this->organizationFacade = $this->createMock(OrganizationFacadeInterface::class);
        $this->voter              = new CanReviewWorkspacesVoter(
            $this->accountFacade,
            $this->organizationFacade
        );
    }

    public function testSupportsCanReviewWorkspacesAttribute(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, null, ['CAN_REVIEW_WORKSPACES']);

        // Vote is not ACCESS_ABSTAIN, meaning the attribute is supported
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForOtherAttributes(): void
    {
        $token = $this->createMock(TokenInterface::class);

        $result = $this->voter->vote($token, null, ['SOME_OTHER_ATTRIBUTE']);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testDeniesAccessWhenNoUser(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, null, ['CAN_REVIEW_WORKSPACES']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testDeniesAccessWhenUserIdNotFound(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('test@example.com');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->accountFacade
            ->method('getAccountCoreIdByEmail')
            ->with('test@example.com')
            ->willReturn(null);

        $result = $this->voter->vote($token, null, ['CAN_REVIEW_WORKSPACES']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testGrantsAccessWhenUserCanReviewWorkspaces(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('reviewer@example.com');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->accountFacade
            ->method('getAccountCoreIdByEmail')
            ->with('reviewer@example.com')
            ->willReturn('user-123');

        $this->organizationFacade
            ->method('userCanReviewWorkspaces')
            ->with('user-123')
            ->willReturn(true);

        $result = $this->voter->vote($token, null, ['CAN_REVIEW_WORKSPACES']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeniesAccessWhenUserCannotReviewWorkspaces(): void
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('regular@example.com');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->accountFacade
            ->method('getAccountCoreIdByEmail')
            ->with('regular@example.com')
            ->willReturn('user-456');

        $this->organizationFacade
            ->method('userCanReviewWorkspaces')
            ->with('user-456')
            ->willReturn(false);

        $result = $this->voter->vote($token, null, ['CAN_REVIEW_WORKSPACES']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }
}
