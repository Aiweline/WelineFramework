# Playwright E2E测试指南

## 📚 简介

Playwright是现代化的端到端（E2E）测试框架，支持Chrome、Firefox、Safari等多个浏览器。

## 🚀 安装配置

### 1. 安装Node.js

确保已安装Node.js 16+:

```bash
node --version  # 应该 >= 16.0.0
npm --version
```

### 2. 初始化Playwright项目

在项目根目录创建测试目录：

```bash
# 创建测试目录
mkdir -p tests/e2e
cd tests/e2e

# 初始化npm项目
npm init -y

# 安装Playwright
npm install -D @playwright/test
npm install -D playwright

# 初始化Playwright配置
npx playwright install
```

### 3. 配置Playwright

创建 `playwright.config.js`:

```javascript
// tests/e2e/playwright.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL: 'http://127.0.0.1:81',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
    // 移动端测试
    {
      name: 'Mobile Chrome',
      use: { ...devices['Pixel 5'] },
    },
    {
      name: 'Mobile Safari',
      use: { ...devices['iPhone 12'] },
    },
  ],

  webServer: {
    command: 'php bin/w s:sta',
    url: 'http://127.0.0.1:81',
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
  },
});
```

## 📁 测试结构

```
tests/e2e/                    # 公共 Playwright 运行器、helper、报告目录
app/code/Weline/Ai/test/e2e/  # Weline_Ai 模块自己的 E2E 用例
├── backend/                  # 后台测试
│   ├── model.spec.js         # 模型管理
│   ├── apikey.spec.js        # API密钥
│   └── adapter.spec.js       # 适配器
├── fixtures/                 # 测试夹具
│   ├── auth.js               # 认证夹具
│   └── data.js               # 测试数据
├── frontend/                 # 前台测试
│   ├── chat.spec.js          # 聊天功能
│   └── center.spec.js        # 用户中心
└── utils/                    # 工具函数
    ├── login.js              # 登录助手
    └── wait.js               # 等待助手
```

## 📝 编写测试

### 示例1：后台登录测试

```javascript
// app/code/Weline/Ai/test/e2e/backend/login.spec.js
import { test, expect } from '@playwright/test';

test.describe('后台登录功能', () => {
  test('应该能够成功登录', async ({ page }) => {
    // 访问登录页
    await page.goto('/admin/login');

    // 填写登录表单
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    
    // 点击登录按钮
    await page.click('button[type="submit"]');

    // 等待跳转并验证
    await page.waitForURL('**/admin/**');
    await expect(page).toHaveURL(/admin/);
    
    // 验证登录成功
    await expect(page.locator('.user-info')).toBeVisible();
  });

  test('应该显示错误信息当密码错误时', async ({ page }) => {
    await page.goto('/admin/login');

    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'wrong_password');
    await page.click('button[type="submit"]');

    // 验证错误消息
    await expect(page.locator('.alert-danger')).toBeVisible();
    await expect(page.locator('.alert-danger')).toContainText('密码错误');
  });
});
```

### 示例2：模型管理测试

```javascript
// app/code/Weline/Ai/test/e2e/backend/model.spec.js
import { test, expect } from '@playwright/test';

test.describe('AI模型管理', () => {
  test.beforeEach(async ({ page }) => {
    // 登录
    await page.goto('/admin/login');
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/**');

    // 访问模型管理页
    await page.goto('/ai/backend/model/index');
  });

  test('应该显示模型列表', async ({ page }) => {
    // 等待表格加载
    await expect(page.locator('table')).toBeVisible();
    
    // 验证至少有一个模型
    const rows = await page.locator('tbody tr').count();
    expect(rows).toBeGreaterThan(0);
  });

  test('应该能够搜索模型', async ({ page }) => {
    // 输入搜索关键词
    await page.fill('input[name="keyword"]', 'GPT');
    await page.click('button.search-btn');

    // 等待搜索结果
    await page.waitForTimeout(500);

    // 验证搜索结果
    const results = await page.locator('tbody tr');
    await expect(results.first()).toContainText('GPT');
  });

  test('应该能够打开创建模型表单', async ({ page }) => {
    // 点击新建按钮
    await page.click('button:has-text("新建模型")');

    // 验证Off-canvas显示
    await expect(page.locator('.offcanvas')).toBeVisible();
    await expect(page.locator('.offcanvas-title')).toContainText('新建模型');
  });

  test('应该能够编辑模型', async ({ page }) => {
    // 点击第一个编辑按钮
    await page.click('tbody tr:first-child button.edit-btn');

    // 验证编辑表单显示
    await expect(page.locator('.offcanvas')).toBeVisible();
    await expect(page.locator('.offcanvas-title')).toContainText('编辑模型');

    // 修改名称
    await page.fill('input[name="name"]', 'Updated Model Name');
    
    // 保存
    await page.click('button:has-text("保存")');

    // 验证保存成功消息
    await expect(page.locator('.alert-success')).toBeVisible();
  });

  test('应该能够切换模型状态', async ({ page }) => {
    // 点击第一个模型的状态开关
    await page.click('tbody tr:first-child .form-switch input');

    // 等待请求完成
    await page.waitForTimeout(500);

    // 验证状态已改变
    await expect(page.locator('.alert-success')).toBeVisible();
  });
});
```

### 示例3：AI 管理聚合页测试

```javascript
// app/code/Weline/Ai/test/e2e/backend/manager.spec.js
import { test, expect } from '@playwright/test';

test.describe('AI 管理', () => {
  test.beforeEach(async ({ page }) => {
    // 登录
    await page.goto('/admin/login');
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/**');

    // 访问 AI 管理页
    await page.goto('/ai/backend/manager');
  });

  test('应该显示模型 Tab', async ({ page }) => {
    await page.click('a[data-tab="model"]');
    await expect(page.locator('#model-tab-content')).toBeVisible();
  });

  test('应该能够新增模型', async ({ page }) => {
    await page.click('a[data-tab="model"]');
    await page.click('button:has-text("新增模型")');
    await expect(page.locator('.offcanvas')).toBeVisible();
  });

  test('应该显示适配器 Tab', async ({ page }) => {
    await page.click('a[data-tab="adapter"]');
    await expect(page.locator('#adapter-tab-content')).toBeVisible();
  });
});
```

## 🔧 测试工具函数

### 登录助手

```javascript
// tests/e2e/utils/login.js
export async function login(page, username = 'admin', password = 'admin') {
  await page.goto('/admin/login');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/admin/**');
}
```

### 等待助手

```javascript
// tests/e2e/utils/wait.js
export async function waitForTableLoad(page) {
  await page.waitForSelector('table', { state: 'visible' });
  await page.waitForLoadState('networkidle');
}

export async function waitForOffcanvas(page) {
  await page.waitForSelector('.offcanvas', { state: 'visible' });
}

export async function waitForMessage(page, type = 'success') {
  await page.waitForSelector(`.alert-${type}`, { state: 'visible' });
}
```

## 🏃 运行测试

### 运行所有测试

```bash
cd tests/e2e
npx playwright test
```

### 运行特定测试

```bash
# 运行后台测试
npx playwright test specs/backend/

# 运行特定文件
npx playwright test specs/backend/model.spec.js

# 运行特定测试用例
npx playwright test -g "应该显示模型列表"
```

### 调试模式

```bash
# UI模式（推荐）
npx playwright test --ui

# Debug模式
npx playwright test --debug

# 指定浏览器
npx playwright test --project=chromium
```

### 生成报告

```bash
# 运行测试并生成HTML报告
npx playwright test

# 查看报告
npx playwright show-report
```

## 📊 测试覆盖率

### 后台功能

- [ ] 登录/登出
- [ ] 模型管理（CRUD）
- [ ] API密钥管理（CRUD）
- [ ] 场景适配器管理
- [ ] 统计监控页面

### 前台功能

- [ ] 首页
- [ ] 聊天功能
- [ ] 用户中心
- [ ] API密钥查看

### 响应式测试

- [ ] 桌面端（1280px）
- [ ] 平板端（768px）
- [ ] 移动端（375px）

### 浏览器兼容性

- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari/WebKit
- [ ] Mobile Chrome
- [ ] Mobile Safari

## 🎯 最佳实践

### 1. 使用Page Object模式

```javascript
// pages/ModelPage.js
export class ModelPage {
  constructor(page) {
    this.page = page;
    this.searchInput = page.locator('input[name="keyword"]');
    this.searchButton = page.locator('button.search-btn');
    this.createButton = page.locator('button:has-text("新建模型")');
    this.table = page.locator('table');
  }

  async goto() {
    await this.page.goto('/ai/backend/model/index');
  }

  async search(keyword) {
    await this.searchInput.fill(keyword);
    await this.searchButton.click();
  }

  async create() {
    await this.createButton.click();
  }

  async getRowCount() {
    return await this.table.locator('tbody tr').count();
  }
}

// 使用
import { ModelPage } from '../pages/ModelPage';

test('搜索模型', async ({ page }) => {
  const modelPage = new ModelPage(page);
  await modelPage.goto();
  await modelPage.search('GPT');
  const count = await modelPage.getRowCount();
  expect(count).toBeGreaterThan(0);
});
```

### 2. 使用Test Fixtures

```javascript
// fixtures/auth.js
import { test as base } from '@playwright/test';
import { login } from '../utils/login';

export const test = base.extend({
  authenticatedPage: async ({ page }, use) => {
    await login(page);
    await use(page);
  },
});

// 使用
import { test } from '../fixtures/auth';

test('已登录后访问模型页面', async ({ authenticatedPage }) => {
  await authenticatedPage.goto('/ai/backend/model/index');
  // 已经是登录状态
});
```

### 3. 并行测试

```javascript
// 在配置中启用
export default defineConfig({
  fullyParallel: true,
  workers: 4, // 4个并行worker
});

// 或在测试中控制
test.describe.configure({ mode: 'parallel' });
```

### 4. 测试隔离

```javascript
// 每个测试前清理数据
test.beforeEach(async ({ page }) => {
  await page.goto('/admin/test/reset-data');
  await login(page);
});
```

### 5. 等待策略

```javascript
// ❌ 不推荐：固定等待
await page.waitForTimeout(2000);

// ✅ 推荐：等待特定条件
await page.waitForSelector('.table', { state: 'visible' });
await page.waitForLoadState('networkidle');
await page.waitForResponse(resp => resp.url().includes('/api/'));
```

## 🐛 调试技巧

### 1. 截图和视频

```javascript
// 配置自动截图
use: {
  screenshot: 'only-on-failure',
  video: 'retain-on-failure',
}

// 手动截图
await page.screenshot({ path: 'screenshot.png' });

// 全页面截图
await page.screenshot({ path: 'full-page.png', fullPage: true });
```

### 2. 查看Trace

```javascript
// 配置trace
use: {
  trace: 'on-first-retry',
}

// 手动开启trace
await page.context().tracing.start({ screenshots: true, snapshots: true });
// ... 测试操作
await page.context().tracing.stop({ path: 'trace.zip' });

// 查看trace
npx playwright show-trace trace.zip
```

### 3. Console日志

```javascript
// 监听console
page.on('console', msg => console.log('PAGE LOG:', msg.text()));

// 监听页面错误
page.on('pageerror', error => console.log('PAGE ERROR:', error));
```

## 📈 性能测试

```javascript
test('页面加载性能', async ({ page }) => {
  const startTime = Date.now();
  
  await page.goto('/ai/backend/model/index');
  await page.waitForLoadState('networkidle');
  
  const loadTime = Date.now() - startTime;
  
  console.log(`Page load time: ${loadTime}ms`);
  expect(loadTime).toBeLessThan(1000); // 应该在1秒内加载
});
```

## 📦 CI/CD集成

### GitHub Actions示例

```yaml
name: E2E Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: 18
      - name: Install dependencies
        run: |
          cd tests/e2e
          npm ci
      - name: Install Playwright Browsers
        run: npx playwright install --with-deps
      - name: Run tests
        run: npx playwright test
      - uses: actions/upload-artifact@v3
        if: always()
        with:
          name: playwright-report
          path: tests/e2e/playwright-report/
```

## 📚 参考资源

- [Playwright官方文档](https://playwright.dev/)
- [Playwright测试最佳实践](https://playwright.dev/docs/best-practices)
- [Page Object Model](https://playwright.dev/docs/pom)
- [Test Fixtures](https://playwright.dev/docs/test-fixtures)

---

**提示**: 开始E2E测试前，确保服务器正在运行（`php bin/w s:sta`）。
