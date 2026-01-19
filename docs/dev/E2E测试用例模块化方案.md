# E2E 测试用例模块化方案

## 📋 概述

将 Playwright E2E 测试用例从集中式目录结构改为模块化结构，每个模块的测试用例存放在模块目录下，通过 JS 脚本动态收集并统一运行。

## 🎯 目标

1. **测试用例就近原则**：测试用例放在模块目录下，与模块代码一起管理
2. **自动收集**：通过 JS 脚本自动收集所有模块的测试用例
3. **统一运行**：收集后的测试用例统一通过 Playwright 运行
4. **模块信息同步**：系统升级时自动生成 modules.json，包含模块路径信息

## 📁 目录结构

### 当前结构（集中式）
```
tests/e2e/
├── specs/
│   └── frontend/
│       └── theme-override.spec.js
├── playwright.config.js
└── package.json
```

### 目标结构（模块化）
```
app/code/Weline/Theme/
├── test/
│   └── e2e/
│       └── frontend/
│           └── theme-override.spec.js
├── register.php
└── ...

app/code/WeShop/Catalog/
├── test/
│   └── e2e/
│       └── frontend/
│           └── category.spec.js
├── register.php
└── ...

tests/e2e/  # 根测试目录（保留用于配置和运行）
├── collect-tests.js  # 测试用例收集脚本
├── playwright.config.js  # Playwright 配置（支持动态测试）
├── package.json
└── modules.json  # 模块信息（由系统命令生成）
```

## 📄 modules.json 格式

```json
{
  "generated_at": "2024-01-15T10:30:00+08:00",
  "modules": {
    "Weline_Theme": {
      "name": "Weline_Theme",
      "base_path": "app/code/Weline/Theme",
      "test_path": "app/code/Weline/Theme/test/e2e",
      "status": true,
      "version": "1.0.1"
    },
    "WeShop_Catalog": {
      "name": "WeShop_Catalog",
      "base_path": "app/code/WeShop/Catalog",
      "test_path": "app/code/WeShop/Catalog/test/e2e",
      "status": true,
      "version": "1.0.0"
    }
  }
}
```

## 🔧 实现步骤

### 步骤 1: 扩展系统升级命令生成 modules.json

**文件**: `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`

**功能**: 在 `executeModuleUpgrade` 方法中添加生成 modules.json 的逻辑

**实现要点**:
- 读取 `app/etc/modules.php` 获取所有模块信息
- 检查每个模块是否存在 `test/e2e/` 目录
- 生成 JSON 格式的 modules.json 到 `tests/e2e/modules.json`

### 步骤 2: 创建测试用例收集脚本

**文件**: `tests/e2e/collect-tests.js`

**功能**: 
- 读取 `modules.json`
- 遍历每个模块的 `test/e2e/` 目录
- 收集所有 `*.spec.js` 文件
- 生成测试文件列表供 Playwright 使用

**实现要点**:
- 使用 Node.js fs 模块扫描目录
- 支持递归扫描子目录
- 过滤掉非测试文件
- 生成相对路径列表

### 步骤 3: 修改 Playwright 配置支持动态测试

**文件**: `tests/e2e/playwright.config.js`

**功能**: 
- 使用收集脚本生成的测试文件列表
- 动态设置 `testMatch` 或 `testDir`
- 支持按模块过滤测试

**实现要点**:
- 在配置文件中调用收集脚本
- 使用 `require()` 加载收集的测试文件列表
- 支持 `--module` 参数过滤特定模块的测试

### 步骤 4: 移动现有测试用例

**操作**:
- 将 `tests/e2e/specs/frontend/theme-override.spec.js` 移动到 `app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js`

### 步骤 5: 更新 package.json 脚本

**文件**: `tests/e2e/package.json`

**添加脚本**:
```json
{
  "scripts": {
    "test": "playwright test",
    "test:collect": "node collect-tests.js",
    "test:module": "playwright test --module",
    "test:ui": "playwright test --ui"
  }
}
```

## 🔄 工作流程

1. **系统升级时**:
   ```bash
   php bin/w setup:upgrade
   ```
   - 自动生成 `tests/e2e/modules.json`

2. **运行测试前**:
   ```bash
   cd tests/e2e
   npm run test:collect  # 可选，Playwright 配置会自动收集
   ```

3. **运行测试**:
   ```bash
   npm test  # 运行所有模块的测试
   npm run test:module Weline_Theme  # 运行特定模块的测试
   ```

## 📝 文件清单

### 需要创建的文件
1. `tests/e2e/collect-tests.js` - 测试用例收集脚本
2. `docs/dev/E2E测试用例模块化方案.md` - 本文档

### 需要修改的文件
1. `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php` - 添加生成 modules.json 功能
2. `tests/e2e/playwright.config.js` - 支持动态测试收集
3. `tests/e2e/package.json` - 添加收集脚本命令

### 需要移动的文件
1. `tests/e2e/specs/frontend/theme-override.spec.js` → `app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js`

## ✅ 验收标准

1. ✅ 系统升级命令成功生成 `tests/e2e/modules.json`
2. ✅ 收集脚本能够扫描所有模块的测试用例
3. ✅ Playwright 能够运行收集到的测试用例
4. ✅ 支持按模块过滤运行测试
5. ✅ 现有测试用例能够正常运行

## 🚀 后续优化

1. **测试用例模板**: 为模块创建测试用例时提供模板
2. **测试覆盖率**: 统计每个模块的测试覆盖率
3. **CI/CD 集成**: 在 CI/CD 流程中自动运行测试
4. **测试报告**: 按模块生成测试报告
