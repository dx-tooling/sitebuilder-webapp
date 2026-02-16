import { test, expect } from "@playwright/test";

/**
 * Full e2e flow with all external dependencies simulated (APP_ENV=test):
 * sign in → create project → open editor → send message.
 * Uses test doubles for: LLM, manifest URL, S3, git clone (fixture), GitHub PR.
 */
test.describe("full flow (sign in, create project, use editor)", () => {
    test("user can sign in, create a project, open editor, and send a message", async ({ page }) => {
        test.setTimeout(180000);

        // 1. Sign in
        await page.goto("/en/account/sign-in");
        await page.getByTestId("sign-in-email").fill("e2e@example.com");
        await page.getByTestId("sign-in-password").fill("e2e-secret");
        await page.getByTestId("sign-in-submit").click();

        await expect(page).toHaveURL(/\/en\/projects/);
        await expect(page.getByTestId("project-list-page")).toBeVisible();

        // 2. Go to add project (one link when empty, one in header when not)
        await page.getByTestId("project-list-add-project").first().click();
        await expect(page.getByTestId("project-form")).toBeVisible();

        // 3. Fill project form (simulated validators accept these in test env)
        await page.getByTestId("project-form-name").fill("E2E Test Project");
        await page.getByTestId("project-form-git-url").fill("https://github.com/e2e/fake-repo.git");
        await page.getByTestId("project-form-github-token").fill("fake-token");
        await page.getByTestId("project-form-content-editing-api-key").fill("fake-llm-key");
        // Blur so optional client-side LLM key verification can complete (simulated in test env)
        await page.getByTestId("project-form-name").click();
        await page.waitForTimeout(500);

        await page.getByTestId("project-form-submit").click();
        await page.waitForURL(/\/en\/projects\/?$/, { timeout: 20000 });

        // 4. Project list after create: exactly one project (no prefabs.yaml in repo)
        await expect(page.getByTestId("project-list-page")).toBeVisible({
            timeout: 10000,
        });
        await expect(page.getByTestId("project-list-heading")).toBeVisible();
        await expect(page.locator('[data-test-class="project-list-item"]')).toHaveCount(1);

        // 5. Open editor (Edit content) for our project
        await page.locator('[data-test-class="project-list-edit-content-link"]').first().click();

        // 6. Either workspace setup page (then redirect) or editor directly
        const setupPage = page.getByTestId("workspace-setup-page");
        const editorPage = page.getByTestId("editor-page");

        if (await setupPage.isVisible()) {
            await expect(editorPage).toBeVisible({ timeout: 120000 });
        } else {
            await expect(editorPage).toBeVisible({ timeout: 10000 });
        }

        // 7. Send a message in the editor
        await expect(page.getByTestId("editor-message-input")).toBeVisible();
        await page.getByTestId("editor-message-input").fill("Add a title to the page");
        await page.getByTestId("editor-send-button").click();

        // 8. Wait for simulated LLM to respond (stream completes)
        await expect(page.getByTestId("editor-page")).toBeVisible({
            timeout: 30000,
        });
        await page.waitForTimeout(3000);
        await expect(page.getByTestId("editor-message-input")).toBeVisible();
    });
});
