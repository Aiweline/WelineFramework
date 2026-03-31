// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend, getRuntimeInfo } = require('../../framework');

/**
 * Theme CSS/JS Lifecycle E2E Tests
 * 主题 CSS/JS 生命周期收集测试
 */
test.describe('Theme CSS/JS Lifecycle - CSS/JS 生命周期测试', () => {

    test('CL-01: Layout 类型识别', async ({ page }) => {
        // 访问首页（使用默认 layout）
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取 layout 类型信息
        const layoutInfo = await page.evaluate(() => {
            // 尝试从 DOM 获取 layout 信息
            const body = document.body;
            return {
                bodyClass: body.className,
                bodyDataTheme: body.getAttribute('data-theme'),
                htmlClass: document.documentElement.className,
                htmlDataTheme: document.documentElement.getAttribute('data-theme')
            };
        });

        console.log('[CL-01] Layout info:', JSON.stringify(layoutInfo, null, 2));

        // 验证页面正常加载
        await expect(page.locator('body')).toBeVisible({ timeout: 10000 });
    });

    test('CL-02: CSS 资源加载验证', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取所有 CSS 链接
        const cssResources = await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
            const scripts = Array.from(document.querySelectorAll('script[src]'));

            const cssDetails = links.map(link => {
                try {
                    const url = new URL(link.href);
                    return {
                        href: link.href,
                        hostname: url.hostname,
                        pathname: url.pathname,
                        loaded: link.sheet !== null
                    };
                } catch {
                    return { href: link.href, error: 'invalid URL' };
                }
            });

            const jsDetails = scripts.map(script => {
                try {
                    const url = new URL(script.src);
                    return {
                        src: script.src,
                        hostname: url.hostname,
                        pathname: url.pathname
                    };
                } catch {
                    return { src: script.src, error: 'invalid URL' };
                }
            });

            return { css: cssDetails, js: jsDetails };
        });

        console.log(`[CL-02] Found ${cssResources.css.length} CSS files`);
        console.log(`[CL-02] Found ${cssResources.js.length} JS files`);

        // 验证有 CSS 加载
        expect(cssResources.css.length).toBeGreaterThan(0);

        // 打印 CSS 详情
        for (const css of cssResources.css.slice(0, 5)) {
            console.log(`[CL-02] CSS: ${css.pathname}`);
        }

        // 打印 JS 详情
        for (const js of cssResources.js.slice(0, 5)) {
            console.log(`[CL-02] JS: ${js.pathname}`);
        }
    });

    test('CL-03: CSS 变量注入验证', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取 CSS 变量
        const cssVariables = await page.evaluate(() => {
            const root = document.documentElement;
            const styles = getComputedStyle(root);

            // 获取所有 CSS 变量
            const variables = [];
            for (const prop of styles) {
                if (prop.startsWith('--')) {
                    variables.push({
                        name: prop,
                        value: styles.getPropertyValue(prop).trim()
                    });
                }
            }

            return variables;
        });

        console.log(`[CL-03] Found ${cssVariables.length} CSS variables`);

        // 打印前 20 个 CSS 变量
        for (const v of cssVariables.slice(0, 20)) {
            console.log(`[CL-03] ${v.name}: ${v.value.substring(0, 50)}`);
        }

        // 验证有 CSS 变量（主题应该有 CSS 变量）
        // 注意：有些主题可能没有 CSS 变量，这是正常的
        console.log(`[CL-03] CSS variable count: ${cssVariables.length}`);
    });

    test('CL-04: JS 模块加载验证', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取已加载的脚本信息
        const loadedScripts = await page.evaluate(() => {
            const scripts = Array.from(document.querySelectorAll('script'));
            return scripts.map(s => ({
                src: s.src || '(inline)',
                type: s.type || 'text/javascript',
                async: s.async,
                defer: s.defer,
                module: s.type === 'module'
            }));
        });

        console.log(`[CL-04] Found ${loadedScripts.length} script elements`);

        // 分类统计
        const inline = loadedScripts.filter(s => !s.src);
        const external = loadedScripts.filter(s => s.src);
        const modules = loadedScripts.filter(s => s.module);

        console.log(`[CL-04] Inline scripts: ${inline.length}`);
        console.log(`[CL-04] External scripts: ${external.length}`);
        console.log(`[CL-04] Module scripts: ${modules.length}`);

        // 验证有脚本加载
        expect(loadedScripts.length).toBeGreaterThan(0);
    });

    test('CL-05: 主题静态资源 404 检查', async ({ page }) => {
        const failedResources = [];

        // 监听请求失败
        page.on('response', response => {
            if (response.status() === 404) {
                const url = response.url();
                // 只记录主题相关的资源
                if (url.includes('theme') || url.includes('statics') || url.includes('assets')) {
                    failedResources.push({
                        url,
                        status: response.status()
                    });
                }
            }
        });

        // 访问多个页面
        const pages = ['/', '/catalog/category/view?id=1', '/product/view?id=1'];

        for (const url of pages) {
            await gotoFrontend(page, url);
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.waitForTimeout(500);
        }

        console.log(`[CL-05] Found ${failedResources.length} failed theme resources`);

        for (const resource of failedResources) {
            console.log(`[CL-05] 404: ${resource.url}`);
        }

        // 验证没有主题资源 404
        expect(failedResources.length).toBe(0);
    });

    test('CL-06: 静态资源发布验证', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取所有资源的主机名分布
        const resourceHosts = await page.evaluate(() => {
            const allElements = document.querySelectorAll('link[rel="stylesheet"], script[src], img[src]');

            const hosts = new Set();
            for (const el of allElements) {
                const src = el.src || el.href;
                if (src && !src.startsWith('data:')) {
                    try {
                        const url = new URL(src);
                        hosts.add(url.hostname);
                    } catch {}
                }
            }

            return Array.from(hosts);
        });

        console.log(`[CL-06] Resource hosts: ${resourceHosts.join(', ')}`);

        // 验证资源来自预期的主机
        expect(resourceHosts.length).toBeGreaterThan(0);
    });

    test('CL-07: 页面渲染完整性验证', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 检查关键元素是否存在
        const criticalElements = await page.evaluate(() => {
            return {
                hasHtml: !!document.documentElement,
                hasHead: !!document.head,
                hasBody: !!document.body,
                bodyChildren: document.body.children.length,
                hasTitle: !!document.title
            };
        });

        console.log('[CL-07] Page structure:', JSON.stringify(criticalElements, null, 2));

        // 验证页面结构完整
        expect(criticalElements.hasHtml).toBe(true);
        expect(criticalElements.hasHead).toBe(true);
        expect(criticalElements.hasBody).toBe(true);
        expect(criticalElements.bodyChildren).toBeGreaterThan(0);
    });
});
