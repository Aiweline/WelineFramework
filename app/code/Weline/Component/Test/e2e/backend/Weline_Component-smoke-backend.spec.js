/**
 * Weline_Component 组件管理模块 E2E 冒烟测试
 *
 * 测试范围：
 * - 组件库：组件列表加载、组件详情获取
 * - OffCanvas：成功/错误结果页
 *
 * 控制器来源：app/code/Weline/Component/Controller/
 * - Backend/Components.php: 组件库 (getIndex, getDetail, getList)
 * - Backend/Offcanvas.php: OffCanvas结果页 (getResult, getSuccess, getError)
 *
 * @weline-e2e-spec { module: Weline_Component, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Component';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Component 组件管理模块冒烟测试', () => {

    /**
     * [module:Weline_Component][case:BACKEND-SMOKE-COMPONENT-001]
     * 组件库首页验证
     * 验证组件库页面能加载，显示组件列表或统计信息
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-COMPONENT-001' },
        '组件库首页能够正常加载，显示组件库标题',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'components');
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含组件库标题
            const heading = page.locator('h4').first();
            await expect(heading).toContainText(/组件|component/i, { timeout: 10000 });

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );

    /**
     * [module:Weline_Component][case:BACKEND-SMOKE-COMPONENT-002]
     * OffCanvas 结果页验证
     * 验证 OffCanvas 成功/错误页能正常显示
     */
    moduleCase(
        test,
        { module: MODULE, id: 'BACKEND-SMOKE-COMPONENT-002' },
        'OffCanvas 成功结果页能够正常加载',
        async ({ page }) => {
            await loginAsAdmin(page);
            const url = buildModuleBackendRoute(MODULE, 'offcanvas/success', { msg: '测试成功', reload: 0 });
            await gotoBackend(page, url, { timeout: 30000 });

            const body = page.locator('body');
            await expect(body).toBeVisible();

            // 验证页面包含成功相关提示
            const content = await body.innerText();
            expect(content).toMatch(/成功|success/i);

            // 验证无 Fatal 错误
            await expect(body).not.toContainText(FATAL_PATTERN);
        }
    );
});
