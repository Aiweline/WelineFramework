// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

moduleDescribe(test, 'Weline_Theme', 'Theme主题管理', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'TC-01' }, '主题列表页显示主题管理标题和内容', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/theme', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证页面包含主题相关内容
    const hasThemeContent = 
      text.includes('主题') ||
      text.includes('Theme') ||
      text.includes('theme');
    expect(hasThemeContent).toBeTruthy();
    
    // 验证页面包含管理相关字样
    const hasManageContent = 
      text.includes('管理') ||
      text.includes('Manage') ||
      text.includes('list') ||
      text.includes('List');
    expect(hasManageContent).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'TC-02' }, '主题列表页包含主题预览或激活相关信息', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/theme', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证包含预览、激活、前端、后端等相关元素
    const hasRelatedContent = 
      text.includes('预览') ||
      text.includes('Preview') ||
      text.includes('preview') ||
      text.includes('激活') ||
      text.includes('Active') ||
      text.includes('active') ||
      text.includes('前端') ||
      text.includes('frontend') ||
      text.includes('后端') ||
      text.includes('backend');
    expect(hasRelatedContent).toBeTruthy();
  });
});
