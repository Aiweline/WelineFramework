/**
 * Weline_Queue 队列管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 队列列表：消息队列状态、任务列表
 *
 * 控制器来源：app/code/Weline/Queue/Controller/Backend/Queue.php
 * 模板来源：app/code/Weline/Queue/view/templates/Backend/Queue/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Queue, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Queue';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Queue 队列管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'QUEUE-SMOKE-001' },
    '队列列表页面能够正常加载，显示队列状态和统计',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'queue');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含队列相关内容
      const content = await body.innerText();
      expect(content).toMatch(/队列|Queue|消息|Message|任务|Job/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'QUEUE-SMOKE-002' },
    '队列列表包含统计信息或状态指示',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'queue');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含统计或状态相关
      const hasStats = /总计|Total|等待|Pending|运行|Running|完成|Done|错误|Error/i.test(content);
      expect(hasStats).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
