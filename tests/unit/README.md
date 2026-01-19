# 前端单元测试 - Vitest

## 📋 概述

使用 Vitest 进行前端 JavaScript 代码的单元测试，支持 watch 模式，随时更新随时测试。

## 🎯 特性

- ✅ **Watch 模式**：文件变更自动重新运行测试
- ✅ **快速执行**：基于 Vite，测试运行速度极快
- ✅ **UI 界面**：提供可视化测试界面
- ✅ **覆盖率报告**：自动生成代码覆盖率报告
- ✅ **模块化测试**：支持按模块组织测试用例

## 🚀 快速开始

### 安装依赖

```bash
cd tests/unit
npm install
```

### 运行测试

```bash
# 运行一次（不挂起进程，推荐用于 CI/CD）⭐
npm test
# 或
npm run test:run

# Watch 模式（文件变更自动测试，会持续运行）⚠️
npm start
# 或
npm run test:watch
# 注意：Watch 模式会持续运行，按 q 退出

# UI 模式（可视化界面）
npm run test:ui

# 生成覆盖率报告
npm run test:coverage
```

**一键启动 Watch 模式**：

```bash
npm start
```

文件变更时会自动重新运行相关测试，无需手动操作。

## 📁 目录结构

```
tests/unit/
├── vitest.config.js          # Vitest 配置
├── package.json              # 依赖配置
├── README.md                 # 本文档
└── specs/                    # 测试用例目录
    ├── Weline/
    │   ├── Theme/
    │   │   └── theme.test.js
    │   └── Frontend/
    │       └── weline.test.js
    └── WeShop/
        └── Search/
            └── search.test.js
```

## 📝 编写测试用例

### 测试文件位置

测试用例应该放在模块目录下，与源代码一起管理：

```
app/code/Weline/Theme/view/theme/frontend/assets/js/
├── theme.js              # 源代码
└── theme.test.js         # 测试用例（可选，也可以放在 tests/unit/specs/）
```

或者统一放在 `tests/unit/specs/` 目录下，按模块组织。

### 示例测试用例

```javascript
// tests/unit/specs/Weline/Theme/theme.test.js
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('Weline Theme.js', () => {
  let themeScript;
  
  beforeEach(() => {
    // 加载主题 JS 文件
    const themePath = resolve(__dirname, '../../../../app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js');
    themeScript = readFileSync(themePath, 'utf-8');
    
    // 在全局作用域执行脚本
    eval(themeScript);
  });
  
  afterEach(() => {
    // 清理全局变量
    delete window.Weline;
    delete window.__WelineThemeConfig;
  });
  
  it('should initialize Weline object', () => {
    expect(window.Weline).toBeDefined();
    expect(typeof window.Weline).toBe('object');
  });
  
  it('should have theme management methods', () => {
    expect(window.Weline.Theme).toBeDefined();
    expect(typeof window.Weline.Theme.setTheme).toBe('function');
  });
  
  it('should support module declaration', () => {
    expect(typeof window.Weline.declare).toBe('function');
  });
});
```

### 测试 DOM 操作

```javascript
import { describe, it, expect, beforeEach } from 'vitest';

describe('Search Manager', () => {
  let container;
  
  beforeEach(() => {
    // 创建测试 DOM
    container = document.createElement('div');
    container.innerHTML = `
      <form class="search-form">
        <input type="text" class="search-input" />
        <div class="suggestions"></div>
      </form>
    `;
    document.body.appendChild(container);
  });
  
  it('should initialize search input', () => {
    const input = container.querySelector('.search-input');
    expect(input).toBeTruthy();
  });
  
  it('should show suggestions on input', () => {
    const input = container.querySelector('.search-input');
    input.value = 'test';
    input.dispatchEvent(new Event('input'));
    
    const suggestions = container.querySelector('.suggestions');
    // 验证建议列表显示
    expect(suggestions).toBeTruthy();
  });
});
```

## 🔧 配置说明

### 路径别名

在 `vitest.config.js` 中配置了以下路径别名：

- `@` - 项目根目录
- `@Weline/Theme` - Weline_Theme 模块
- `@Weline/Frontend` - Weline_Frontend 模块
- `@theme` - 主题目录
- `@design` - 设计目录

使用示例：

```javascript
import theme from '@Weline/Theme/view/theme/frontend/assets/js/theme.js';
```

### Watch 模式

默认启用 watch 模式，文件变更会自动重新运行相关测试。

**排除监听的文件**：
- `node_modules/`
- `dist/`, `build/`
- `var/`, `generated/`
- `tests/e2e/`

## 📊 覆盖率报告

运行覆盖率测试：

```bash
npm run test:coverage
```

报告会生成在 `coverage/` 目录下，包括：
- HTML 报告（`coverage/index.html`）
- JSON 报告（`coverage/coverage-final.json`）

## 🎨 UI 模式

使用可视化界面运行测试：

```bash
npm run test:ui
```

会在浏览器中打开测试界面，可以：
- 查看测试结果
- 调试失败的测试
- 查看覆盖率
- 过滤测试用例

## 🐛 故障排除

### 模块解析失败

如果遇到模块路径解析问题，检查 `vitest.config.js` 中的 `resolve.alias` 配置。

### DOM API 不可用

确保在测试文件中导入 `vitest` 的 `describe`, `it`, `expect` 等 API，并且配置了 `environment: 'happy-dom'`。

### 全局变量未定义

如果测试的代码依赖 `window` 等全局变量，确保在 `beforeEach` 中正确设置。

## 📚 相关文档

- [Vitest 官方文档](https://vitest.dev/)
- [Happy DOM 文档](https://github.com/capricorn86/happy-dom)
