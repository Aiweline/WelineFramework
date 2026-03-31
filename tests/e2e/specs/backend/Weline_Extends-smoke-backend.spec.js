// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

moduleDescribe(test, 'Weline_Extends', 'Extends扩展点管理', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Extends', id: 'TC-01' }, '扩展列表页显示扩展内容和标题', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/extends', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证页面包含扩展相关内容
    const hasExtendsContent = 
      text.includes('扩展') ||
      text.includes('Extends') ||
      text.includes('extends') ||
      text.includes('extension');
    expect(hasExtendsContent).toBeTruthy();
    
    // 验证包含管理相关字样
    const hasManageContent = 
      text.includes('管理') ||
      text.includes('Manage') ||
      text.includes('list') ||
      text.includes('List');
    expect(hasManageContent).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_Extends', id: 'TC-02' }, '扩展列表页包含Sticker或模块详情入口', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/extends', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证包含Sticker或详情相关元素
    const hasStickerOrDetail = 
      text.includes('Sticker') ||
      text.includes('sticker') ||
      text.includes('模块') ||
      text.includes('module') ||
      text.includes('详情') ||
      text.includes('detail') ||
      text.includes('统计') ||
      text.includes('stats');
    expect(hasStickerOrDetail).toBeTruthy();
  });
});
