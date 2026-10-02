import { test as base, expect, type Browser, type BrowserContext, type Locator, type Page } from '@playwright/test';

// Shared fixtures and helpers for the signed-asset-urls specs.
//
// The fixture controller (tests/browser/fixtures/, routed at /sau-browser by fixtures/_config) seeds
// three 40x30 PNGs in the folder sau-browser/ (protected = published but logged-in only, draft =
// not published, public) and a member without CMS access, and renders a front-end page with the
// three images through AutoURL(policy). The signing secret comes from BROWSER_ENV in targets.sh.

/** The member the fixture creates: logged in, but no CMS access, so no signing bypass. */
export const VISITOR = { email: 'visitor@sau.test', password: 'Sau-visitor-7Kq!m2Zr-browser' };

/**
 * test, extended with an automatic console guard on the default (CMS admin) page: every spec fails
 * if it logs a console error or throws an uncaught exception. "Failed to load resource" arrives as
 * a console error too, so a signed image the admin cannot load fails the spec. Visitor pages (other
 * browser contexts) are made by the specs and checked explicitly instead: some of them are meant
 * to be refused.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/** Make sure the fixture files and member exist (as the logged-in admin). */
export async function seed(page: Page): Promise<void> {
    const response = await page.request.get('/sau-browser/seed');
    expect(response.status(), 'fixtures seeded').toBe(200);
}

/** A fresh browser context: no cookies, so no session and no login. */
export async function visitorContext(browser: Browser, baseURL: string | undefined): Promise<BrowserContext> {
    return browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
}

/** A fresh context logged in through the real login form as the fixture member (its own session). */
export async function memberContext(browser: Browser, baseURL: string | undefined): Promise<BrowserContext> {
    const context = await visitorContext(browser, baseURL);
    const page = await context.newPage();
    await page.goto('/Security/login?BackURL=/sau-browser/page');
    await page.locator('input[name="Email"]').fill(VISITOR.email);
    await page.locator('input[name="Password"]').fill(VISITOR.password);
    await page.locator('[name="action_doLogin"]').click();
    await expect(page).toHaveURL(/\/sau-browser\/page/);
    await page.close();
    return context;
}

/** Open the fixture page with this AutoURL policy; returns the page response. */
export async function openFixturePage(page: Page, policy: string, extra = '') {
    const response = await page.goto(`/sau-browser/page?policy=${policy}${extra}`);
    expect(response?.status(), 'fixture page status').toBe(200);
    return response!;
}

/** The src of one of the fixture page's images (protected, draft, public). */
export async function src(page: Page, id: string): Promise<string> {
    const value = await page.locator(`img#${id}`).getAttribute('src');
    expect(value, `img#${id} has a src`).toBeTruthy();
    return value!;
}

/** Wait until an <img> has loaded and decoded; returns its intrinsic size. */
export async function loadedSize(img: Locator): Promise<{ w: number; h: number }> {
    await expect
        .poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0), { message: 'image loaded' })
        .toBe(true);
    return img.evaluate((el: HTMLImageElement) => ({ w: el.naturalWidth, h: el.naturalHeight }));
}

/** Whether an <img> has finished and failed (a refused URL). */
export async function failedToLoad(img: Locator): Promise<void> {
    await expect
        .poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth === 0), { message: 'image refused' })
        .toBe(true);
}

/** Replace one query parameter of a (relative) URL. */
export function withParam(url: string, key: string, value: string | null): string {
    const u = new URL(url, 'http://x');
    if (value === null) {
        u.searchParams.delete(key);
    } else {
        u.searchParams.set(key, value);
    }
    return u.pathname + u.search;
}

/** max-age from a Cache-Control header, or null. */
export function maxAge(header: string | undefined): number | null {
    const m = /max-age=(\d+)/.exec(header ?? '');
    return m ? Number(m[1]) : null;
}
