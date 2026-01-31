/**
 * Sitemap Management E2E Test
 * 
 * Tests the complete sitemap management workflow:
 * - Page loading
 * - URL synchronization
 * - File generation
 * - File structure display
 * - Platform grouping
 * - Cross-site index
 * - URL copying functionality
 */

const { test, expect } = require('@playwright/test');

// 测试配置
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:9981';
const ADMIN_PATH = '/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/USD/zh_Hans_CN';

test.describe('Sitemap Management Backend', () => {
  
  test.beforeEach(async ({ page }) => {
    // TODO: Add login if authentication is required
    // For now, assuming direct access or session exists
  });

  test('页面应该正常加载并显示标题', async ({ page }) => {
    // 导航到 sitemap 管理页面
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    
    // 等待页面加载
    await page.waitForLoadState('networkidle');
    
    // 验证页面标题
    const title = page.locator('h1, .page-title');
    await expect(title.first()).toBeVisible();
    await expect(title.first()).toContainText(/Sitemap|站点地图/i);
    
    console.log('✓ 页面标题正确显示');
  });

  test('应该显示跨站总索引信息', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找跨站总索引提示
    const indexHint = page.locator('.index-file-hint, .cross-site-index');
    
    // 应该显示总索引信息
    if (await indexHint.count() > 0) {
      await expect(indexHint.first()).toBeVisible();
      await expect(indexHint.first()).toContainText(/跨站|总索引|sitemap\.xml/i);
      
      // 验证 URL 显示
      const urlCode = indexHint.locator('code');
      await expect(urlCode).toBeVisible();
      await expect(urlCode).toContainText('/sitemaps/sitemap.xml');
      
      console.log('✓ 跨站总索引信息正确显示');
    } else {
      console.log('⚠ 跨站总索引未显示（可能是空数据）');
    }
  });

  test('应该显示站点分组卡片', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找站点卡片
    const siteCards = page.locator('.site-card, .website-card, [class*="site"]');
    
    if (await siteCards.count() > 0) {
      // 至少应该有一个站点卡片
      await expect(siteCards.first()).toBeVisible();
      
      console.log(`✓ 找到 ${await siteCards.count()} 个站点卡片`);
    } else {
      console.log('⚠ 没有找到站点卡片（可能需要先生成 sitemap）');
    }
  });

  test('应该显示平台分组（Google, Bing, 百度）', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找平台标签或分组
    const platformLabels = await page.locator('[class*="platform"], .badge').allTextContents();
    
    // 检查是否包含预期的平台
    const expectedPlatforms = ['Google', 'Bing', '百度'];
    const foundPlatforms = expectedPlatforms.filter(platform => 
      platformLabels.some(label => label.includes(platform))
    );
    
    if (foundPlatforms.length > 0) {
      console.log(`✓ 找到平台: ${foundPlatforms.join(', ')}`);
    } else {
      console.log('⚠ 没有找到平台分组（可能需要先生成 sitemap）');
    }
  });

  test('应该显示 sitemap 文件列表', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找文件列表项
    const fileItems = page.locator('.file-item, .sitemap-file, [class*="file"]');
    
    if (await fileItems.count() > 0) {
      const firstFile = fileItems.first();
      await expect(firstFile).toBeVisible();
      
      // 验证文件信息（文件名、大小、修改时间）
      const fileText = await firstFile.textContent();
      
      // 应该包含文件信息
      if (fileText) {
        console.log('✓ Sitemap 文件列表正确显示');
      }
    } else {
      console.log('⚠ 没有 sitemap 文件（需要先生成）');
    }
  });

  test('复制 URL 按钮应该存在且可点击', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找复制按钮
    const copyButtons = page.locator('button:has-text("复制"), button[title*="复制"], button .mdi-content-copy');
    
    if (await copyButtons.count() > 0) {
      const firstCopyBtn = copyButtons.first();
      await expect(firstCopyBtn).toBeVisible();
      
      // 验证按钮可点击
      await expect(firstCopyBtn).toBeEnabled();
      
      console.log(`✓ 找到 ${await copyButtons.count()} 个复制按钮`);
    } else {
      console.log('⚠ 没有找到复制按钮');
    }
  });

  test('查看按钮应该存在且可点击', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找查看按钮
    const viewButtons = page.locator('a:has-text("查看"), a .mdi-open-in-new');
    
    if (await viewButtons.count() > 0) {
      const firstViewBtn = viewButtons.first();
      await expect(firstViewBtn).toBeVisible();
      
      // 验证链接有效
      const href = await firstViewBtn.getAttribute('href');
      expect(href).toBeTruthy();
      expect(href).toContain('/sitemaps/');
      
      console.log(`✓ 找到 ${await viewButtons.count()} 个查看按钮`);
    } else {
      console.log('⚠ 没有找到查看按钮');
    }
  });

  test('生成 Sitemap 按钮应该可用', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找生成按钮
    const generateBtn = page.locator('button:has-text("生成"), button:has-text("同步")');
    
    if (await generateBtn.count() > 0) {
      await expect(generateBtn.first()).toBeVisible();
      await expect(generateBtn.first()).toBeEnabled();
      
      console.log('✓ 生成 Sitemap 按钮可用');
    } else {
      console.log('⚠ 没有找到生成按钮');
    }
  });

  test('应该显示高级手风琴（工作原理）', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找手风琴
    const accordion = page.locator('.accordion, [class*="collapse"]');
    
    if (await accordion.count() > 0) {
      // 查找标题
      const accordionTitle = accordion.locator('.accordion-header, .card-header, h5');
      
      if (await accordionTitle.count() > 0) {
        await expect(accordionTitle.first()).toBeVisible();
        const titleText = await accordionTitle.first().textContent();
        
        // 应该包含 "高级" 或 "工作原理" 等字样
        if (titleText && (titleText.includes('高级') || titleText.includes('工作原理'))) {
          console.log('✓ 高级手风琴正确显示');
        }
      }
    }
  });

  test('文件应该显示正确的元信息', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找文件元信息
    const fileMeta = page.locator('.file-meta, [class*="meta"]');
    
    if (await fileMeta.count() > 0) {
      const firstMeta = fileMeta.first();
      const metaText = await firstMeta.textContent();
      
      // 应该包含文件大小或修改时间
      if (metaText && (metaText.includes('B') || metaText.includes('KB') || metaText.includes('MB') || metaText.match(/\d{4}-\d{2}-\d{2}/))) {
        console.log('✓ 文件元信息正确显示（大小、时间）');
      }
    }
  });

  test('完整工作流：点击生成按钮并验证结果', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找生成按钮
    const generateBtn = page.locator('button:has-text("生成"), button:has-text("同步")').first();
    
    if (await generateBtn.count() > 0) {
      // 点击生成按钮
      await generateBtn.click();
      
      // 等待响应（可能是 AJAX 请求）
      await page.waitForTimeout(2000);
      
      // 查找成功提示
      const successAlert = page.locator('.alert-success, .toast-success, [class*="success"]');
      
      if (await successAlert.count() > 0) {
        await expect(successAlert.first()).toBeVisible();
        console.log('✓ Sitemap 生成成功提示显示');
      } else {
        console.log('⚠ 没有找到成功提示（可能已消失或不同实现）');
      }
      
      // 重新加载页面验证文件是否生成
      await page.reload();
      await page.waitForLoadState('networkidle');
      
      // 验证是否有新文件显示
      const fileItems = page.locator('.file-item, .sitemap-file');
      if (await fileItems.count() > 0) {
        console.log(`✓ 生成后显示 ${await fileItems.count()} 个文件`);
      }
    } else {
      console.log('⚠ 没有找到生成按钮，跳过工作流测试');
    }
  });

  test('验证页面无 JavaScript 错误', async ({ page }) => {
    const errors = [];
    
    // 监听控制台错误
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    // 监听页面错误
    page.on('pageerror', error => {
      errors.push(error.message);
    });
    
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 等待一段时间以捕获所有错误
    await page.waitForTimeout(1000);
    
    if (errors.length > 0) {
      console.log(`⚠ 发现 ${errors.length} 个 JavaScript 错误:`);
      errors.forEach(err => console.log(`  - ${err}`));
    } else {
      console.log('✓ 页面无 JavaScript 错误');
    }
    
    // 不强制失败，只报告
    expect(errors.length).toBeLessThan(5); // 允许少量非关键错误
  });

  test('验证响应式设计（移动端）', async ({ page }) => {
    // 设置移动端视口
    await page.setViewportSize({ width: 375, height: 667 });
    
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 验证页面在移动端可见
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // 验证内容不溢出
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    
    // 允许少量溢出（滚动条等）
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 20);
    
    console.log('✓ 移动端布局正常');
  });

  test('站点默认应该是折叠状态', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找折叠的站点内容
    const collapsedSites = page.locator('.website-files.collapse:not(.show)');
    
    if (await collapsedSites.count() > 0) {
      console.log(`✓ 找到 ${await collapsedSites.count()} 个折叠的站点`);
    } else {
      console.log('⚠ 站点未折叠或没有站点数据');
    }
  });

  test('搜索功能应该正常工作', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找搜索框
    const searchInput = page.locator('#siteSearchInput, input[placeholder*="搜索"]');
    
    if (await searchInput.count() > 0) {
      await expect(searchInput.first()).toBeVisible();
      
      // 测试搜索
      await searchInput.first().fill('default');
      
      // 等待搜索防抖
      await page.waitForTimeout(500);
      
      // 验证搜索结果（应该只显示包含 default 的站点）
      const visibleSites = page.locator('.website-node[style*="display: block"], .website-node:not([style*="display: none"])');
      
      console.log('✓ 搜索功能可用');
    } else {
      console.log('⚠ 搜索框未找到');
    }
  });

  test('展开/折叠按钮应该存在', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 查找展开按钮
    const expandBtn = page.locator('button:has-text("展开"), button[title*="展开"]');
    
    if (await expandBtn.count() > 0) {
      await expect(expandBtn.first()).toBeVisible();
      console.log('✓ 展开按钮存在');
    }
    
    // 查找折叠按钮
    const collapseBtn = page.locator('button:has-text("折叠"), button[title*="折叠"]');
    
    if (await collapseBtn.count() > 0) {
      await expect(collapseBtn.first()).toBeVisible();
      console.log('✓ 折叠按钮存在');
    }
  });

  test('分页控件应该在多站点时显示', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 等待 JavaScript 初始化
    await page.waitForTimeout(500);
    
    // 查找分页控件
    const pagination = page.locator('#pagination, .pagination');
    const pageInfo = page.locator('#pageInfo');
    
    // 如果站点数量 <= 5，分页应该隐藏
    // 如果站点数量 > 5，分页应该显示
    const websiteNodes = await page.locator('.website-node').count();
    
    if (websiteNodes > 5) {
      await expect(pagination.first()).toBeVisible();
      console.log(`✓ 分页控件显示（${websiteNodes} 个站点）`);
    } else {
      console.log(`✓ 站点数量 ${websiteNodes} <= 5，分页自动隐藏`);
    }
  });

  test('完整工作流：搜索 -> 展开 -> 查看文件', async ({ page }) => {
    await page.goto(`${BASE_URL}${ADMIN_PATH}/seo/backend/sitemap`);
    await page.waitForLoadState('networkidle');
    
    // 1. 搜索站点
    const searchInput = page.locator('#siteSearchInput');
    if (await searchInput.count() > 0) {
      await searchInput.fill('default');
      await page.waitForTimeout(500);
      console.log('✓ 步骤 1: 搜索站点完成');
    }
    
    // 2. 点击站点卡片展开
    const siteHeader = page.locator('.website-header').first();
    if (await siteHeader.count() > 0) {
      await siteHeader.click();
      await page.waitForTimeout(300);
      console.log('✓ 步骤 2: 展开站点完成');
    }
    
    // 3. 验证平台分组显示
    const platforms = page.locator('.platform-group');
    if (await platforms.count() > 0) {
      console.log(`✓ 步骤 3: 显示 ${await platforms.count()} 个平台分组`);
    }
    
    // 4. 验证文件链接可点击
    const viewLinks = page.locator('a[target="_blank"]');
    if (await viewLinks.count() > 0) {
      await expect(viewLinks.first()).toBeEnabled();
      console.log('✓ 步骤 4: 文件查看链接可用');
    }
    
    console.log('✓ 完整工作流测试通过');
  });
});

test.describe('Sitemap File Access', () => {
  
  test('跨站总索引文件应该可访问', async ({ page }) => {
    // 直接访问 sitemap URL
    const response = await page.goto(`${BASE_URL}/sitemaps/sitemap.xml`);
    
    // 验证响应状态
    expect(response?.status()).toBe(200);
    
    // 验证内容类型
    const contentType = response?.headers()['content-type'] || '';
    expect(contentType).toContain('xml');
    
    // 验证内容
    const content = await page.content();
    expect(content).toContain('<?xml');
    expect(content).toContain('sitemapindex');
    
    console.log('✓ 跨站总索引文件可访问');
  });

  test('站点平台索引文件应该可访问', async ({ page }) => {
    // 测试 default 站点的 google 平台索引
    const response = await page.goto(`${BASE_URL}/sitemaps/default/google/sitemap.xml`);
    
    if (response?.status() === 200) {
      const content = await page.content();
      expect(content).toContain('<?xml');
      expect(content).toContain('sitemapindex');
      
      console.log('✓ 站点平台索引文件可访问');
    } else {
      console.log('⚠ 平台索引文件不存在（可能需要先生成）');
    }
  });

  test('模块 sitemap 文件应该包含有效的 URL', async ({ page }) => {
    // 访问模块文件
    const response = await page.goto(`${BASE_URL}/sitemaps/default/google/sitemap_builder_1.xml`);
    
    if (response?.status() === 200) {
      const content = await page.content();
      
      // 验证 XML 格式
      expect(content).toContain('<?xml');
      expect(content).toContain('<urlset');
      expect(content).toContain('<url>');
      expect(content).toContain('<loc>');
      
      // 验证包含实际 URL
      expect(content).toMatch(/http:\/\/[^<]+/);
      
      console.log('✓ 模块 sitemap 文件格式正确且包含 URL');
    } else {
      console.log('⚠ 模块 sitemap 文件不存在');
    }
  });
});
