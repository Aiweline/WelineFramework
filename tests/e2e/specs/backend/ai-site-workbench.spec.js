// @ts-check
const { test, expect } = require('@playwright/test');
const { buildWorkbenchUrl, loginAsAdmin } = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

async function openHub(page, provider = 'pagebuilder', fakeMode = true) {
  const backendRoot = await loginAsAdmin(page);
  const hubUrl = buildWorkbenchUrl(backendRoot, provider, fakeMode);
  await page.goto(hubUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(1500);
  return { backendRoot, hubUrl };
}

function terminalContent(page) {
  return page.locator('#site-builder-agent-terminal_content');
}

test.describe('AI Site Workbench', () => {
  test('provider lane anchors stay on the hub and workspace flow can advance to visual edit', async ({ page }) => {
    test.slow();
    const { hubUrl } = await openHub(page);

    const providerLaneLink = page.locator('a[href*="#provider-lane"]').first();
    await expect(providerLaneLink).toHaveAttribute('href', /site-builder-agent\/index/);
    await providerLaneLink.click();
    await page.waitForTimeout(1200);

    await expect(page).toHaveURL(new RegExp(`${hubUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}#provider-lane$`));
    await expect(page.locator('#provider-lane')).toBeVisible();
    await expect(page.locator('body')).not.toHaveText(/^404$/);

    await page.fill('#site-agent-description', 'Build a resumable coffee brand site with subscriptions and story pages.');
    await page.click('#site-agent-create-workspace');
    await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, { timeout: 30000 });
    await page.waitForTimeout(1500);

    await page.fill('#site-builder-title', 'Demo Coffee Roasters');
    await page.fill('#site-builder-tagline', 'Fresh roasting stories each week');
    await page.fill('#site-builder-domain', 'demo-coffee.local.test');
    await page.fill('#site-builder-notes', 'Verify workspace summary and provider tools');
    await page.fill('#site-builder-brief', 'Need brand storytelling, product pages, FAQ, and subscription CTA.');
    await page.click('#site-builder-save-summary');
    await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, { timeout: 30000 });
    await page.waitForTimeout(1500);

    await expect(page.locator('#site-builder-title')).toHaveValue('Demo Coffee Roasters');
    await expect(page.locator('#site-builder-domain')).toHaveValue('demo-coffee.local.test');

    await page.selectOption('#site-builder-stage', 'virtual_theme');
    await page.click('#site-builder-save-stage');
    await page.waitForTimeout(1500);
    await expect(page.locator('#site-builder-stage')).toHaveValue('virtual_theme');

    await page.locator('[data-tool-code="prepare_visual_edit_stage"]').click();
    await page.waitForTimeout(1500);
    await expect(page.locator('#site-builder-stage')).toHaveValue('visual_edit');

    const stateUrl = await page.locator('a[href*="state-json"]').getAttribute('href');
    expect(stateUrl).toBeTruthy();

    const statePage = await page.context().newPage();
    await statePage.goto(new URL(stateUrl, page.url()).toString(), {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    });
    const state = JSON.parse((await statePage.locator('body').innerText()).trim());
    await statePage.close();

    expect(state.success).toBeTruthy();
    expect(state.data.session.current_stage).toBe('visual_edit');
    expect(state.data.session.scope.site_title).toBe('Demo Coffee Roasters');
    expect(state.data.session.scope.preferred_editor).toBe('pagebuilder');
  });

  test('fake AI quick build emits domain, theme, and visual preview milestones', async ({ page }) => {
    test.slow();
    await openHub(page);
    const terminal = terminalContent(page);

    await page.fill('#site-agent-description', 'Launch a polished outdoor gear storefront with seasonal landing pages.');
    await page.click('#site-agent-start-btn');

    await expect(terminal).toContainText('Local demo: suggested domain', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('Local demo: simulated domain purchase and bootstrap resources', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('Local demo: generated theme direction and virtual theme', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('Local demo: prepared visual-edit preview', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('Local demo flow completed', {
      timeout: 20000,
    });
    await expect(page.locator('#site-agent-start-btn')).toBeEnabled({ timeout: 20000 });
  });

  test('fake manual quick build can run without selecting a registrar account', async ({ page }) => {
    test.slow();
    await openHub(page, 'websites_default', true);
    const terminal = terminalContent(page);

    await page.uncheck('#site-agent-use-ai');
    await page.fill('#site-agent-domain', 'manual-demo.local.test');
    await page.click('#site-agent-start-btn');

    await expect(terminal).toContainText('Local demo: simulated domain purchase and bootstrap resources', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('manual-demo.local.test', {
      timeout: 20000,
    });
    await expect(terminal).toContainText('Local demo flow completed', {
      timeout: 20000,
    });
    await expect(page.locator('#site-agent-start-btn')).toBeEnabled({ timeout: 20000 });
  });
});
