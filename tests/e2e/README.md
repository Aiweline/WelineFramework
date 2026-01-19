# Playwright E2E 测试 - 模块化测试用例

## 📋 概述

本目录包含 Playwright E2E 测试的配置和运行脚本。测试用例采用**模块化结构**，每个模块的测试用例存放在模块目录下的 `test/e2e/` 目录中。

## 🎯 设计理念

- **测试用例就近原则**：测试用例与模块代码放在一起，便于维护
- **自动收集**：通过 JS 脚本自动收集所有模块的测试用例
- **统一运行**：收集后的测试用例统一通过 Playwright 运行

## 📁 目录结构

```
项目根目录/
├── app/code/
│   └── Weline/Theme/
│       └── test/e2e/          # 模块测试用例目录
│           └── frontend/
│               └── theme-override.spec.js
└── tests/e2e/                  # 测试配置和运行目录
    ├── collect-tests.js        # 测试用例收集脚本
    ├── playwright.config.js    # Playwright 配置（支持动态测试收集）
    ├── package.json
    ├── modules.json            # 模块信息（由系统命令生成）
    └── collected-tests.json    # 收集结果（由收集脚本生成）
```

## 🚀 快速开始

### 一键运行（推荐）⭐

一条命令完成所有操作：检查 modules.json → 收集测试用例 → 运行测试

```bash
cd tests/e2e
npm start
```

**支持传递参数给 Playwright**：

```bash
# 运行特定模块的测试
npm start -- --module=Weline_Theme

# 使用 UI 模式（推荐，可以交互式调试）
npm start -- --ui

# 使用有头模式（可以看到浏览器窗口）
npm start -- --headed

# 运行特定测试文件
npm start -- app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js
```

### 分步操作

#### 1. 生成 modules.json

系统升级时会自动生成 `modules.json`：

```bash
php bin/w setup:upgrade
```

#### 2. 收集测试用例（可选）

测试用例收集会在运行 Playwright 时自动执行，也可以手动运行：

```bash
cd tests/e2e
npm run test:collect
```

#### 3. 运行测试

```bash
cd tests/e2e

# 运行所有模块的测试（自动收集）
npm test

# 运行特定模块的测试
npm run test:module -- --module=Weline_Theme

# 使用 UI 模式运行（推荐，可以交互式调试）
npm run test:ui

# 使用有头模式运行（可以看到浏览器窗口）
npm run test:headed
```

## 📝 创建模块测试用例

### 目录结构

在模块目录下创建测试用例：

```
app/code/YourModule/
└── test/
    └── e2e/
        └── frontend/          # 或 backend/
            └── your-test.spec.js
```

### 测试文件命名规范

- 测试文件必须以 `.spec.js` 结尾
- 建议使用描述性的文件名，如 `theme-override.spec.js`

### 示例测试用例

```javascript
// app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js
const { test, expect } = require('@playwright/test');

test.describe('Theme frontend override behavior', () => {
  test('should override parent theme files', async ({ page }) => {
    await page.goto('/');
    // 你的测试代码
  });
});
```

## 🔧 配置说明

### modules.json 格式

```json
{
  "generated_at": "2024-01-15T10:30:00+08:00",
  "modules": {
    "Weline_Theme": {
      "name": "Weline_Theme",
      "base_path": "app/code/Weline/Theme",
      "test_path": "app/code/Weline/Theme/test/e2e",
      "status": true,
      "version": "1.0.1",
      "has_tests": true
    }
  }
}
```

### Playwright 配置

`playwright.config.js` 会自动：
1. 读取 `modules.json`
2. 调用 `collect-tests.js` 收集所有测试用例
3. 动态设置 `testMatch` 指向收集到的测试文件

## 📊 测试报告

运行测试后，会生成 HTML 测试报告：

```bash
# 查看测试报告
npx playwright show-report
```

## 🐛 故障排除

### modules.json 不存在

**错误**: `modules.json 不存在！`

**解决**: 运行 `php bin/w setup:upgrade` 生成 modules.json

### 测试用例未收集到

**检查**:
1. 确认模块目录下有 `test/e2e/` 目录
2. 确认测试文件以 `.spec.js` 结尾
3. 检查 `modules.json` 中该模块的 `has_tests` 是否为 `true`

### 服务器连接失败

**错误**: `net::ERR_CONNECTION_REFUSED`

**解决**: 
1. 确保服务器正在运行：`php bin/w server:start`
2. 检查服务器是否在 `http://127.0.0.1:81` 上运行

## 📚 相关文档

- [E2E测试用例模块化方案](../../docs/dev/E2E测试用例模块化方案.md)
- [Playwright E2E测试指南](../../app/code/Weline/Ai/doc/开发/Playwright测试指南.md)
