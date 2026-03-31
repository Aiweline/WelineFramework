/**
 * Weline_Currency 货币管理模块 E2E 冒烟测试
 *
 * 测试范围：
 * - 货币列表：货币管理页面加载、货币数据展示
 * - 货币配置：汇率配置页面、基准货币设置
 *
 * 控制器来源：app/code/Weline/Currency/Controller/Backend/
 * - Currency.php: 货币管理 (index, getAdd, getEdit, postAdd, postEdit, postDelete)
 * - Config.php: 货币配置 (index, testApi, import, postUpdateRates)
 *
 * 模板字段来源：view/templates/Backend/Currency/index.phtml, Config/index.phtml
 *
 * @weline-e2e-spec { module: Weline_Currency, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Currency';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Currency 货币管理模块冒烟测试', () => {

    /**
     * [module:Weline_Currency][case:BACKEND-SMOKE-CURRENCY-001]
     * 货币列表页面验证
     * 验证货币管理列表页能加载，显示货币数据表格
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CURRENCY-001' },
        '货币列表页面能够正常加载，显示货币管理标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'currency');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含货币管理标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/货币|currency/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );

    /**
     * [module:Weline_Currency][case:BACKEND-SMOKE-CURRENCY-002]
     * 货币配置页面验证
     * 验证货币配置页能加载，显示配置项
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CURRENCY-002' },
        '货币配置页面能够正常加载，显示配置项标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'config');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含配置相关标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/货币|配置|currency|config/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );
});
