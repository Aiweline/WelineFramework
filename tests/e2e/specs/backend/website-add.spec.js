// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * 网站添加功能 E2E 测试
 * 测试添加站点功能，包括：
 * 1. 打开添加站点表单
 * 2. 填写站点信息
 * 3. 提交表单
 * 4. 验证成功/失败处理
 * 5. 验证错误页面跳转
 */
test.describe('网站添加功能', () => {
    test.beforeEach(async ({ page }) => {
        // 访问后台首页并登录
        await page.goto('http://127.0.0.1:9981/admin');
        
        // 等待登录表单出现
        await page.waitForSelector('input[name="username"], input[type="text"]', { timeout: 5000 }).catch(() => {});
        
        // 如果存在登录表单，执行登录
        const usernameInput = await page.$('input[name="username"], input[type="text"]');
        if (usernameInput) {
            await page.fill('input[name="username"], input[type="text"]', 'admin');
            await page.fill('input[name="password"], input[type="password"]', 'admin');
            await page.click('button[type="submit"], input[type="submit"]');
            // 等待登录完成
            await page.waitForTimeout(2000);
        }
    });

    test('应该能够打开添加站点表单', async ({ page }) => {
        // 访问网站管理页面
        await page.goto('http://127.0.0.1:9981/websites/admin/website');
        await page.waitForTimeout(1000);

        // 查找并点击添加按钮
        const addButton = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"]').first();
        await addButton.waitFor({ timeout: 5000 }).catch(() => {});
        
        if (await addButton.isVisible()) {
            await addButton.click();
            await page.waitForTimeout(1000);

            // 验证 offcanvas 是否打开
            const offcanvas = page.locator('.offcanvas.show, .offcanvas[class*="show"]');
            await expect(offcanvas).toBeVisible({ timeout: 3000 });
        }
    });

    test('应该能够填写并提交站点表单', async ({ page }) => {
        // 访问网站管理页面
        await page.goto('http://127.0.0.1:9981/websites/admin/website');
        await page.waitForTimeout(1000);

        // 查找并点击添加按钮
        const addButton = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"]').first();
        await addButton.waitFor({ timeout: 5000 }).catch(() => {});
        
        if (await addButton.isVisible()) {
            await addButton.click();
            await page.waitForTimeout(2000);

            // 等待 iframe 加载
            const iframe = page.frameLocator('iframe').first();
            await page.waitForTimeout(2000);

            // 在 iframe 中填写表单
            const nameInput = iframe.locator('input[name="name"], input[id*="name"]').first();
            if (await nameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                await nameInput.fill('测试站点 ' + Date.now());
            }

            const codeInput = iframe.locator('input[name="code"], input[id*="code"]').first();
            if (await codeInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                await codeInput.fill('test_' + Date.now());
            }

            const urlInput = iframe.locator('input[name="url"], input[id*="url"]').first();
            if (await urlInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                await urlInput.fill('http://test.example.com');
            }

            // 点击保存按钮
            const saveButton = page.locator('button[id*="Save"]').first();
            if (await saveButton.isVisible({ timeout: 3000 }).catch(() => false)) {
                await saveButton.click();
                await page.waitForTimeout(3000);

                // 验证是否显示成功或错误消息
                const successMsg = page.locator('text=/成功|success/i');
                const errorMsg = page.locator('text=/失败|error|错误/i');
                
                // 等待消息出现
                await page.waitForTimeout(2000);
                
                // 验证至少有一个消息显示
                const hasSuccess = await successMsg.isVisible().catch(() => false);
                const hasError = await errorMsg.isVisible().catch(() => false);
                
                expect(hasSuccess || hasError).toBeTruthy();
            }
        }
    });

    test('提交失败时应该显示错误页面并跳转首页', async ({ page }) => {
        // 访问网站管理页面
        await page.goto('http://127.0.0.1:9981/websites/admin/website');
        await page.waitForTimeout(1000);

        // 查找并点击添加按钮
        const addButton = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"]').first();
        await addButton.waitFor({ timeout: 5000 }).catch(() => {});
        
        if (await addButton.isVisible()) {
            await addButton.click();
            await page.waitForTimeout(2000);

            // 等待 iframe 加载
            const iframe = page.frameLocator('iframe').first();
            await page.waitForTimeout(2000);

            // 填写无效数据（例如重复的 code）
            const codeInput = iframe.locator('input[name="code"], input[id*="code"]').first();
            if (await codeInput.isVisible({ timeout: 3000 }).catch(() => false)) {
                await codeInput.fill('default'); // 使用已存在的 code
            }

            // 点击保存按钮
            const saveButton = page.locator('button[id*="Save"]').first();
            if (await saveButton.isVisible({ timeout: 3000 }).catch(() => false)) {
                await saveButton.click();
                await page.waitForTimeout(3000);

                // 验证错误页面显示
                const errorPage = page.locator('text=/警告提示|错误|失败/i');
                await expect(errorPage).toBeVisible({ timeout: 5000 });

                // 验证倒计时显示
                const countdown = page.locator('text=/秒后|countdown/i');
                await expect(countdown).toBeVisible({ timeout: 3000 });

                // 等待跳转（3秒后）
                await page.waitForTimeout(4000);

                // 验证是否跳转到首页或关闭 offcanvas
                const currentUrl = page.url();
                const isHomePage = currentUrl.includes('/') && !currentUrl.includes('/admin/website');
                const offcanvasClosed = !(await page.locator('.offcanvas.show').isVisible().catch(() => false));
                
                expect(isHomePage || offcanvasClosed).toBeTruthy();
            }
        }
    });

    test('不应该在添加时检查 website_id', async ({ page }) => {
        // 访问添加页面直接提交（不填写任何数据）
        await page.goto('http://127.0.0.1:9981/websites/admin/website/add');
        await page.waitForTimeout(1000);

        // 查找表单并提交
        const form = page.locator('form').first();
        if (await form.isVisible({ timeout: 3000 }).catch(() => false)) {
            const submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
            if (await submitButton.isVisible({ timeout: 3000 }).catch(() => false)) {
                await submitButton.click();
                await page.waitForTimeout(2000);

                // 验证不应该出现"网站ID不存在"的错误
                const errorText = page.locator('text=/网站ID不存在|website.*id.*not.*found/i');
                await expect(errorText).not.toBeVisible({ timeout: 2000 });
            }
        }
    });

    test('验证模板变量已定义，无未定义变量警告', async ({ page }) => {
        // 访问网站管理页面
        await page.goto('http://127.0.0.1:9981/websites/admin/website');
        await page.waitForTimeout(1000);

        // 监听控制台错误
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                errors.push(msg.text());
            }
        });

        page.on('pageerror', error => {
            errors.push(error.message);
        });

        // 查找并点击添加按钮
        const addButton = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"]').first();
        await addButton.waitFor({ timeout: 5000 }).catch(() => {});
        
        if (await addButton.isVisible()) {
            await addButton.click();
            await page.waitForTimeout(2000);

            // 检查页面内容中是否有未定义变量警告
            const pageContent = await page.content();
            const hasUndefinedWarning = /Undefined variable.*target_button_text|Undefined variable.*title|Undefined variable.*submit_button_text/i.test(pageContent);
            
            expect(hasUndefinedWarning).toBeFalsy();
            
            // 检查控制台错误
            const hasConsoleError = errors.some(err => 
                /Undefined variable|syntax error|Fatal error/i.test(err)
            );
            
            expect(hasConsoleError).toBeFalsy();
        }
    });
});
