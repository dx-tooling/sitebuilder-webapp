import { test, expect } from "@playwright/test";

/**
 * PhotoBuilder session resumption (APP_ENV=test with fake generators).
 * Verifies that revisiting the photo builder for the same page resumes
 * the previous session and shows "Start Over" buttons.
 *
 * Requires: stack started with docker-compose.e2e.yml (messenger workers in test env)
 */
test.describe("photo builder session resumption", () => {
    test("revisiting photo builder shows Start Over buttons; clicking starts a new session", async ({ page }) => {
        test.setTimeout(120_000);

        // 1. Sign in
        await page.goto("/en/account/sign-in");
        await page.getByTestId("sign-in-email").fill("e2e@example.com");
        await page.getByTestId("sign-in-password").fill("e2e-secret");
        await page.getByTestId("sign-in-submit").click();
        await expect(page).toHaveURL(/\/en\/projects/);

        // 2. Create project
        await page.getByTestId("project-list-add-project").first().click();
        await page.getByTestId("project-form-name").fill("E2E PhotoBuilder Test");
        await page.getByTestId("project-form-git-url").fill("https://github.com/e2e/fake-repo.git");
        await page.getByTestId("project-form-github-token").fill("fake-token");
        await page.getByTestId("project-form-content-editing-api-key").fill("fake-llm-key");
        await page.getByTestId("project-form-name").click();
        await page.waitForTimeout(500);
        await page.getByTestId("project-form-submit").click();
        await page.waitForURL(/\/en\/projects\/?$/, { timeout: 20_000 });

        // 3. Open editor
        await page
            .locator('[data-test-class="project-list-item"]')
            .filter({ hasText: "E2E PhotoBuilder Test" })
            .last()
            .locator('[data-test-class="project-list-edit-content-link"]')
            .click();
        await expect(page.getByTestId("editor-page")).toBeVisible({ timeout: 120_000 });

        // 4. Find the photo builder link for a page and navigate to it
        const photoBuilderLink = page.locator('[data-test-class="photo-builder-page-link"]').first();
        await expect(photoBuilderLink).toBeVisible({ timeout: 30_000 });
        const photoBuilderUrl = await photoBuilderLink.getAttribute("href");
        expect(photoBuilderUrl).toBeTruthy();
        await page.goto(photoBuilderUrl!);

        // 5. Photo builder loads — wait for main content to appear (images generated)
        await expect(page.getByTestId("photo-builder-page")).toBeVisible();
        await expect(page.getByTestId("photo-builder-main-content")).toBeVisible({ timeout: 60_000 });

        // 6. Navigate away
        await page.goto("/en/projects");

        // 7. Navigate back to the same photo builder URL (cache-bust so we get fresh HTML with existingSessionId)
        const revisitUrl =
            photoBuilderUrl! + (photoBuilderUrl!.includes("?") ? "&" : "?") + "_=" + Date.now();
        await page.goto(revisitUrl);

        // 8. "Start Over" buttons are visible (session was resumed)
        await expect(page.getByTestId("photo-builder-page")).toBeVisible();
        await expect(page.locator('[data-test-id="photo-builder-start-over-button"]').first()).toBeVisible({
            timeout: 10_000,
        });

        // 9. Click "Start Over" → loading overlay shown, Start Over buttons hidden
        await page.locator('[data-test-id="photo-builder-start-over-button"]').first().click();
        await expect(page.getByTestId("photo-builder-loading-overlay")).toBeVisible();
        await expect(page.locator('[data-test-id="photo-builder-start-over-button"]').first()).toBeHidden();
    });
});
