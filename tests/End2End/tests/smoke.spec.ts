import { test, expect } from "@playwright/test";

test.describe("smoke", () => {
    test("homepage loads", async ({ page }) => {
        await page.goto("/");
        await expect(page).toHaveTitle(/SiteBuilder|sitebuilder/i);
    });

    test("sign-in page loads", async ({ page }) => {
        await page.goto("/en/account/sign-in");
        await expect(page.getByRole("heading", { name: /welcome back|sign in|log in/i })).toBeVisible();
        await expect(page.getByLabel(/email/i)).toBeVisible();
        await expect(page.getByLabel(/password/i)).toBeVisible();
    });
});
