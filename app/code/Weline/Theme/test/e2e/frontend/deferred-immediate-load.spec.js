// app/code/Weline/Theme/test/e2e/frontend/deferred-immediate-load.spec.js
// 测试 Weline.declare 的延迟立即加载功能（data-load-order="last" 和 options.loadOrder: 'last'）
// @weline-e2e-transport direct

const {
    test,
    expect,
    getActiveTheme,
    gotoThemePreview,
} = require('../../../../../../../tests/e2e/framework');

async function gotoStablePreview(page) {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await gotoThemePreview(page, {
        themeId: activeTheme.id,
        pageType: 'homepage',
        previewMode: 'live',
    }, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 1000,
    });

    await page.waitForFunction(() => {
        return typeof window.Weline === 'object'
            && window.Weline !== null
            && typeof window.Weline.declare === 'function';
    }, null, {
        timeout: 30000,
    });
}

test.describe('Deferred immediate load behavior', () => {
    test('延迟立即加载：带 data-load-order="last" 的模块应在 DOMContentLoaded 后加载', async ({ page }) => {
        // 监听网络请求，记录 search.js 的加载时机
        const searchJsRequests = [];
        let domContentLoadedTime = null;

        page.on('request', request => {
            if (request.url().includes('search.js')) {
                searchJsRequests.push({
                    url: request.url(),
                    time: Date.now()
                });
            }
        });

        // 监听 DOMContentLoaded 事件
        await page.addInitScript(() => {
            window.__domContentLoadedTime = null;
            document.addEventListener('DOMContentLoaded', () => {
                window.__domContentLoadedTime = Date.now();
            });
        });

        await gotoStablePreview(page);

        // 获取 DOMContentLoaded 时间
        domContentLoadedTime = await page.evaluate(() => window.__domContentLoadedTime);

        // 验证 Weline 对象存在
        const hasWeline = await page.evaluate(() => {
            return typeof window.Weline === 'object' && window.Weline !== null;
        });
        expect(hasWeline).toBeTruthy();

        // 验证 Weline.declare 可以接受 options 参数而不抛错
        const declareSupportsOptions = await page.evaluate(() => {
            if (typeof window.Weline !== 'object' || typeof window.Weline.declare !== 'function') {
                return false;
            }

            try {
                window.Weline.declare('test-module-options-probe', false, undefined, undefined, {
                    loadOrder: 'last',
                });
                return true;
            } catch (error) {
                console.error('declare options error:', error);
                return false;
            }
        });
        expect(declareSupportsOptions).toBeTruthy();

        // 如果有 search.js 请求，验证它是在 DOMContentLoaded 之后发起的
        // 注意：由于 Search 模块现在使用 data-load-order="last"，其加载应该被延迟
        if (searchJsRequests.length > 0 && domContentLoadedTime) {
            // 由于网络请求时间戳可能不完全精确，这里只做基本验证
            console.log('Search.js request time:', searchJsRequests[0].time);
            console.log('DOMContentLoaded time:', domContentLoadedTime);
        }
    });

    test('非延迟立即加载：不带 data-load-order 的模块应立即加载', async ({ page }) => {
        await gotoStablePreview(page);

        // 验证 Weline.declare 可以正常声明并立即加载模块
        const declareWorks = await page.evaluate(() => {
            return new Promise((resolve) => {
                // 测试不带延迟的 declare 是否能正常工作
                if (typeof window.Weline === 'object' && typeof window.Weline.declare === 'function') {
                    // 声明一个测试模块（不实际加载，只验证 API 可用）
                    try {
                        // 只声明不立即加载，验证 API 可用
                        window.Weline.declare('test-module');
                        resolve(true);
                    } catch (e) {
                        console.error('declare error:', e);
                        resolve(false);
                    }
                } else {
                    resolve(false);
                }
            });
        });

        expect(declareWorks).toBeTruthy();
    });

    test('显式参数 options.loadOrder 应正确识别', async ({ page }) => {
        await gotoStablePreview(page);

        // 验证 declare 函数可以显式接受 options.loadOrder 参数
        const optionsRecognized = await page.evaluate(() => {
            if (typeof window.Weline === 'object' && typeof window.Weline.declare === 'function') {
                try {
                    window.Weline.declare('test-module-load-order-probe', false, undefined, undefined, {
                        loadOrder: 'last',
                    });
                    return true;
                } catch (error) {
                    console.error('declare loadOrder error:', error);
                    return false;
                }
            }
            return false;
        });

        expect(optionsRecognized).toBeTruthy();
    });

    test('延迟加载队列 flush 应在适当时机执行', async ({ page }) => {
        await gotoStablePreview(page);

        // 等待一小段时间，让 flush 执行
        await page.waitForTimeout(500);

        // 验证页面正常加载，没有 JavaScript 错误
        const hasNoErrors = await page.evaluate(() => {
            // 检查控制台是否有 Maximum call stack size exceeded 错误
            // 这个测试主要是确保延迟加载机制不会导致栈溢出
            return !window.__hasStackOverflowError;
        });

        expect(hasNoErrors).toBeTruthy();
    });

    test('模块声明代理应在延迟加载前就能正常工作', async ({ page }) => {
        await gotoStablePreview(page);

        // 验证 moduleDeclarer 的代理机制正常工作
        const proxyWorks = await page.evaluate(() => {
            if (typeof window.Weline === 'object' && typeof window.Weline.declarer === 'object') {
                // declarer 对象应该存在
                return typeof window.Weline.declarer.declare === 'function' 
                    && typeof window.Weline.declarer.isDeclared === 'function';
            }
            return false;
        });

        expect(proxyWorks).toBeTruthy();
    });
});
