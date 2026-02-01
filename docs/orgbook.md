# Organization Book

This document describes the multi-tenancy model built around Organizations.

## Overview

Every user operates within the context of an Organization. Organizations provide workspace isolation and team collaboration features including membership management, role-based access control via Groups, and invitation workflows.

Projects are scoped to Organizations, ensuring proper data isolation between tenants.

## Domain Model

```
Organization
├── owned by exactly one User (owner)
├── has many Groups
├── has many Invitations (pending)
├── has many joined Users (via join table)
└── has many Projects

Group
├── belongs to one Organization
├── has a list of AccessRights
├── has many member Users (via join table)
└── may be marked as default for new members

Invitation
├── belongs to one Organization
└── targets an email address

Project
├── belongs to one Organization
└── scoped by organizationId
```

### Organization

An Organization is a workspace owned by a single user. The owner has implicit full access regardless of group membership.

- **id**: UUID, auto-generated
- **owningUsersId**: The user who created and owns the organization
- **name**: Optional display name (max 256 chars); falls back to a translated default

### Group

Groups define permission sets within an Organization. Each organization is created with two default groups:

| Group          | Access Rights                         | Default for New Members |
| -------------- | ------------------------------------- | ----------------------- |
| Administrators | `FULL_ACCESS`                         | No                      |
| Team Members   | `SEE_ORGANIZATION_GROUPS_AND_MEMBERS` | Yes                     |

Groups are immutable after creation (name, access rights, default flag are readonly).

### AccessRight Enum

Available access rights that can be assigned to groups:

| Value                                   | Description                         |
| --------------------------------------- | ----------------------------------- |
| `FULL_ACCESS`                           | Grants all permissions              |
| `EDIT_ORGANIZATION_NAME`                | Can rename the organization         |
| `INVITE_ORGANIZATION_MEMBERS`           | Can send invitations                |
| `SEE_ORGANIZATION_GROUPS_AND_MEMBERS`   | Can view member list and groups     |
| `MOVE_ORGANIZATION_MEMBERS_INTO_GROUPS` | Can change member group assignments |

### Invitation

Invitations are pending membership offers sent via email. They persist until accepted or the organization is deleted (cascade).

### Project Scoping

All projects belong to an organization via `organizationId`. When listing or creating projects, the user's currently active organization determines the visible scope.

## User Context

Each User has a `currentlyActiveOrganizationId` that determines their working context. This is set:

- When a user's own organization is created (on registration)
- When a user accepts an invitation to join another organization
- When a user explicitly switches organizations

A user can belong to multiple organizations (their own + any they've joined via invitation).

## Lifecycle Events

### User Registration → Organization Creation

When a new user registers, the `AccountCoreCreatedSymfonyEvent` triggers automatic creation of their personal organization:

```
User Created
  → AccountCoreCreatedSymfonyEventSubscriber
    → createOrganization(userId)
    → dispatch CurrentlyActiveOrganizationChangedSymfonyEvent
      → set user's currentlyActiveOrganizationId
```

### Invitation Flow

```
1. Owner invites email@example.com
   → Invitation entity created
   → Email sent with accept link

2. Recipient clicks link
   a) If already registered:
      → Added to organization
      → Added to default group
      → currentlyActiveOrganizationId updated
   b) If new user:
      → Must register first
      → Then added to inviting organization
      → currentlyActiveOrganizationId set to inviting org

3. Invitation deleted after acceptance
```

## Access Control

Permission checks follow this logic:

1. If user is the organization owner → full access (implicit)
2. Otherwise, check all groups the user belongs to in the currently active organization
3. Grant access if any group has `FULL_ACCESS` or the specific required `AccessRight`

```php
// Pseudo-code
if (currentlyActiveOrganizationIsOwnOrganization($userId)) {
    return true;
}

foreach (getGroupsOfUserForCurrentlyActiveOrganization($userId) as $group) {
    if ($group->hasAccessRight(FULL_ACCESS) || $group->hasAccessRight($required)) {
        return true;
    }
}
return false;
```

## Database Schema

### Tables

- `organizations` - Organization entities
- `organization_groups` - Group entities
- `organization_invitations` - Pending invitations
- `organization_members` - Join table: accounts that have joined (not owned) an organization
- `organization_group_members` - Join table: group membership
- `projects.organization_id` - Foreign reference to organization

**Note:** The join tables store `account_cores_id` as a plain GUID without FK constraint. This maintains strict vertical isolation at the persistence layer—the Organization vertical has no database-level dependency on the Account vertical.

### Key Relationships

```sql
-- Account owns organization (1:N, cross-vertical reference via GUID)
organizations.owning_users_id → account_cores.id (no FK)

-- Account's active context (cross-vertical reference via GUID)
account_cores.currently_active_organization_id → organizations.id (no FK)

-- Groups belong to organization (cascade delete, same vertical)
organization_groups.organizations_id → organizations.id (FK)

-- Account joins organization (many-to-many, cross-vertical)
organization_members(account_cores_id, organizations_id)
  └─ FK only to organizations (same vertical)

-- Account is member of group (many-to-many, cross-vertical)
organization_group_members(account_cores_id, organization_groups_id)
  └─ FK only to organization_groups (same vertical)

-- Projects belong to organization (cross-vertical reference via GUID)
projects.organization_id → organizations.id (no FK)
```

## Vertical Structure

The Organization vertical follows the standard feature-based architecture:

```
src/Organization/
├── Domain/
│   ├── Entity/           # Organization, Group, Invitation, OrganizationMember, GroupMember
│   ├── Enum/             # AccessRight
│   ├── Service/          # OrganizationDomainService
│   └── SymfonyEventSubscriber/
├── Facade/
│   ├── OrganizationFacade[Interface]
│   └── SymfonyEvent/     # Cross-vertical events
└── Infrastructure/
    └── Repository/       # OrganizationRepository
```

Cross-vertical communication uses the Facade layer. The Account vertical listens for `CurrentlyActiveOrganizationChangedSymfonyEvent` to update the user's context.

## Data Migration

When the organization system is introduced to an existing deployment:

1. A single organization is created, owned by the oldest user (by `created_at`)
2. The organization is named after the oldest user's email (e.g., "john's Organization")
3. All other users are added as members in the Team Members group
4. All existing projects are associated with this organization
5. All users' `currentlyActiveOrganizationId` is set to this organization

See `migrations/Version20260201160000.php` for implementation details.
