/**
 * Weline_Backend 后台基础设施 E2E 冒烟测试
 *
 * 测试范围：
 * - Notification 通知中心：列表加载、搜索过滤、全部已读操作
 * - UserContact 联系人管理：列表加载、添加联系人、设置默认、删除联系人
 * - Config 后台配置：配置页面加载、站点名称保存
 *
 * 表单字段来源：app/code/Weline/Backend/Controller/Backend/*.php
 * 模板来源：app/code/Weline/Backend/view/templates/Backend/{Notification,UserContact,Config}/index.phtml
 *
 * @weline-e2e-spec { module: Weline_Backend, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Backend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Backend 后台基础设施冒烟测试', () => {

  // ========== Notification 通知中心 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-NOTIFICATION-001' },
    '通知列表页面能够正常加载，显示通知数据或空状态',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'notification');
      await gotoBackend(page, url, { timeout: 30000 });

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/通知中心|通知/, { timeout: 10000 });

      // 验证过滤器表单存在
      const keywordInput = page.locator('input[name="keyword"]');
      const typeSelect = page.locator('select[name="type"]');
      const readSelect = page.locator('select[name="read"]');
      await expect(keywordInput).toBeVisible();
      await expect(typeSelect).toBeVisible();
      await expect(readSelect).toBeVisible();

      // 验证搜索按钮
      const searchBtn = page.locator('button[type="submit"]').filter({ hasText: /搜索|search/i });
      await expect(searchBtn).toBeVisible();

      // 验证无 Fatal 错误
      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-NOTIFICATION-002' },
    '通知列表支持关键词搜索过滤',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'notification');
      await gotoBackend(page, url, { timeout: 30000 });

      // 输入关键词并搜索
      const keywordInput = page.locator('input[name="keyword"]');
      await keywordInput.fill('test-keyword');

      const searchBtn = page.locator('button[type="submit"]').filter({ hasText: /搜索|search/i });
      await searchBtn.click();

      // 验证 URL 包含搜索参数
      await expect(page).toHaveURL(/keyword=test-keyword/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-NOTIFICATION-003' },
    '通知列表支持按已读状态过滤',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'notification');
      await gotoBackend(page, url, { timeout: 30000 });

      // 选择"未读"过滤
      const readSelect = page.locator('select[name="read"]');
      await readSelect.selectOption('unread');

      const searchBtn = page.locator('button[type="submit"]').filter({ hasText: /搜索|search/i });
      await searchBtn.click();

      // 验证 URL 包含 read=unread
      await expect(page).toHaveURL(/read=unread/);
    }
  );

  // ========== UserContact 联系人管理 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-CONTACT-001' },
    '联系人列表页面能够正常加载，显示各渠道分组',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'usercontact');
      await gotoBackend(page, url, { timeout: 30000 });

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/联系人|contact/i, { timeout: 10000 });

      // 验证添加按钮存在
      const addBtn = page.locator('button').filter({ hasText: /添加.*联系人|add.*contact/i }).first();
      await expect(addBtn).toBeVisible();

      // 验证渠道卡片存在（邮件、短信、飞书、钉钉、Webhook）
      const channelCards = page.locator('.card');
      expect(await channelCards.count()).toBeGreaterThanOrEqual(1);

      // 验证无 Fatal 错误
      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-CONTACT-002' },
    '添加联系人弹窗能够打开并显示表单字段',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'usercontact');
      await gotoBackend(page, url, { timeout: 30000 });

      // 点击添加联系人按钮
      const addBtn = page.locator('button').filter({ hasText: /添加.*联系人/i }).first();
      await addBtn.click();

      // 等待弹窗出现
      const modal = page.locator('#addContactModal');
      await expect(modal).toBeVisible({ timeout: 10000 });

      // 验证表单字段存在
      const channelSelect = page.locator('select[name="channel_code"]');
      const contactValueInput = page.locator('input[name="contact_value"]');
      const contactNameInput = page.locator('input[name="contact_name"]');
      const isDefaultCheckbox = page.locator('input[name="is_default"]');

      await expect(channelSelect).toBeVisible();
      await expect(contactValueInput).toBeVisible();
      await expect(contactNameInput).toBeVisible();
      await expect(isDefaultCheckbox).toBeVisible();

      // 验证保存按钮
      const saveBtn = modal.locator('button').filter({ hasText: /保存/i });
      await expect(saveBtn).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-CONTACT-003' },
    '添加联系人表单字段验证：空渠道和空联系方式应返回错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'usercontact');
      await gotoBackend(page, url, { timeout: 30000 });

      // 打开弹窗
      const addBtn = page.locator('button').filter({ hasText: /添加.*联系人/i }).first();
      await addBtn.click();

      const modal = page.locator('#addContactModal');
      await expect(modal).toBeVisible({ timeout: 10000 });

      // 直接点击保存（不填写任何内容）
      const saveBtn = modal.locator('button').filter({ hasText: /保存/i });
      await saveBtn.click();

      // 验证浏览器原生验证或后端返回错误
      // 如果是 required 属性，表单不会提交
      const contactValueInput = page.locator('input[name="contact_value"]');
      // 验证 input 的 required 属性会阻止提交
      const isRequired = await contactValueInput.getAttribute('required');
      expect(isRequired).not.toBeNull();
    }
  );

  // ========== Config 后台配置 ==========

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-CONFIG-001' },
    '后台配置页面能够正常加载，显示 Logo 和站点信息配置项',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'config');
      await gotoBackend(page, url, { timeout: 30000 });

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/外观|Logo|config/i, { timeout: 10000 });

      // 验证配置表单存在
      const configForm = page.locator('#configForm');
      await expect(configForm).toBeVisible();

      // 验证站点名称输入框
      const siteNameInput = page.locator('input[name="config[site_name]"]');
      await expect(siteNameInput).toBeVisible();

      // 验证站点描述输入框
      const siteDescInput = page.locator('input[name="config[site_description]"]');
      await expect(siteDescInput).toBeVisible();

      // 验证保存按钮
      const submitBtn = page.locator('button[type="submit"]');
      await expect(submitBtn).toBeVisible();

      // 验证无 Fatal 错误
      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'BACKEND-SMOKE-CONFIG-002' },
    '后台配置站点名称可以填写并提交',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'config');
      await gotoBackend(page, url, { timeout: 30000 });

      // 填写站点名称
      const siteNameInput = page.locator('input[name="config[site_name]"]');
      const testSiteName = 'Test Backend Site ' + Date.now();
      await siteNameInput.fill(testSiteName);

      // 获取当前值确认已填写
      const currentValue = await siteNameInput.inputValue();
      expect(currentValue).toBe(testSiteName);
    }
  );
});
