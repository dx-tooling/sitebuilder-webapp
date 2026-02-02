<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Repository;

use App\Organization\Domain\Entity\Organization;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OrganizationRepository implements OrganizationRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return list<Organization>
     *
     * @throws Exception
     */
    public function getAllOrganizationsForUser(string $userId): array
    {
        $organizationsTableName = $this->entityManager->getClassMetadata(Organization::class)->getTableName();

        $sql = "
            SELECT o.id
            FROM $organizationsTableName o
            WHERE o.owning_users_id = :userId

            UNION

            SELECT om.organizations_id
            FROM organization_members om
            WHERE om.account_cores_id = :userId
        ";

        $connection      = $this->entityManager->getConnection();
        $result          = $connection->executeQuery($sql, ['userId' => $userId]);
        $organizationIds = $result->fetchFirstColumn();

        if (count($organizationIds) === 0) {
            return [];
        }

        return $this->entityManager
            ->getRepository(Organization::class)
            ->findBy(['id' => $organizationIds]);
    }

    /** @throws Exception */
    public function userHasJoinedOrganizations(string $userId): bool
    {
        $sql = '
            SELECT 1
            FROM organization_members om
            WHERE om.account_cores_id = :userId
            LIMIT 1
        ';

        $connection = $this->entityManager->getConnection();
        $result     = $connection->executeQuery($sql, ['userId' => $userId]);

        return $result->fetchOne() !== false;
    }

    /** @throws Exception */
    public function userHasJoinedOrganization(string $userId, string $organizationId): bool
    {
        $sql = '
            SELECT 1
            FROM organization_members om
            WHERE om.account_cores_id = :userId
              AND om.organizations_id = :organizationId
            LIMIT 1
        ';

        $connection = $this->entityManager->getConnection();
        $result     = $connection->executeQuery($sql, [
            'userId'         => $userId,
            'organizationId' => $organizationId,
        ]);

        return $result->fetchOne() !== false;
    }

    public function findById(string $organizationId): ?Organization
    {
        return $this->entityManager->find(Organization::class, $organizationId);
    }

    /** @throws Exception */
    public function addUserToOrganization(string $userId, string $organizationId): void
    {
        $sql = '
            INSERT INTO organization_members (account_cores_id, organizations_id)
            VALUES (:userId, :organizationId)
        ';

        $this->entityManager->getConnection()->executeStatement($sql, [
            'userId'         => $userId,
            'organizationId' => $organizationId,
        ]);
    }

    /** @throws Exception */
    public function addMemberToGroup(string $userId, string $groupId): void
    {
        $sql = '
            INSERT INTO organization_group_members (account_cores_id, organization_groups_id)
            VALUES (:userId, :groupId)
        ';

        $this->entityManager->getConnection()->executeStatement($sql, [
            'userId'  => $userId,
            'groupId' => $groupId,
        ]);
    }

    /** @throws Exception */
    public function removeMemberFromGroup(string $userId, string $groupId): void
    {
        $sql = '
            DELETE FROM organization_group_members
            WHERE account_cores_id = :userId
              AND organization_groups_id = :groupId
        ';

        $this->entityManager->getConnection()->executeStatement($sql, [
            'userId'  => $userId,
            'groupId' => $groupId,
        ]);
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function getMemberIdsOfGroup(string $groupId): array
    {
        $sql = '
            SELECT account_cores_id
            FROM organization_group_members
            WHERE organization_groups_id = :groupId
        ';

        $result = $this->entityManager->getConnection()->executeQuery($sql, [
            'groupId' => $groupId,
        ]);

        /** @var list<string> $memberIds */
        $memberIds = $result->fetchFirstColumn();

        return $memberIds;
    }

    /** @throws Exception */
    public function userIsMemberOfGroup(string $userId, string $groupId): bool
    {
        $sql = '
            SELECT 1
            FROM organization_group_members
            WHERE account_cores_id = :userId
              AND organization_groups_id = :groupId
            LIMIT 1
        ';

        $result = $this->entityManager->getConnection()->executeQuery($sql, [
            'userId'  => $userId,
            'groupId' => $groupId,
        ]);

        return $result->fetchOne() !== false;
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function getGroupIdsOfUser(string $userId): array
    {
        $sql = '
            SELECT organization_groups_id
            FROM organization_group_members
            WHERE account_cores_id = :userId
        ';

        $result = $this->entityManager->getConnection()->executeQuery($sql, [
            'userId' => $userId,
        ]);

        /** @var list<string> $groupIds */
        $groupIds = $result->fetchFirstColumn();

        return $groupIds;
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function getJoinedUserIdsForOrganization(string $organizationId): array
    {
        $sql = '
            SELECT account_cores_id
            FROM organization_members
            WHERE organizations_id = :organizationId
        ';

        $result = $this->entityManager->getConnection()->executeQuery($sql, [
            'organizationId' => $organizationId,
        ]);

        /** @var list<string> $userIds */
        $userIds = $result->fetchFirstColumn();

        return $userIds;
    }
}
