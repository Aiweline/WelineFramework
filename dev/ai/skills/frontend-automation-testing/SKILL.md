---
name: frontend-automation-testing
description: |
  前端自动化测试 - Browser MCP 和 Playwright。仅限前端 UI 交互验证。
  后端测试请使用 PHPUnit 单元测试（php-unit-testing 技能）。
  
  触发词：前端测试, e2e, playwright, browser test, 浏览器测试, UI 测试,
  页面测试, 点击测试, 表单测试, browser_navigate, browser_snapshot
globs:
  - "**/test/e2e/**/*.js"
  - "**/test/e2e/**/*.spec.js"
alwaysApply: false
---

# 前端自动化测试

**定位**：仅用于前端 UI 交互验证，后端测试请使用 PHPUnit 单元测试。

## 测试方式选择

| 场景 | 推荐方式 |
|------|---------|
| 后端逻辑、模型、服务 | PHPUnit 单元测试 |
| HTTP 路由、API 接口 | http:req 命令 |
| 前端页面交互 | Browser MCP 或 Playwright |

## 一、Browser MCP（开发时快速验证）

### 基本操作

```javascript
// 导航到页面
browser_navigate({ url: 'http://127.0.0.1:9981/admin/login' })

// 获取页面快照（获取元素 ref）
browser_snapshot()

// 填写表单
browser_fill({ elementRef: 'ref', value: 'admin' })

// 点击按钮
browser_click({ elementRef: 'ref' })

// 等待
browser_wait({ ms: 2000 })
```

### 登录测试示例

```javascript
// 1. 导航到登录页
browser_navigate({ url: 'http://127.0.0.1:9981/admin/login' })

// 2. 获取快照
browser_snapshot()

// 3. 填写用户名密码
browser_fill({ elementRef: 'username-ref', value: 'admin' })
browser_fill({ elementRef: 'password-ref', value: 'admin' })

// 4. 点击登录
browser_click({ elementRef: 'login-button-ref' })

// 5. 等待并验证
browser_wait({ ms: 2000 })
browser_snapshot()  // 检查是否跳转到后台
```

## 二、Playwright E2E（CI/CD 场景）

### 测试文件位置

```
app/code/Vendor/Module/test/e2e/
├── frontend/
│   └── page.spec.js
└── backend/
    └── admin.spec.js
```

### 运行测试

```bash
cd tests/e2e
npm start                            # 运行全部
npm start -- --module=Vendor_Module  # 指定模块
npm start -- --ui                    # UI 调试模式
```

### 测试模板

```javascript
const { test, expect } = require('@playwright/test');

test.describe('页面测试', () => {
  test('页面加载正常', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h1')).toBeVisible();
  });

  test('表单提交成功', async ({ page }) => {
    await page.goto('/form');
    await page.fill('input[name="name"]', 'Test');
    await page.click('button[type="submit"]');
    await expect(page.locator('.success')).toBeVisible();
  });
});
```

## 三、与单元测试的关系

**原则**：前端测试是单元测试的补充，不是替代。

1. **业务逻辑** → PHPUnit 单元测试
2. **HTTP 接口** → http:req 命令
3. **UI 交互** → Browser MCP / Playwright

**禁止**：
- ❌ 只做前端测试不做单元测试
- ❌ 为每个功能创建单独的测试脚本
- ✅ 测试代码统一放在模块 `test/` 目录
