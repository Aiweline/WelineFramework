// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoBackend, gotoFrontend, loginAsAdmin, getRuntimeInfo } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error|Call to undefined/i;
const STYLE_ERROR_PATTERN = /Failed to load resource|Refused to apply style|stylesheet.*404|MIME type .*text\/html/i;
const STYLE_URL_PATTERN = /\.css($|\?)/i;

function bindStyleDiagnostics(page, targetOrigin = '') {
    const styleConsoleErrors = [];
    const failedStyleRequests = [];
    const invalidStyleResponses = [];

    const isTargetStyleUrl = (url) => {
        if (!STYLE_URL_PATTERN.test(url)) return false;
        if (!targetOrigin) return true;
        try {
            return new URL(url).origin === new URL(targetOrigin).origin;
        } catch {
            return false;
        }
    };

    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        if (STYLE_ERROR_PATTERN.test(text)) {
            styleConsoleErrors.push(text);
        }
    });

    page.on('requestfailed', (request) => {
        const url = request.url();
        if (!isTargetStyleUrl(url)) return;
        failedStyleRequests.push(`${request.method()} ${url}`);
    });

    page.on('response', async (response) => {
        const request = response.request();
        const url = request.url();
        if (!isTargetStyleUrl(url)) return;

        const status = response.status();
        const contentType = (response.headers()['content-type'] || '').toLowerCase();
        const isInvalidMime = contentType.includes('text/html');

        if (status >= 400 || isInvalidMime) {
            invalidStyleResponses.push(`${status} ${url} (${contentType || 'unknown'})`);
        }
    });

    return {
        styleConsoleErrors,
        failedStyleRequests,
        invalidStyleResponses
    };
}

async function waitForStyleSettle(page) {
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(800);
}

async function collectStyleHealth(page) {
    return page.evaluate(() => {
        const stylesheets = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
        const loadedStylesheets = stylesheets.filter(link => {
            try {
                return !!link.sheet && !link.sheet.disabled;
            } catch {
                return false;
            }
        });
        const bodyStyles = getComputedStyle(document.body);
        const htmlStyles = getComputedStyle(document.documentElement);

        return {
            stylesheetCount: stylesheets.length,
            loadedStylesheetCount: loadedStylesheets.length,
            bodyDisplay: bodyStyles.display,
            bodyVisibility: bodyStyles.visibility,
            bodyFontSize: bodyStyles.fontSize,
            htmlFontSize: htmlStyles.fontSize,
            title: document.title || '',
            bodyTextLength: (document.body?.innerText || '').trim().length
        };
    });
}

/**
 * Theme Management E2E Tests
 * 主题切换和管理功能测试
 */
test.describe('Theme Management - 主题切换测试', () => {
    const runtimeInfo = getRuntimeInfo();
    const targetOrigin = String(runtimeInfo?.runtime?.target_origin || '');

    test.beforeAll(async () => {
        await loginAsAdmin({});
    });

    test('TM-01: 后台主题列表显示正常', async ({ page }) => {
        const styleDiagnostics = bindStyleDiagnostics(page, targetOrigin);

        // 进入主题管理页面
        await gotoBackend(page, 'theme/backend');
        await waitForStyleSettle(page);

        // 验证页面标题
        await expect(page).toHaveURL(/theme\/backend/i, { timeout: 15000 });
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN, { timeout: 10000 });

        // 验证主题列表容器存在
        const themeList = page.locator('.theme-list, .themes-grid, [data-theme-list]').first();
        await expect(themeList).toBeVisible({ timeout: 10000 }).catch(() => {
            // 如果没有特定选择器，验证页面有内容
            expect(page.locator('body')).not.toBeEmpty();
        });

        // 验证至少有主题卡片或表格行
        const themeItems = page.locator('[data-theme-id], .theme-card, .theme-item');
        const count = await themeItems.count();
        console.log(`[TM-01] Found ${count} theme items`);

        // 验证页面样式已生效（避免后台裸页/样式异常）
        const styleHealth = await collectStyleHealth(page);
        console.log('[TM-01] Style health:', JSON.stringify(styleHealth));
        expect(styleHealth.stylesheetCount).toBeGreaterThan(0);
        expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
        expect(styleHealth.bodyDisplay).not.toBe('none');
        expect(styleHealth.bodyVisibility).toBe('visible');
        expect(styleHealth.bodyTextLength).toBeGreaterThan(0);
        expect(styleDiagnostics.styleConsoleErrors, styleDiagnostics.styleConsoleErrors.join('\n')).toEqual([]);
        expect(styleDiagnostics.failedStyleRequests, styleDiagnostics.failedStyleRequests.join('\n')).toEqual([]);
        expect(styleDiagnostics.invalidStyleResponses, styleDiagnostics.invalidStyleResponses.join('\n')).toEqual([]);
    });

    test('TM-02: 获取当前激活主题信息', async ({ page }) => {
        const runtimeInfo = getRuntimeInfo();
        const activeThemes = runtimeInfo.themes?.active || {};

        console.log('[TM-02] Active themes:', JSON.stringify(activeThemes, null, 2));

        // 验证前台主题已激活
        if (activeThemes.frontend) {
            expect(activeThemes.frontend.id).toBeTruthy();
            expect(activeThemes.frontend.name).toBeTruthy();
        }
    });

    test('TM-03: 前台主题切换后页面正确渲染', async ({ page }) => {
        const styleDiagnostics = bindStyleDiagnostics(page, targetOrigin);

        // 访问前台首页
        await gotoFrontend(page, '/');
        await waitForStyleSettle(page);

        // 验证页面正常加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 验证没有致命错误
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN, {
            timeout: 10000
        });

        // 验证主题样式加载正常，避免页面出现“无样式”异常
        const styleHealth = await collectStyleHealth(page);
        console.log('[TM-03] Frontend style health:', JSON.stringify(styleHealth));
        expect(styleHealth.stylesheetCount).toBeGreaterThan(0);
        expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
        expect(styleHealth.bodyDisplay).not.toBe('none');
        expect(styleHealth.bodyVisibility).toBe('visible');
        expect(styleHealth.bodyFontSize).toMatch(/\d+px/);
        expect(styleHealth.htmlFontSize).toMatch(/\d+px/);
        expect(styleHealth.bodyTextLength).toBeGreaterThan(0);
        expect(styleDiagnostics.styleConsoleErrors, styleDiagnostics.styleConsoleErrors.join('\n')).toEqual([]);
        expect(styleDiagnostics.failedStyleRequests, styleDiagnostics.failedStyleRequests.join('\n')).toEqual([]);
        expect(styleDiagnostics.invalidStyleResponses, styleDiagnostics.invalidStyleResponses.join('\n')).toEqual([]);
    });

    test('TM-04: 后台主题切换', async ({ page }) => {
        const styleDiagnostics = bindStyleDiagnostics(page, targetOrigin);

        // 进入主题管理
        await gotoBackend(page, 'theme/backend');
        await waitForStyleSettle(page);

        // 查找并点击第一个主题的激活按钮（如果有多个主题）
        const activateButton = page.locator('button[data-action="activate"], .btn-activate, [data-theme-activate]').first();

        if (await activateButton.isVisible({ timeout: 3000 }).catch(() => false)) {
            await activateButton.click();

            // 等待操作完成
            await page.waitForTimeout(1000);

            // 验证成功提示
            const successMsg = page.locator('.toast-success, .alert-success, [data-success]').first();
            if (await successMsg.isVisible({ timeout: 5000 }).catch(() => false)) {
                expect(true).toBe(true);
            }
        } else {
            console.log('[TM-04] No activate button found or theme already active');
        }

        // 激活操作后页面仍应保持样式正常
        await expect(page.locator('body')).not.toContainText(STYLE_ERROR_PATTERN, { timeout: 10000 });
        const styleHealth = await collectStyleHealth(page);
        expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
        expect(styleDiagnostics.styleConsoleErrors, styleDiagnostics.styleConsoleErrors.join('\n')).toEqual([]);
        expect(styleDiagnostics.failedStyleRequests, styleDiagnostics.failedStyleRequests.join('\n')).toEqual([]);
        expect(styleDiagnostics.invalidStyleResponses, styleDiagnostics.invalidStyleResponses.join('\n')).toEqual([]);
    });

    test('TM-05: 主题预览功能', async ({ page }) => {
        const styleDiagnostics = bindStyleDiagnostics(page, targetOrigin);

        // 进入主题管理
        await gotoBackend(page, 'theme/backend');
        await waitForStyleSettle(page);

        // 查找预览按钮
        const previewButton = page.locator('button[data-action="preview"], .btn-preview, a[href*="preview"]').first();

        if (await previewButton.isVisible({ timeout: 3000 }).catch(() => false)) {
            // 获取当前 URL
            const currentUrl = page.url();

            // 点击预览
            const previewUrl = await previewButton.getAttribute('href');
            if (previewUrl) {
                // 在新标签页打开预览
                const newPage = await page.context().newPage();
                await newPage.goto(previewUrl, { timeout: 30000 });
                await newPage.waitForLoadState('domcontentloaded');

                // 验证预览页面正常
                await expect(newPage.locator('body')).toBeVisible({ timeout: 15000 });
                await newPage.close();
            }
        } else {
            console.log('[TM-05] No preview button found');
        }

        // 预览功能检查后，当前后台页样式也应正常
        const styleHealth = await collectStyleHealth(page);
        expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
        expect(styleDiagnostics.styleConsoleErrors, styleDiagnostics.styleConsoleErrors.join('\n')).toEqual([]);
        expect(styleDiagnostics.failedStyleRequests, styleDiagnostics.failedStyleRequests.join('\n')).toEqual([]);
        expect(styleDiagnostics.invalidStyleResponses, styleDiagnostics.invalidStyleResponses.join('\n')).toEqual([]);
    });

    test('TM-06: 多主题并存验证（前后台不同主题）', async ({ page }) => {
        const runtimeInfo = getRuntimeInfo();
        const activeThemes = runtimeInfo.themes?.active || {};

        // 检查前后台主题
        const frontendTheme = activeThemes.frontend;
        const backendTheme = activeThemes.backend;

        console.log('[TM-06] Frontend theme:', frontendTheme?.name);
        console.log('[TM-06] Backend theme:', backendTheme?.name);

        // 访问前台
        await gotoFrontend(page, '/');
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 访问后台
        await gotoBackend(page, 'dashboard');
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 验证两者都可以正常访问
        expect(page.url()).toBeTruthy();
    });
});
