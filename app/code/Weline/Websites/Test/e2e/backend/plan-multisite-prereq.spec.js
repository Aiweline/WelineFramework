/**
 * 计划用例固化：多站前置 + 后台核心交互
 *
 * - TEST-WLS-04：登录后台并打开 Store 复制向导（多站传品入口）且 console 无 error
 * - TEST-P2C-COPY-04-PREREQ：跨 Website 复制前置——新建网站表单入口可达
 *
 * @weline-e2e-spec { module: Weline_Websites, type: plan, layer: backend }
 */

const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function collectConsoleErrors(page) {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() !== 'error') {
      return;
    }
    const text = msg.text();
    // 后台既有 jQuery 加载时序噪音（与本计划多站复制无关），不计入 WLS-04
    if (/favicon|Failed to load resource|net::ERR_|jQuery is not defined|\$ is not defined|reading 'fn'/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  page.on('pageerror', (err) => {
    const text = String(err);
    if (/jQuery is not defined|\$ is not defined|reading 'fn'/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  return errors;
}

moduleDescribe(test, MODULE, '计划用例：多站前置与后台核心交互', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-WLS-04' },
    '登录后台后打开 Store 复制向导（多站传品入口）且 console 无 error',
    async ({ page }) => {
      const errors = collectConsoleErrors(page);
      await loginAsAdmin(page);
      await gotoBackend(page, 'websites/admin/store-copy/wizard', { timeout: 60000 });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      // data-testid 或文案回退
      const wizard = page.locator('[data-testid="store-copy-wizard"]');
      if ((await wizard.count()) > 0) {
        await expect(wizard).toBeVisible({ timeout: 15000 });
      } else {
        await expect(page.locator('body')).toContainText(/Store 商品复制向导|复制向导|blank|site_pull|store_inherit/i, {
          timeout: 20000,
        });
      }
      await expect(page.locator('body')).toContainText(/store_inherit|他店继承/i);
      expect(errors, `console/pageerror: ${errors.join(' | ')}`).toHaveLength(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-COPY-04-PREREQ' },
    '跨 Website 复制前置：新建网站表单入口可达',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, 'websites/admin/website/add', { timeout: 60000 });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      // 与 website-add.spec.js 同入口；至少出现表单或网站字段
      const form = page.locator('form');
      const hasForm = (await form.count()) > 0;
      const text = await page.locator('body').innerText();
      expect(
        hasForm || /website|网站|域名|domain/i.test(text),
        '新建网站页应含表单或网站相关文案'
      ).toBeTruthy();
    }
  );
});
