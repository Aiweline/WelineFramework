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

function deployBackendRoute(...segments) {
  return buildModuleBackendRoute(MODULE, ...segments);
}

moduleDescribe(test, MODULE, 'Release management (发布管理)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, {
      timeout: 90000,
      bootstrapOnly: true,
      bootstrapModes: ['wls', 'fpm'],
      useProxy: false,
    });
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.1-01' },
    'release control page renders without fatal errors',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('release-control'), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });
      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('.weline-release-control')).toBeVisible();
      await expect(page.getByRole('heading', { name: /发布控制/ })).toBeVisible();
      await page.screenshot({ path: artifactPath('release-control-index.png'), fullPage: true });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.1-02' },
    'release history page renders with rollback affordance',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('release'), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });
      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('.weline-release-history')).toBeVisible();
      await expect(page.getByRole('heading', { name: /发布历史/ })).toBeVisible();
      await page.screenshot({ path: artifactPath('release-history-index.png'), fullPage: true });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.1-03' },
    'core update page renders default branch and protected paths',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('core-update'), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });
      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('.weline-core-update')).toBeVisible();
      await expect(page.getByText(/受保护路径/)).toBeVisible();
      await expect(page.locator('#wcu-branch')).toBeVisible();
      await expect(page.locator('#wcu-confirm')).toBeVisible();
      await expect(page.locator('#wcu-submit')).toBeDisabled();
      await page.screenshot({ path: artifactPath('core-update-index.png'), fullPage: true });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.2-01' },
    'release control loads branch list via API',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('release-control'), {
        timeout: 30000,
        settleMs: 800,
        useProxy: false,
      });
      await expect(page.locator('#wrc-branch')).toBeVisible();
      await page.waitForTimeout(2500);
      const branchOptions = page.locator('#wrc-branch option');
      const count = await branchOptions.count();
      expect(count).toBeGreaterThan(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.2-02' },
    'release control shows commits table and tags tab',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('release-control'), {
        timeout: 30000,
        settleMs: 800,
        useProxy: false,
      });
      await page.locator('#wrc-refresh-commits').click();
      await page.waitForTimeout(2500);
      await expect(page.locator('#wrc-commits-table')).toBeVisible();
      await page.locator('#wrc-tab-tags').click();
      await page.locator('#wrc-refresh-tags').click();
      await page.waitForTimeout(2500);
      await expect(page.locator('#wrc-tags-table')).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.2-03' },
    'release confirm modal requires older-version acknowledgement',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('release-control'), {
        timeout: 30000,
        settleMs: 1200,
        useProxy: false,
      });
      await page.locator('#wrc-refresh-commits').click();
      await page.waitForTimeout(2500);
      const publishBtn = page.locator('[data-action="release-commit"]').first();
      if (!(await publishBtn.isVisible().catch(() => false))) {
        test.skip(true, 'No commits available (project repo may be unconfigured)');
        return;
      }
      await publishBtn.click();
      const modal = page.locator('#wrc-release-modal');
      await expect(modal).toBeVisible();
      await expect(page.locator('#wrc-confirm-release')).toBeVisible();
      await expect(page.locator('#wrc-run-release')).toBeDisabled();
      await page.locator('#wrc-confirm-release').check();
      const olderWrapVisible = await page.locator('#wrc-older-confirm-wrap').isVisible().catch(() => false);
      if (olderWrapVisible) {
        await expect(page.locator('#wrc-confirm-older')).toBeVisible();
        await expect(page.locator('#wrc-run-release')).toBeDisabled();
        await page.locator('#wrc-confirm-older').check();
      }
      await expect(page.locator('#wrc-run-release')).toBeEnabled();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'RM-10.4-01' },
    'core update submit stays disabled until confirm checkbox',
    async ({ page }) => {
      await gotoBackend(page, deployBackendRoute('core-update'), {
        timeout: 30000,
        settleMs: 500,
        useProxy: false,
      });
      await expect(page.locator('#wcu-submit')).toBeDisabled();
      await page.locator('#wcu-confirm').check();
      await expect(page.locator('#wcu-submit')).toBeEnabled();
    }
  );
});
