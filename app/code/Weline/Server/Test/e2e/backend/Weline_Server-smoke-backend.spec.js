/**
 * Weline_Server WLS服务器管理 E2E 冒烟测试
 *
 * 测试范围：
 * - ServerManager 服务器管理：管理页面可访问
 * - SslCertificate SSL证书管理：证书页面可访问
 * - ServerMonitor 服务器监控：监控页面可访问
 *
 * 说明：
 * - Weline_Server 模块主要是 CLI 驱动的，后台页面有限
 * - 服务器的实际启动/停止/重载等操作需要 WLS 服务运行
 * - 本模块主要测试后台页面的可访问性和无Fatal错误
 *
 * 控制器来源：app/code/Weline/Server/Controller/Backend/*.php
 *
 * @weline-e2e-spec { module: Weline_Server, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Server';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Server WLS服务器管理冒烟测试', () => {

  // ========== ServerManager 服务器管理 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-MANAGER-001' },
    '服务器管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'server-manager');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);

      // 页面不应重定向到登录页
      expect(page.url()).not.toContain('/admin/login');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-MANAGER-002' },
    'Session管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'server-manager/session');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-MANAGER-003' },
    '内存服务管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'server-manager/memory');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  // ========== SslCertificate SSL证书 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-SSL-001' },
    'SSL证书管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'ssl-certificate');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  // ========== ServerMonitor 服务器监控 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-MONITOR-001' },
    '服务器监控页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'server-monitor');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  // ========== SseTest SSE测试 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-SSETEST-001' },
    'SSE测试页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'sse-test');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  // ========== OptimizationGuide 优化指南 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'SERVER-SMOKE-OPTIMIZATION-001' },
    '优化指南页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'optimization-guide');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
