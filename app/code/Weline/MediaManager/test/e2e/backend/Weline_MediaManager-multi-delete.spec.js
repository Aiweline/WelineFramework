/**
 * Multi-select delete must keep targets as a real array (Worker + FormData fallback).
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_MediaManager';

moduleDescribe(test, MODULE, 'Weline_MediaManager multi-delete', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-MULTI-RM-001' },
    '多选删除不再提示未选择删除项目',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await page.waitForFunction(() => {
        return !document.querySelector('.mmf-loading')
          && !!document.querySelector('.mmf-path-seg')
          && !!document.querySelector('#mmf-btn-newfolder')
          && document.querySelector('#mmf-btn-newfolder')?.disabled === false;
      });

      const stamp = Date.now().toString(36);
      const names = [`mmf-rm-a-${stamp}`, `mmf-rm-b-${stamp}`];
      const item = (name) => page.locator('.mmf-grid .mmf-item').filter({ hasText: name });

      const makeFolder = async (name) => {
        await page.locator('#mmf-btn-newfolder').click();
        await expect(page.locator('.mmf-dialog-overlay')).toHaveClass(/visible/);
        await page.locator('.mmf-dialog-input').fill(name);
        await page.locator('.mmf-dialog-ok').click();
        await expect(item(name)).toBeVisible();
      };

      const removeVisibleItemBestEffort = async (name) => {
        const target = item(name).first();
        if (!(await target.isVisible().catch(() => false))) return;
        await target.click().catch(() => {});
        await page.locator('#mmf-btn-delete').click().catch(() => {});
        const confirm = page.locator('.mmf-dialog-overlay.visible .mmf-dialog-ok');
        if (await confirm.isVisible().catch(() => false)) await confirm.click().catch(() => {});
        await page.waitForTimeout(250);
      };

      try {
        await makeFolder(names[0]);
        await makeFolder(names[1]);

        await item(names[0]).click();
        await item(names[1]).click({
          modifiers: process.platform === 'darwin' ? ['Meta'] : ['Control'],
        });
        await expect(page.locator('#mmf-btn-delete')).toBeEnabled();

        await page.evaluate(() => {
          window.__mmfRmPayloads = [];
          const original = XMLHttpRequest.prototype.send;
          window.__mmfOriginalXhrSend = original;
          XMLHttpRequest.prototype.send = function (body) {
            if (body instanceof FormData && body.get('cmd') === 'rm') {
              window.__mmfRmPayloads.push(body.getAll('targets[]'));
            }
            return original.call(this, body);
          };
        });

        await page.locator('#mmf-btn-delete').click();
        await expect(page.locator('.mmf-dialog-overlay')).toHaveClass(/visible/);
        await expect(page.locator('.mmf-dialog-overlay')).toContainText(/2|两项|删除/);
        await page.locator('.mmf-dialog-ok').click();

        await expect(item(names[0])).toHaveCount(0);
        await expect(item(names[1])).toHaveCount(0);
        await expect(page.locator('body')).not.toContainText('未选择删除项目');
        await expect(page.locator('body')).not.toContainText('Unknown frontend worker param');

        const formTargets = await page.evaluate(() => window.__mmfRmPayloads || []);
        for (const targets of formTargets) {
          expect(Array.isArray(targets)).toBe(true);
          expect(targets.length).toBeGreaterThanOrEqual(2);
        }
      } finally {
        await page.evaluate(() => {
          if (window.__mmfOriginalXhrSend) {
            XMLHttpRequest.prototype.send = window.__mmfOriginalXhrSend;
            delete window.__mmfOriginalXhrSend;
          }
        }).catch(() => {});
        await removeVisibleItemBestEffort(names[0]);
        await removeVisibleItemBestEffort(names[1]);
      }
    },
  );
});
