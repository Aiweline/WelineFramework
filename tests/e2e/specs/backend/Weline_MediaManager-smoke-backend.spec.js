/**
 * Weline_MediaManager 媒体管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 图片管理：媒体库图片列表、上传
 * - 路由管理：媒体路由配置
 * - 连接器管理：外部存储连接器配置
 *
 * 控制器来源：app/code/Weline/MediaManager/Controller/Backend/Media/*.php
 * 模板来源：app/code/Weline/MediaManager/view/templates/Backend/Media/*.phtml
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_MediaManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_MediaManager 媒体管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-SMOKE-001' },
    '图片管理页面能够正常加载，显示媒体库标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'media/image');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/媒体|图片|Media|Image/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-SMOKE-002' },
    '媒体管理页面包含上传或管理按钮',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'media/image');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含上传或管理相关按钮
      const hasAction = /上传|Upload|添加|Add|管理|Manage/i.test(content);
      expect(hasAction).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-SMOKE-003' },
    '媒体路由页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'media/router');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
