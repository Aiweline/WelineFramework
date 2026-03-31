/**
 * Weline_Bot 机器人管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 机器人角色管理：角色列表、权限配置
 * - 机器人调度：任务调度配置
 *
 * 控制器来源：app/code/Weline/Bot/Controller/Backend/Role.php, Schedule.php
 * 模板来源：app/code/Weline/Bot/view/templates/Backend/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Bot, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Bot';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Bot 机器人管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'BOT-SMOKE-001' },
    '机器人角色管理页面能够正常加载，显示角色管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'bot');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/机器人|Bot|角色|Role/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BOT-SMOKE-002' },
    '机器人角色列表包含角色数据或统计',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'bot');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含角色相关内容
      const hasRoleContent = /角色|Role|权限|Permission|机器人|Bot/i.test(content);
      expect(hasRoleContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BOT-SMOKE-003' },
    '机器人调度页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'bot/schedule');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
