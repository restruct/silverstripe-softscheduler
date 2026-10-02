import { test as base, expect, type APIRequestContext, type Browser, type Locator, type Page } from '@playwright/test';

// Shared fixtures and helpers for the softscheduler specs.
//
// The fixture page type SchedBPage (tests/browser/fixtures/, copied into the scratch host by the
// runner) has the extension applied and seeds five published pages on every dev/build: visible,
// within its window, under embargo, expired, and one the CMS spec edits. Its controller renders a
// minimal page with the canView()-filtered top-level menu (the host has no theme).

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception at any point, page load included ("Failed to load
 * resource" for any 4xx/5xx counts too). Requests that are expected to answer 404 go through
 * visitor() below, an API context, so they never reach this guard.
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

/** A fresh, logged-out browser context: an anonymous visitor (no VIEW_DRAFT_CONTENT). */
export async function visitorContext(browser: Browser, baseURL: string | undefined) {
    return browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
}

/** An anonymous HTTP client, for responses a visitor gets without rendering them. */
export async function visitor(browser: Browser, baseURL: string | undefined): Promise<APIRequestContext> {
    return (await visitorContext(browser, baseURL)).request;
}

/** The URL segment of a seeded page ("Sched embargo" -> /sched-embargo; no trailing slash, which SS6 redirects). */
export function urlOf(title: string): string {
    return `/${title.toLowerCase().replace(/ /g, '-')}`;
}

/** Assert a visitor gets the site's normal 404 for this page: not the page, not a login form. */
export async function expectNotFoundFor(request: APIRequestContext, title: string): Promise<void> {
    const response = await request.get(urlOf(title), { maxRedirects: 0 });
    expect(response.status(), `${title}: status`).toBe(404);
    const body = await response.text();
    expect(body, `${title}: the 404 page`).toContain('Page not found');
    expect(body, `${title}: not the page itself`).not.toContain(`Content of ${title}`);
    expect(body, `${title}: not a login form`).not.toContain('name="Email"');
}

/** The site-tree node of a page in the CMS pages section. */
export function treeNode(page: Page, title: string): Locator {
    return page.locator('.cms-tree li[data-id]').filter({ has: page.locator('> a .item', { hasText: new RegExp(`^\\s*${title}\\s*$`) }) });
}

/** The scheduling badge ("Scheduled" / "Expired") in a site-tree node, if any. */
export function flag(page: Page, title: string): Locator {
    return treeNode(page, title).locator('> a .badge').filter({ hasText: /^\s*(Scheduled|Expired)\s*$/ });
}
