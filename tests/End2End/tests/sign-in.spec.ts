import { test, expect } from "@playwright/test";

/**
 * Sign-in flow: uses the default e2e user created by the test harness (e2e.sh)
 * and verifies successful login and redirect to the projects page.
 */
test.describe("sign-in flow", () => {
    test("user can sign in and is redirected to projects page", async ({ page }) => {
        await page.goto("/en/account/sign-in");

        await page.getByTestId("sign-in-email").fill("e2e@example.com");
        await page.getByTestId("sign-in-password").fill("e2e-secret");
        await page.getByTestId("sign-in-submit").click();

        await expect(page).toHaveURL(/\/en\/projects/);
        await expect(page.getByTestId("project-list-page")).toBeVisible();
        await expect(page.getByTestId("project-list-heading")).toBeVisible();
    });
});
