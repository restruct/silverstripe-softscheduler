import { test, expect, expectNotFoundFor, urlOf, visitor, visitorContext } from './support';

// What a visitor sees: a page under embargo or past its expiry answers the site's normal 404 by
// its URL (not a login form) and drops out of canView()-filtered lists such as the menu. Pages
// without dates, or inside their window, are untouched.

test('visible pages render for a visitor, and the menu leaves out scheduled and expired pages', async ({ browser, baseURL }) => {
    const context = await visitorContext(browser, baseURL);
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    for (const title of ['Sched visible', 'Sched window']) {
        const response = await page.goto(urlOf(title));
        expect(response?.status(), `${title}: status`).toBe(200);
        await expect(page.locator('#title')).toHaveText(title);
        await expect(page.locator('#content')).toHaveText(`Content of ${title}`);
    }

    const menu = page.locator('#menu li');
    await expect(menu.filter({ hasText: /^Sched visible$/ })).toHaveCount(1);
    await expect(menu.filter({ hasText: /^Sched window$/ })).toHaveCount(1);
    await expect(menu.filter({ hasText: /^Sched embargo$/ })).toHaveCount(0);
    await expect(menu.filter({ hasText: /^Sched expired$/ })).toHaveCount(0);
    expect(errors, 'no console errors on the visible pages').toEqual([]);
    await context.close();
});

test('a page under embargo answers a visitor with the site\'s 404, not a login form', async ({ browser, baseURL }) => {
    await expectNotFoundFor(await visitor(browser, baseURL), 'Sched embargo');
});

test('an expired page answers a visitor with the site\'s 404, not a login form', async ({ browser, baseURL }) => {
    await expectNotFoundFor(await visitor(browser, baseURL), 'Sched expired');
});

test('a CMS user (VIEW_DRAFT_CONTENT) can still open a scheduled and an expired page by URL', async ({ page }) => {
    for (const title of ['Sched embargo', 'Sched expired']) {
        const response = await page.goto(urlOf(title));
        expect(response?.status(), `${title}: status`).toBe(200);
        await expect(page.locator('#content')).toHaveText(`Content of ${title}`);
    }
});
