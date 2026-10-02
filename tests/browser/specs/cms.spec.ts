import { test, expect, expectNotFoundFor, flag, treeNode, urlOf, visitor } from './support';

// The CMS side: the site tree flags scheduled and expired pages, and the "Schedule publishing &
// unpublishing" fields on the edit form set the dates that the front end acts on.

test('the site tree flags scheduled and expired pages', async ({ page }) => {
    await page.goto('/admin/pages');
    await expect(treeNode(page, 'Sched embargo')).toBeVisible();
    // updateStatusFlags() keys 'status-scheduled' / 'status-expired': the tree renders each as a
    // badge in the node's link and as a status-<key> class on the node.
    await expect(flag(page, 'Sched embargo')).toHaveText('Scheduled');
    await expect(treeNode(page, 'Sched embargo')).toHaveClass(/status-status-scheduled/);
    await expect(flag(page, 'Sched expired')).toHaveText('Expired');
    await expect(treeNode(page, 'Sched expired')).toHaveClass(/status-status-expired/);
    for (const title of ['Sched visible', 'Sched window']) {
        await expect(treeNode(page, title)).toBeVisible();
        await expect(flag(page, title)).toHaveCount(0);
    }
});

test('an expiry set and published in the CMS takes the page off the front end', async ({ page, browser, baseURL }) => {
    // Regression guard for https://github.com/restruct/silverstripe-softscheduler/issues/3 (fixed in
    // 3.0.1): on Silverstripe 6 the page edit form of a page type with the extension failed with a 500
    // ("a field called 'Embargo' appears twice"): SiteTree scaffolds Embargo/Expiry there and the
    // extension added them again inside its toggle. Silverstripe 5 was not affected.
    const anonymous = await visitor(browser, baseURL);
    expect((await anonymous.get(urlOf('Sched edit'))).status(), 'visible before').toBe(200);

    await page.goto('/admin/pages');
    await treeNode(page, 'Sched edit').locator('> a').click();
    const form = page.locator('#Form_EditForm');
    await expect(page.locator('#Form_EditForm_Title')).toHaveValue('Sched edit');

    // The collapsible group sits before the Content field and holds both dates.
    const group = form.locator('#Form_EditForm_SoftScheduler_Holder, #Form_EditForm_SoftScheduler').first();
    await expect(group).toContainText('Schedule publishing & unpublishing of this page');
    const order = await form.evaluate((f) => {
        const g = f.querySelector('[id^="Form_EditForm_SoftScheduler"]');
        const c = f.querySelector('#Form_EditForm_Content_Holder, [id^="Form_EditForm_Content"]');
        return g && c ? !!(g.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING) : null;
    });
    expect(order, 'the schedule group comes before Content').toBe(true);
    await group.getByText('Schedule publishing & unpublishing of this page').click();
    const expiry = page.locator('#Form_EditForm_Expiry');
    await expect(expiry).toBeVisible();
    await expect(page.locator('#Form_EditForm_Embargo')).toBeVisible();

    // Yesterday, as the datetime-local input takes it.
    const d = new Date(Date.now() - 24 * 3600 * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    await expiry.fill(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`);

    const published = page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/pages\/edit\/EditForm/.test(r.url()));
    await form.locator('button[name="action_publish"]').click();
    expect((await published).status(), 'publish status').toBe(200);

    await expectNotFoundFor(anonymous, 'Sched edit');
    await page.goto('/admin/pages');
    await expect(flag(page, 'Sched edit')).toHaveText('Expired');

    // Clear it again and publish, so a repeat run (--repeat-each) starts from the seeded state.
    await treeNode(page, 'Sched edit').locator('> a').click();
    await expect(page.locator('#Form_EditForm_Title')).toHaveValue('Sched edit');
    await page.locator('#Form_EditForm').getByText('Schedule publishing & unpublishing of this page').click();
    await page.locator('#Form_EditForm_Expiry').fill('');
    const republished = page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/pages\/edit\/EditForm/.test(r.url()));
    await page.locator('#Form_EditForm button[name="action_publish"]').click();
    expect((await republished).status()).toBe(200);
    expect((await anonymous.get(urlOf('Sched edit'))).status(), 'visible again').toBe(200);
});
