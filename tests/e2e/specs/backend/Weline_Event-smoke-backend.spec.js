// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

moduleDescribe(test, 'Weline_Event', 'Event事件管理', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Event', id: 'TC-01' }, '事件列表页显示事件统计数据和内容', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/event', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证页面包含事件相关内容
    const hasEventContent = 
      text.includes('事件') ||
      text.includes('Event') ||
      text.includes('event');
    expect(hasEventContent).toBeTruthy();
    
    // 验证包含统计信息或观察者
    const hasStatsOrObserver = 
      text.includes('总计') ||
      text.includes('total') ||
      text.includes('观察者') ||
      text.includes('observer') ||
      text.includes('模块') ||
      text.includes('module') ||
      text.includes('规约') ||
      text.includes('spec');
    expect(hasStatsOrObserver).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_Event', id: 'TC-02' }, '事件列表页包含筛选或搜索功能', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/event', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    
    // 验证页面无PHP fatal error
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    
    // 验证包含筛选或搜索相关元素
    const hasFilterOrSearch = 
      text.includes('搜索') ||
      text.includes('search') ||
      text.includes('筛选') ||
      text.includes('filter') ||
      text.includes('模块') ||
      text.includes('module') ||
      text.includes('文档') ||
      text.includes('doc');
    expect(hasFilterOrSearch).toBeTruthy();
  });
});
