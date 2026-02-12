<?php

declare(strict_types=1);

namespace Tests\Unit\WorkspaceMgmt;

use App\WorkspaceMgmt\Domain\Service\BranchNameGenerator;

describe('BranchNameGenerator', function (): void {
    describe('generate', function (): void {
        it('returns branch name in format YYYY-MM-DD_HH-MM-SS-sanitizedEmail-shortWorkspaceId (URL/shell safe)', function (): void {
            $generator   = new BranchNameGenerator();
            $workspaceId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
            $userEmail   = 'user@example.com';

            $result = $generator->generate($workspaceId, $userEmail);

            // Timestamp: YYYY-MM-DD_HH-MM-SS (no space, no colons)
            expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-/');
            // Sanitized email segment
            expect($result)->toContain('-userATexampleDOTcom-');
            // Short workspace ID (first 8 chars)
            expect($result)->toEndWith('-aaaaaaaa');
        });

        it('uses first 8 characters of workspace ID as short ID', function (): void {
            $generator   = new BranchNameGenerator();
            $workspaceId = 'deadbeef-1234-5678-abcd-ffffffffffff';
            $userEmail   = 'a@b.co';

            $result = $generator->generate($workspaceId, $userEmail);

            expect($result)->toEndWith('-deadbeef');
        });

        it('matches full format regex and contains sanitized email and short workspace id', function (): void {
            $generator   = new BranchNameGenerator();
            $workspaceId = '12345678-aaaa-bbbb-cccc-dddddddddddd';
            $userEmail   = 'foo@bar.baz';

            $result = $generator->generate($workspaceId, $userEmail);

            // Full format: YYYY-MM-DD_HH-MM-SS-sanitizedEmail-shortId (shortId = 8 chars, URL/shell safe)
            expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-fooATbarDOTbaz-12345678$/');
        });
    });

    describe('sanitizeEmailForBranchName', function (): void {
        it('replaces @ with AT and . with DOT', function (): void {
            $generator = new BranchNameGenerator();

            expect($generator->sanitizeEmailForBranchName('user@example.com'))
                ->toBe('userATexampleDOTcom');
        });

        it('normalizes email to lowercase', function (): void {
            $generator = new BranchNameGenerator();

            expect($generator->sanitizeEmailForBranchName('User@Example.COM'))
                ->toBe('userATexampleDOTcom');
        });

        it('handles multiple dots in domain', function (): void {
            $generator = new BranchNameGenerator();

            expect($generator->sanitizeEmailForBranchName('a@b.c.d'))
                ->toBe('aATbDOTcDOTd');
        });

        it('handles subdomain-style email', function (): void {
            $generator = new BranchNameGenerator();

            expect($generator->sanitizeEmailForBranchName('manuel@kiessling.net'))
                ->toBe('manuelATkiesslingDOTnet');
        });
    });
});
