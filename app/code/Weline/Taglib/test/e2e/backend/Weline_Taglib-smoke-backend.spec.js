// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

moduleDescribe(test, 'Weline_Taglib', 'Taglib标签库管理', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Taglib', id: 'TC-01' }, '标签库列表页显示标签内容和分页', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/taglib', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证页面包含标签相关内容
    const hasTagContent = 
      text.includes('标签') ||
      text.includes('Tag') ||
      text.includes('tag') ||
      text.includes('列表') ||
      text.includes('list');
    expect(hasTagContent).toBeTruthy();
    
    // 验证包含分页或搜索元素
    const hasPaginationOrSearch = 
      text.includes('页') ||
      text.includes('page') ||
      text.includes(' Pagination') ||
      text.includes('搜索') ||
      text.includes('search') ||
      text.includes('共') ||
      text.includes('total');
    expect(hasPaginationOrSearch).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_Taglib', id: 'TC-02' }, '标签库列表页包含模块信息', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/taglib', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证包含模块相关信息
    const hasModuleContent = 
      text.includes('模块') ||
      text.includes('Module') ||
      text.includes('module') ||
      text.includes('Weline');
    expect(hasModuleContent).toBeTruthy();
  });
});
