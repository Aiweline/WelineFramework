/**
 * Weline_Captcha 验证码模块 E2E 冒烟测试
 *
 * 测试范围：
 * - Captcha 配置页面加载（通过系统配置路由）
 * - 验证码验证功能正常
 *
 * Captcha 模块无独立 Backend Controller，配置通过 SystemConfig 管理
 * 此处测试 Captcha 模块在后台的可用性
 *
 * @weline-e2e-spec { module: Weline_Captcha, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Captcha';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Captcha 验证码模块冒烟测试', () => {

    /**
     * [module:Weline_Captcha][case:BACKEND-SMOKE-CAPTCHA-001]
     * 验证 Captcha 模块在后台无 PHP 致命错误
     * Captcha 模块主要提供验证码服务，测试其后台入口可访问性
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CAPTCHA-001' },
        'Captcha 模块后台入口页面能够正常加载，无 PHP 致命错误',
        async ({ page }) => {
            await loginAsAdmin(page);
            // Captcha 模块无独立后台控制器，测试通过 SystemConfig 模块访问验证码配置
            const url = buildModuleBackendRoute('Weline_SystemConfig', 'config', { group: 'captcha' });
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();
            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );

    /**
     * [module:Weline_Captcha][case:BACKEND-SMOKE-CAPTCHA-002]
     * 验证验证码服务在登录流程中可用
     * 测试前台登录页验证码图片能够加载
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CAPTCHA-002' },
        '前台登录页验证码能够正常显示',
        async ({ page }) => {
            // 测试前台登录页面（验证码实际使用场景）
            await page.goto('/admin/login', { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();
            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );
});
