import { test, expect } from "@playwright/test";

test.describe("smoke", () => {
    test("homepage loads", async ({ page }) => {
        await page.goto("/");
        await expect(page).toHaveTitle(/SiteBuilder|sitebuilder/i);
        await expect(page.getByTestId("homepage")).toBeVisible();
    });

    test("sign-in page loads", async ({ page }) => {
        await page.goto("/en/account/sign-in");
        await expect(page.getByTestId("sign-in-page")).toBeVisible();
        await expect(page.getByTestId("sign-in-heading")).toBeVisible();
        await expect(page.getByTestId("sign-in-email")).toBeVisible();
        await expect(page.getByTestId("sign-in-password")).toBeVisible();
    });
});
