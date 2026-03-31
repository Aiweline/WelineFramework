---
name: testing
description: 测试与质量。TDD、PHPUnit(`test/Unit/`)、`http:req` 路由验证、前端 Browser MCP/Playwright、QA、E2E 收口。禁止单独创建测试脚本。
globs:
  - "**/test/**/*.php"
  - "**/Test/**/*.php"
  - "**/test/e2e/**/*.js"
alwaysApply: false
---

# testing（测试与质量规范）

## 何时使用

- 单元测试、路由验证、功能测试、e2e、Playwright、http:req、QA、代码覆盖率审查

---

## 0) TDD 流程

1. **Red**：先定义失败用例、失败路由验证口或明确的验收断言
2. **Green**：再写实现让测试通过
3. **Refactor**：最后重构代码

> 如果场景暂时无法先写自动化测试，也要先把失败条件、验收口和原因写进当前任务的 `plan.md` / `result.md`

---

## 1) PHP 单元测试（PHPUnit / Pest）

### 测试文件位置
```
app/code/Vendor/Module/Test/Unit/
```

### 运行命令

```bash
# 默认运行所有测试（优先 Pest）
php bin/w phpunit:run

# 运行指定模块（最常用）
php bin/w phpunit:run --module=Vendor_Module

# 后台上下文（自动登录）
php bin/w phpunit:run -b Vendor_Module

# 指定测试文件
php bin/w phpunit:run --name=ExampleTest

# 生成覆盖率报告
php bin/w phpunit:run --coverage

# 强制使用 PHPUnit（不用 Pest）
php bin/w phpunit:run --phpunit

# Watch 模式（文件改动自动重跑）
php bin/w phpunit:run --watch

# 并行测试
php bin/w phpunit:run --parallel
```

### PHPUnit 配置

- **全局配置**：`phpunit.xml`（根目录）
- **模块配置**：`app/code/Vendor/Module/Test/phpunit.xml`
- **Bootstrap**：`app/bootstrap_phpunit.php`
- **覆盖率输出**：`coverage-html/`、`coverage.txt`、`coverage.xml`（Clover）

### Pest 支持

框架优先使用 Pest 2.x（更简洁的语法），可无缝替代 PHPUnit：

```bash
php bin/w phpunit:run --module=Vendor_Module  # 自动使用 Pest
```

---

## 2) 前端 JS 单元测试（Vitest）

### 配置
- **配置文件**：`tests/unit/vitest.config.js`
- **环境**：happy-dom（快速 DOM 模拟）
- **测试框架**：Vitest 1.6.x

### 运行命令

```bash
cd tests/unit

# 安装依赖
npm install

# 运行一次测试
npm test

# Watch 模式
npm start

# 生成覆盖率
npm run test:coverage
```

### 测试文件位置

```
tests/unit/specs/
├── Weline/Theme/theme.test.js
└── WeShop/Search/search.test.js
```

---

## 3) E2E 测试（Playwright）

### 配置
- **配置文件**：`tests/e2e/playwright.config.js`
- **测试框架**：Playwright 1.48.x
- **测试文件**：`tests/e2e/specs/backend/`、`tests/e2e/specs/frontend/`

### 运行命令

```bash
# 推荐：统一使用框架命令（无需 cd）
php bin/w e2e:run

# 有界面运行（可视化默认）
php bin/w e2e:run --project=chromium

# UI 模式
php bin/w e2e:run --ui --project=chromium

# 按模块运行
php bin/w e2e:run --module=Vendor_Module --project=chromium

# 跑单文件
php bin/w e2e:run specs/backend/WeShop_Cart-smoke-backend.spec.js --project=chromium

# 按用例标题关键词
php bin/w e2e:run --module=WeShop_Cart --case="remove item" --project=chromium

# 按用例 ID（推荐）
php bin/w e2e:run --module=WeShop_Cart --case-id=CART-REMOVE-001 --project=chromium

# 列可用模块
php bin/w e2e:run --list-modules

# 强制刷新测试收集映射
php bin/w e2e:run --refresh-collection --list-modules
```

### E2E Framework 封装

`tests/e2e/framework/index.js` 提供统一入口：

```javascript
const {
  test, expect,
  gotoFrontend, gotoBackend, gotoApi, loginAsAdmin, getRuntimeInfo,
  moduleDescribe, moduleCase
} = require('./framework');

// 跳转前台
await gotoFrontend(page, '/');

// 跳转后台
await gotoBackend(page, 'dashboard');

// 跳转 API
await gotoApi(page, 'rest/v1/module/action');

// 后台登录
await loginAsAdmin(page);

// 获取运行时信息
const runtimeInfo = getRuntimeInfo();

// 推荐写法：模块 + 用例标签
const MODULE = 'Vendor_Module';
moduleDescribe(test, MODULE, 'backend smoke', () => {
  moduleCase(test, { module: MODULE, id: 'BACKEND-SMOKE-001' }, 'admin page renders', async ({ page }) => {
    await loginAsAdmin(page);
    await gotoBackend(page, 'dashboard');
    await expect(page.locator('body')).toBeVisible();
  });
});
```

### 统一代理

E2E 测试默认通过本地统一代理（通常是 `https://localhost:3999`，但端口可能变化），自动处理：
- `/` → 前台
- `/@backend/...` → 后台
- `/@api/...` → 前台 API
- `/@backend-api/...` → 后台 API

#### E2E origin / port 约束

- `3999` 这类代理端口只是 Playwright 运行时的传输入口，不是业务 origin
- 对页面渲染出来的绝对 URL、`href`、`location` 断言，必须使用 `getRuntimeInfo().runtime.target_origin` 或 `buildTargetUrl()`
- 服务默认端口 `80` / `443` 不应出现在渲染 URL 中；非默认端口必须保留

### 环境变量

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `MODULE_FILTER` | - | 按模块过滤测试 |
| `PLAYWRIGHT_WORKERS` | 1 | 并行 worker 数 |
| `PLAYWRIGHT_TEST_TIMEOUT` | 90000 | 测试超时（ms） |
| `PLAYWRIGHT_EXPECT_TIMEOUT` | 10000 | expect 超时（ms） |
| `PLAYWRIGHT_DISABLE_PROXY` | - | 设为 1 禁用代理 |
| `PLAYWRIGHT_HEADLESS` | 0 | 设为 1 强制无界面模式 |

### 用例书写规范（新增）

- 新用例必须优先采用 `moduleDescribe/moduleCase`，确保标题携带 `[module:*]` 和 `[case:*]`
- `--case-id` 依赖 `[case:ID]` 标签；未迁移历史用例可先用 `--case` 关键词过滤
- 用例 ID 推荐格式：`DOMAIN-SCENE-###`（如 `CART-REMOVE-001`）
- 一个用例只验证一个可见行为，失败信息必须可定位（页面、接口、关键断言）

---

## 4) http:req 路由验证

快速验证路由、接口可用性：

```bash
# 前台首页
php bin/w http:request /

# 后台（自动登录）
php bin/w http:request admin -b
php bin/w http:request ai/backend/model -b

# API 接口
php bin/w http:request rest/v1/module/action -api

# 响应校验与 PHP 错误检测
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal

# 并发测试（100 并发）
php bin/w http:request / -C -t=100
```

> 新控制器后执行：`php bin/w setup:upgrade --route`

---

## 5) 前端 UI 测试

- **Browser MCP** / **Playwright** 仅用于前端 UI；后端优先 PHPUnit 或 `http:req`
- 涉及用户主路径、后台关键操作链路、跨模块集成流程时，默认补齐对应 e2e
- 如果暂不具备 e2e 条件，必须在当前任务 `result.md` 明确缺口、风险和补测计划

---

## 6) 测试覆盖范围

### 必须覆盖

| 类型 | 覆盖要求 |
|------|----------|
| **Controller** | 路由、参数校验、权限、响应格式 |
| **Service** | 业务逻辑、边界条件、异常处理 |
| **Model/Repository** | CRUD、数据映射、查询构建 |
| **Event Observer** | 事件触发、参数传递、副作用 |

### 推荐覆盖

| 类型 | 覆盖要求 |
|------|----------|
| **Api/Rest** | 请求/响应格式、错误码、认证 |
| **Filter/Query** | 筛选条件、排序、分页 |
| **Hook** | 模板渲染、数据注入 |

---

## 7) QA 原则

- 功能完成前必须验证；单元 / 集成 / 手动 / 浏览器 组合使用
- 主动发现问题再提交，不要把"未验证"包装成"已完成"
- 覆盖率报告：`coverage-html/index.html` 可视化查看

---

## 8) 最小命令示例

```bash
# PHP 单元测试
php bin/w phpunit:run -b Vendor_Module

# 路由验证
php bin/w http:req admin/dashboard -b

# E2E 测试
php bin/w e2e:run --module=Vendor_Module --project=chromium

# 前端单元测试
cd tests/unit && npm test
```

---

## 禁止

- 功能未验证就提交
- 为功能单独创建测试脚本（用框架内验证口）
- 前端改动跳过 UI 测试
- 在 `phpunit.xml` 中覆盖 annotation 严格检查（`beStrictAboutCoversAnnotation` 必须为 true）
