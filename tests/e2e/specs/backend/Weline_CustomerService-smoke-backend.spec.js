/**
 * Weline_CustomerService 客服模块 E2E 冒烟测试
 *
 * 测试范围：
 * - 会话管理：会话列表、会话详情、关闭会话
 * - 客服配置：配置页面保存
 * - 客服人员：人员列表、人员管理
 *
 * 控制器来源：app/code/Weline/CustomerService/Controller/Backend/
 * - Session.php: 会话管理 (index, view, postClose)
 * - Config.php: 客服配置 (index, postSave)
 * - Agent.php: 客服人员管理 (index, postSave, postRemove, getAgentStatistics)
 *
 * @weline-e2e-spec { module: Weline_CustomerService, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_CustomerService';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_CustomerService 客服模块冒烟测试', () => {

    /**
     * [module:Weline_CustomerService][case:BACKEND-SMOKE-CUSTOMERSERVICE-001]
     * 客服会话列表页面验证
     * 验证会话管理页能加载，显示会话列表或空状态
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CUSTOMERSERVICE-001' },
        '客服会话列表页面能够正常加载，显示会话管理标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'session');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含会话管理标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/会话|客服|customer.?service/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );

    /**
     * [module:Weline_CustomerService][case:BACKEND-SMOKE-CUSTOMERSERVICE-002]
     * 客服配置页面验证
     * 验证配置页能加载，显示配置表单
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CUSTOMERSERVICE-002' },
        '客服配置页面能够正常加载，显示配置标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'config');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含配置标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/客服|配置/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );

    /**
     * [module:Weline_CustomerService][case:BACKEND-SMOKE-CUSTOMERSERVICE-003]
     * 客服人员列表页面验证
     * 验证客服人员管理页能加载，显示人员列表
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-CUSTOMERSERVICE-003' },
        '客服人员列表页面能够正常加载，显示人员管理标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'agent');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含客服人员管理标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/客服|人员|agent/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );
});
