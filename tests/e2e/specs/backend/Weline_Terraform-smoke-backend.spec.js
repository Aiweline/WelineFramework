/**
 * Weline_Terraform Terraform部署 E2E 冒烟测试
 *
 * 测试范围：
 * - Terraform域名管理
 *
 * @weline-e2e-spec { module: Weline_Terraform, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Terraform';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Terraform Terraform部署模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'TERRAFORM-SMOKE-001' },
    'Terraform域名管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'terraform/domain');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含Terraform相关内容
      const content = await body.innerText();
      expect(content).toMatch(/Terraform|域名|Domain|部署|Deploy/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
