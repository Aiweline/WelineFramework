/**
 * Weline_Code 代码模块 E2E 冒烟测试
 *
 * 测试范围：
 * - Weline_Code 模块为 Console 命令模块，无 Backend Controller
 * - 模块注册正常，加载无 PHP 致命错误
 *
 * 代码来源：app/code/Weline/Code/
 * - register.php: 模块注册
 * - Console/Code/Repaire.php: 代码修复命令
 *
 * @weline-e2e-spec { module: Weline_Code, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Code';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Code 代码模块冒烟测试', () => {

    /**
     * [module:Weline_Code][case:BACKEND-SMOKE-CODE-001]
     * Weline_Code 模块为 Console 命令模块，无独立 Backend Controller
     * 此测试验证模块在框架中加载无错误
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CODE-001' },
        'Code 模块后台入口存在，无 PHP 致命错误（模块为Console命令模块）',
        async ({ page }) => {
            await loginAsAdmin(page);

            // Weline_Code 无 Backend Controller，尝试访问模块根路由
            const url = buildModuleBackendRoute(MODULE);
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证无 Fatal 错误（即使页面显示404，模块本身应无错误）
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );
});
