const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Deploy';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ARTIFACT_DIR = path.resolve(__dirname, '../../../../../../../tests/e2e/artifacts/backend/Weline_Deploy');

function artifactPath(name) {
  fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
  return path.join(ARTIFACT_DIR, name);
}

async function expectNoHorizontalOverflow(page) {
  const metrics = await page.evaluate(() => ({
    htmlScrollWidth: document.documentElement.scrollWidth,
    htmlClientWidth: document.documentElement.clientWidth,
    bodyScrollWidth: document.body.scrollWidth,
    bodyClientWidth: document.body.clientWidth,
  }));
  expect(metrics.htmlScrollWidth).toBeLessThanOrEqual(metrics.htmlClientWidth + 2);
  expect(metrics.bodyScrollWidth).toBeLessThanOrEqual(metrics.bodyClientWidth + 2);
}

function deployRoute() {
  const route = buildModuleBackendRoute(MODULE, 'wls-deploy');
  return `${route}?operation=deploy.tag&project_id=e2e-deploy-profile&domain=e2e-deploy.wls.test&project_type=wls`;
}

moduleDescribe(test, MODULE, 'WLS Deploy profile panel', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'WLS-DEPLOY-PROFILE-001' },
    'standalone deploy panel saves project release profile',
    async ({ page }) => {
      test.setTimeout(300000);
      await loginAsAdmin(page, {
        timeout: 90000,
        bootstrapOnly: true,
        bootstrapModes: ['wls', 'fpm'],
        useProxy: false,
      });

      await page.setViewportSize({ width: 1440, height: 900 });
      await gotoBackend(page, deployRoute(), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });

      const body = page.locator('body');
      const shell = page.locator('.wls-deploy-shell[data-wls-deploy-shell]');
      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(shell).toBeVisible();
      await expect(page.locator('#project-profile')).toBeVisible();
      await expect(page.locator('#project-profile input[name="project_id"]')).toHaveValue('e2e-deploy-profile');
      await expect(page.locator('#project-profile input[name="domain"]')).toHaveValue('e2e-deploy.wls.test');
      await expect(page.locator('#project-profile input[name="profile_key"]')).toHaveValue('project:e2e-deploy-profile');
      await expect(page.locator('[data-wls-deploy-preflight]')).toBeVisible();

      await page.locator('input[name="enabled"][value="1"]').check();
      await page.locator('input[name="backup_before_deploy"][value="1"]').check();
      await page.locator('input[name="run_composer_install"][value="1"]').check();
      await page.locator('#wdep-profile-repo').fill('https://example.com/org/e2e-deploy.git');
      await page.locator('#wdep-profile-branch').fill('main');
      await page.locator('#wdep-profile-remote').fill('origin');
      await page.locator('#wdep-profile-root').fill('E:\\WelineFramework\\DEV-workspace');
      await page.locator('#wdep-profile-trigger').selectOption('tag');
      await page.locator('#wdep-profile-tag-prefix').fill('v');
      await page.locator('#wdep-profile-webhook-branch').fill('main');
      await page.locator('#wdep-profile-webhook-secret').fill('e2e-project-secret');
      await page.locator('#wdep-profile-git-mode').selectOption('pull_ff_only');
      await page.locator('#wdep-profile-composer').fill('composer install --no-dev --prefer-dist');
      await page.locator('#wdep-profile-post-command').fill('php bin/w setup:upgrade --route');
      await page.locator('#wdep-profile-rollback').fill('last-stable');
      await page.locator('#wdep-profile-description').fill('E2E project deploy profile');

      await Promise.all([
        page.waitForURL(/deploy_notice=profile_saved/i, { timeout: 30000 }),
        page.locator('#project-profile button[type="submit"]').click(),
      ]);

      await expect(page.locator('.wdep-alert.is-ok')).toContainText(/Profile/);
      await expect(page.locator('#project-profile .wdep-pill')).toContainText(/Profile/);
      await expect(page.locator('#wdep-profile-repo')).toHaveValue('https://example.com/org/e2e-deploy.git');
      await expect(page.locator('#wdep-profile-webhook-secret')).toHaveValue('');
      await expect(page.locator('#wdep-profile-webhook-secret')).toHaveAttribute('placeholder', /Configured|已配置/);
      await expect(page.locator('#project-profile')).toContainText(/Project Secret|项目密钥/);
      await expect(page.locator('#wdep-profile-git-mode')).toHaveValue('pull_ff_only');
      await expect(page.locator('#configuration')).toContainText('https://example.com/org/e2e-deploy.git');
      await expect(page.locator('#configuration')).toContainText('pull_ff_only');
      await expect(page.locator('[data-wls-deploy-preflight]')).toHaveAttribute(
        'data-wls-deploy-preflight-status',
        /^(ok|warning)$/,
      );
      await expect(page.locator('[data-wls-deploy-preflight-check="profile"]')).toHaveAttribute('data-state', 'ok');
      await expect(page.locator('[data-wls-deploy-preflight-check="repo"]')).toHaveAttribute('data-state', 'ok');
      await expect(page.locator('[data-wls-deploy-preflight-check="trigger"]')).toHaveAttribute('data-state', 'ok');
      await expect(page.locator('[data-wls-deploy-preflight-check="commands"]')).toHaveAttribute('data-state', 'ok');
      await expect(page.locator('[data-wls-deploy-preflight-check="rollback"]')).toHaveAttribute('data-state', 'ok');
      await expect(page.locator('[data-wls-deploy-rollback]')).toHaveAttribute('data-ready', '1');
      await expect(page.locator('[data-wls-deploy-rollback-confirm]')).toBeVisible();
      await expect(page.locator('[data-wls-deploy-rollback-run]')).toBeEnabled();
      await expectNoHorizontalOverflow(page);

      await page.locator('#preflight').scrollIntoViewIfNeeded();
      await Promise.all([
        page.waitForURL(/deploy_notice=preflight_checked/i, { timeout: 30000 }),
        page.locator('[data-wls-deploy-preflight-run]').click(),
      ]);
      await expect(page.locator('.wdep-alert.is-ok')).toContainText(/预检|Preflight/);
      await expect(page.locator('[data-wls-deploy-preflight]')).toHaveAttribute(
        'data-wls-deploy-preflight-status',
        /^(ok|warning)$/,
      );

      await page.locator('#webhook-replay').scrollIntoViewIfNeeded();
      await expect(page.locator('[data-wls-webhook-replay]')).toBeVisible();
      await page.locator('[data-wls-webhook-replay-ref]').fill('refs/tags/v9.9.9');
      await Promise.all([
        page.waitForURL(/replay_status=ready/i, { timeout: 30000 }),
        page.locator('[data-wls-webhook-replay-run]').click(),
      ]);
      await expect(page.locator('.wdep-alert.is-ok')).toContainText(/Webhook|回放/);
      await expect(page.locator('[data-wls-webhook-replay-result]')).toHaveAttribute('data-status', 'ready');
      await expect(page.locator('[data-wls-webhook-replay-result]')).toContainText('v9.9.9');

      await page.locator('[data-wls-webhook-replay-ref]').fill('refs/heads/main');
      await Promise.all([
        page.waitForURL(/replay_status=skipped/i, { timeout: 30000 }),
        page.locator('[data-wls-webhook-replay-run]').click(),
      ]);
      await expect(page.locator('[data-wls-webhook-replay-result]')).toHaveAttribute('data-status', 'skipped');
      await expect(page.locator('[data-wls-webhook-replay-result]')).toHaveAttribute('data-reason', 'trigger_mode_tag_only');

      await page.locator('#manual-release-plan').scrollIntoViewIfNeeded();
      await expect(page.locator('[data-wls-manual-plan]')).toBeVisible();
      await page.locator('[data-wls-manual-plan-ref]').fill('refs/tags/v9.9.9');
      await Promise.all([
        page.waitForURL(/manual_status=ready/i, { timeout: 30000 }),
        page.locator('[data-wls-manual-plan-run]').click(),
      ]);
      await expect(page.locator('.wdep-alert.is-ok')).toContainText(/Manual release plan/);
      await expect(page.locator('[data-wls-manual-plan-result]')).toHaveAttribute('data-status', 'ready');
      await expect(page.locator('[data-wls-manual-plan-result]')).toContainText('v9.9.9');
      await expect(page.locator('[data-wls-manual-plan-steps]')).toContainText(/Dry-run boundary/);
      await page.locator('#manual-release-plan').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-manual-plan-desktop.png'),
        fullPage: false,
      });

      await page.locator('#webhook-replay').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-webhook-replay-desktop.png'),
        fullPage: false,
      });

      await page.locator('#preflight').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-preflight-desktop.png'),
        fullPage: false,
      });

      await page.screenshot({
        path: artifactPath('wls-deploy-profile-desktop.png'),
        fullPage: true,
      });

      await page.locator('[data-wdep-theme-toggle]').click();
      await expect(shell).toHaveAttribute('data-theme', 'dark');
      await expectNoHorizontalOverflow(page);

      await page.setViewportSize({ width: 390, height: 844 });
      await gotoBackend(page, deployRoute(), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });
      await expect(page.locator('.wls-deploy-shell[data-wls-deploy-shell]')).toBeVisible();
      await expect(page.locator('#project-profile')).toBeVisible();
      await expect(page.locator('[data-wls-deploy-preflight]')).toBeVisible();
      await expect(page.locator('[data-wls-deploy-rollback]')).toBeVisible();
      await expect(page.locator('[data-wls-webhook-replay]')).toBeVisible();
      await expect(page.locator('[data-wls-manual-plan]')).toBeVisible();
      await expect(page.locator('#wdep-profile-repo')).toHaveValue('https://example.com/org/e2e-deploy.git');
      await expectNoHorizontalOverflow(page);
      await page.locator('#manual-release-plan').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-manual-plan-mobile.png'),
        fullPage: false,
      });
      await page.locator('#webhook-replay').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-webhook-replay-mobile.png'),
        fullPage: false,
      });
      await page.locator('#preflight').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-preflight-mobile.png'),
        fullPage: false,
      });
      await page.locator('#project-profile').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-deploy-profile-mobile.png'),
        fullPage: true,
      });
    },
  );
});
