// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

moduleDescribe(test, 'Weline_Hook', 'Hook管理', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Hook', id: 'TC-01' }, 'Hook列表页包含Hook统计数据和内容', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/hook', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证页面包含Hook相关内容
    expect(text).toMatch(/Hook|hook/);
    
    // 验证包含统计信息或Hook列表（页面有实质内容）
    const hasStatsOrList = 
      text.includes('总计') || 
      text.includes('total') || 
      text.includes('Total') ||
      text.includes('模块') ||
      text.includes('module') ||
      /[0-9]+\s*(个|条|个)?\s*hook/i.test(text);
    expect(hasStatsOrList).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_Hook', id: 'TC-02' }, 'Hook列表页显示筛选器或搜索区域', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/hook', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证包含筛选相关元素
    const hasFilter = 
      text.includes('模块') ||
      text.includes('module') ||
      text.includes('区域') ||
      text.includes('area') ||
      text.includes('搜索') ||
      text.includes('search') ||
      text.includes('筛选') ||
      text.includes('filter');
    expect(hasFilter).toBeTruthy();
  });
});
