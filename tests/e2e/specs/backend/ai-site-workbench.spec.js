// @weline-e2e-runtime fallback
// @ts-check
const { test, expect } = require('@playwright/test');
const { buildWorkbenchUrl, loginAsAdmin } = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

const HUB_TIMEOUT = 120000;
const WORKSPACE_TIMEOUT = 120000;
const TERMINAL_TIMEOUT = 60000;

async function openHub(page, provider = 'pagebuilder', fakeMode = true) {
  const backendRoot = await loginAsAdmin(page);
  const hubUrl = buildWorkbenchUrl(backendRoot, provider, fakeMode);
  await page.goto(hubUrl, { waitUntil: 'domcontentloaded', timeout: HUB_TIMEOUT });
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(1500);
  return { backendRoot, hubUrl };
}

function terminalContent(page) {
  return page.locator('#site-builder-agent-terminal_content');
}

async function waitForWorkspaceReload(page, action) {
  const waitForWorkspaceUrl = page.waitForURL(/site-builder-agent\/workspace\?public_id=/, {
    waitUntil: 'domcontentloaded',
    timeout: WORKSPACE_TIMEOUT,
  }).catch(error => {
    if (/ERR_ABORTED|frame was detached/i.test(String(error && error.message))) {
      return null;
    }
    throw error;
  });

  await Promise.allSettled([waitForWorkspaceUrl, action()]);
  await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, { timeout: WORKSPACE_TIMEOUT }).catch(() => {});
  await page.waitForLoadState('domcontentloaded', { timeout: 20000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
}

async function readJsonPage(page, href) {
  const dataPage = await page.context().newPage();
  await dataPage.goto(new URL(href, page.url()).toString(), {
    waitUntil: 'domcontentloaded',
    timeout: WORKSPACE_TIMEOUT,
  });
  const payload = JSON.parse((await dataPage.locator('body').innerText()).trim());
  await dataPage.close();
  return payload;
}

async function expectHubAnchorState(page, provider, fakeMode) {
  await page.waitForURL(url => {
    return url.pathname.endsWith('/site-builder-agent/index')
      && url.hash === '#provider-lane'
      && url.searchParams.get('provider') === provider
      && (!fakeMode || url.searchParams.get('fake_mode') === '1');
  }, { timeout: HUB_TIMEOUT });
}

test.describe('AI Site Workbench', () => {
  test.describe.configure({ mode: 'serial' });

  test('pagebuilder provider hands off generation into the native PageBuilder workspace', async ({ page }) => {
    test.slow();
    test.setTimeout(300000);
    await openHub(page);

    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#provider-lane')).toBeVisible({ timeout: 30000 });

    const providerLaneLink = page.locator('a[href*="#provider-lane"]').first();
    await expect(providerLaneLink).toHaveAttribute('href', /site-builder-agent\/index/);
    await providerLaneLink.click({ force: true });
    await page.waitForTimeout(1200);

    await expectHubAnchorState(page, 'pagebuilder', true);
    await expect(page.locator('#provider-lane')).toBeVisible();
    await expect(page.locator('body')).not.toHaveText(/^404$/);

    await page.fill('#site-agent-description', 'Build a resumable coffee brand site with subscriptions and story pages.');
    await page.click('#site-agent-create-workspace', { force: true });
    await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, {
      waitUntil: 'domcontentloaded',
      timeout: WORKSPACE_TIMEOUT,
    });
    await page.waitForTimeout(1500);

    await page.fill('#site-builder-title', 'Demo Coffee Roasters');
    await page.fill('#site-builder-tagline', 'Fresh roasting stories each week');
    await page.fill('#site-builder-domain', 'demo-coffee.local.test');
    await page.fill('#site-builder-notes', 'Verify workspace summary and provider tools');
    await page.fill('#site-builder-brief', 'Need brand storytelling, product pages, FAQ, and subscription CTA.');
    await waitForWorkspaceReload(page, () => page.click('#site-builder-save-summary', { force: true }));

    await expect(page.locator('#site-builder-title')).toHaveValue('Demo Coffee Roasters');
    await expect(page.locator('#site-builder-domain')).toHaveValue('demo-coffee.local.test');
    await expect(page.locator('#site-builder-current-stage')).toBeVisible();

    const websitesStateUrl = await page.locator('a[href*="state-json"]').getAttribute('href');
    expect(websitesStateUrl).toBeTruthy();

    const beforeHandoffState = await readJsonPage(page, websitesStateUrl);
    expect(beforeHandoffState.success).toBeTruthy();
    expect(beforeHandoffState.data.session.current_stage).toBe('prepare');
    expect(beforeHandoffState.data.session.scope.site_title).toBe('Demo Coffee Roasters');
    expect(beforeHandoffState.data.session.scope.target_domain).toBe('demo-coffee.local.test');

    await page.click('#site-builder-apply-stage-recommendation', { force: true });
    await page.waitForURL(/pagebuilder\/backend\/aiSiteAgent\/workspace\?public_id=/, {
      waitUntil: 'domcontentloaded',
      timeout: WORKSPACE_TIMEOUT,
    });
    await expect(page.locator('#wiz-theme-hint')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#wiz-theme-hint')).not.toHaveValue('', { timeout: 30000 });

    const pageBuilderStateUrl = await page.locator('a[href*="get-state-json"]').getAttribute('href');
    expect(pageBuilderStateUrl).toBeTruthy();

    const pageBuilderState = await readJsonPage(page, pageBuilderStateUrl);
    expect(pageBuilderState.success).toBeTruthy();
    expect(pageBuilderState.data.stage).toBe('virtual_theme');
    expect(pageBuilderState.data.scope.site_title).toBe('Demo Coffee Roasters');
    expect(pageBuilderState.data.scope.target_domain).toBe('demo-coffee.local.test');
    expect(pageBuilderState.data.scope.handoff_source).toBe('weline_websites_workbench');
    expect(pageBuilderState.data.scope.preferred_flow).toBe('pagebuilder_style_template');
    expect(pageBuilderState.data.scope.pagebuilder_theme_source).toBe('styles');
    expect(pageBuilderState.data.scope.style_template_code).toBeTruthy();

    const websitesState = await readJsonPage(page, websitesStateUrl);
    expect(websitesState.success).toBeTruthy();
    expect(websitesState.data.session.current_stage).toBe('generate');
    expect(websitesState.data.session.scope.pagebuilder_workspace_public_id).toBe(pageBuilderState.data.public_id);
    expect(websitesState.data.session.scope.pagebuilder_workspace_url).toContain('/pagebuilder/backend/aiSiteAgent/workspace');
  });

  test('workspace domain purchase keeps running while pagebuilder handoff continues', async ({ page }) => {
    test.slow();
    test.setTimeout(300000);
    await openHub(page, 'pagebuilder', true);

    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#provider-lane')).toBeVisible({ timeout: 30000 });

    const providerLaneLink = page.locator('a[href*="#provider-lane"]').first();
    await expect(providerLaneLink).toHaveAttribute('href', /site-builder-agent\/index/);
    await providerLaneLink.click({ force: true });
    await page.waitForTimeout(1200);

    await expectHubAnchorState(page, 'pagebuilder', true);
    await expect(page.locator('#provider-lane')).toBeVisible();

    await page.fill('#site-agent-description', 'Build a resumable brand site where domain purchase should keep running in the background.');
    await page.click('#site-agent-create-workspace', { force: true });
    await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, {
      waitUntil: 'domcontentloaded',
      timeout: WORKSPACE_TIMEOUT,
    });
    await page.waitForTimeout(1500);

    const websitesStateUrl = await page.locator('a[href*="state-json"]').getAttribute('href');
    expect(websitesStateUrl).toBeTruthy();
    const initialWebsitesState = await readJsonPage(page, websitesStateUrl);
    const recommendationPatch = JSON.parse(
      (await page.locator('#site-builder-apply-stage-recommendation').getAttribute('data-patch')) || '{}'
    );
    const registrarValue = String(
      recommendationPatch.preferred_registrar_account_id
        || recommendationPatch.registrar_account_id
        || initialWebsitesState.data.session.scope.preferred_registrar_account_id
        || initialWebsitesState.data.session.scope.registrar_account_id
        || ''
    );
    expect(registrarValue).toBeTruthy();

    await page.fill('#site-builder-domain', 'background-domain.local.test');
    await page.evaluate(({ accountId, label }) => {
      const valueInput = document.getElementById('site-builder-registrar-account_value');
      if (valueInput && !valueInput.value) {
        valueInput.value = String(accountId || '');
      }

      const labelInput = document.getElementById('site-builder-registrar-label');
      if (labelInput && !labelInput.value && label) {
        labelInput.value = String(label);
      }
    }, {
      accountId: registrarValue,
      label: recommendationPatch.recommended_registrar_label
        || initialWebsitesState.data.session.scope.recommended_registrar_label
        || '',
    });

    const startDomainPurchaseUrl = new URL(page.url());
    startDomainPurchaseUrl.pathname = startDomainPurchaseUrl.pathname.replace(/\/workspace$/, '/start-domain-purchase');
    startDomainPurchaseUrl.search = '';
    const domainPurchaseResponse = await page.evaluate(async ({ url, publicId, scopePatch }) => {
      const body = new URLSearchParams();
      body.set('public_id', publicId);
      body.set('scope_patch', JSON.stringify(scopePatch));

      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
        credentials: 'same-origin',
      });

      return response.json();
    }, {
      url: startDomainPurchaseUrl.toString(),
      publicId: initialWebsitesState.data.session.public_id,
      scopePatch: {
        target_domain: 'background-domain.local.test',
        preferred_registrar_account_id: Number(registrarValue),
        registrar_account_id: Number(registrarValue),
        recommended_registrar_label: recommendationPatch.recommended_registrar_label
          || initialWebsitesState.data.session.scope.recommended_registrar_label
          || '',
      },
    });
    expect(domainPurchaseResponse.success).toBeTruthy();

    if (domainPurchaseResponse.startable && domainPurchaseResponse.stream_url) {
      await page.evaluate(async streamUrl => {
        const response = await fetch(streamUrl, {
          method: 'GET',
          credentials: 'same-origin',
        });
        await response.text();
      }, domainPurchaseResponse.stream_url);
    }

    await page.click('#site-builder-apply-stage-recommendation', { force: true });
    await page.waitForURL(/pagebuilder\/backend\/aiSiteAgent\/workspace\?public_id=/, {
      waitUntil: 'domcontentloaded',
      timeout: WORKSPACE_TIMEOUT,
    });
    await expect(page.locator('#wiz-theme-hint')).toBeVisible({ timeout: 30000 });

    await page.waitForTimeout(2500);
    const websitesState = await readJsonPage(page, websitesStateUrl);
    expect(websitesState.success).toBeTruthy();
    expect(websitesState.data.domain_purchase.domain).toBe('background-domain.local.test');
    expect(websitesState.data.domain_purchase.status).toBe('completed');
    expect(websitesState.data.domain_purchase.message).toContain('Local demo');
  });

  test('fake AI quick build emits domain, theme, and visual preview milestones', async ({ page }) => {
    test.slow();
    test.setTimeout(300000);
    await openHub(page);
    const terminal = terminalContent(page);

    await page.fill('#site-agent-description', 'Launch a polished outdoor gear storefront with seasonal landing pages.');
    await page.click('#site-agent-start-btn', { force: true });

    await expect(terminal).toContainText('Local demo: recommended registrar', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo: suggested domain', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo: simulated domain purchase and bootstrap resources', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo: generated theme direction and virtual theme', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo: prepared visual-edit preview', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo flow completed', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(page.locator('#site-agent-start-btn')).toBeEnabled({ timeout: TERMINAL_TIMEOUT });
  });

  test('fake manual quick build requires a registrar and then runs the simulated purchase flow', async ({ page }) => {
    test.slow();
    test.setTimeout(300000);
    await openHub(page, 'websites_default', true);
    const terminal = terminalContent(page);

    await page.uncheck('#site-agent-use-ai');
    await page.fill('#site-agent-domain', 'manual-demo.local.test');
    const registrarValue = await page.locator('#site-agent-account option:not([value=""])').first().getAttribute('value');
    expect(registrarValue).toBeTruthy();
    await page.selectOption('#site-agent-account', registrarValue);
    await page.click('#site-agent-start-btn', { force: true });

    await expect(terminal).toContainText('Local demo: simulated domain purchase and bootstrap resources', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('manual-demo.local.test', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(terminal).toContainText('Local demo flow completed', {
      timeout: TERMINAL_TIMEOUT,
    });
    await expect(page.locator('#site-agent-start-btn')).toBeEnabled({ timeout: TERMINAL_TIMEOUT });
  });
});
