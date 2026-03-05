---
name: module-development
description: |
  Module development workflow for Weline Framework. CRITICAL for database schema changes and env configuration!
  
  Use when:
  - Creating modules, controllers, models, services
  - Adding backend controllers or admin pages（涉及后台时须同时开发菜单：etc/backend/menu.xml、etc/env.php）
  - Adding/modifying database columns or fields
  - Database schema changes（表结构使用声明式 #[Col]，由 setup:upgrade 触发 SchemaDiff）
  - Modifying register.php or version numbers
  - Database migration, table structure changes
  - Configuring env.php settings (server, cache, db, session, router, backend_router, etc.)
  - Working with server configuration and smart mode
  
  Keywords: 模块, module, 开发模块, create module, new module, 版本升级, version, 加字段, 添加字段, 新增字段, add column, add field, 数据库 schema, database schema, 表结构, schema, #[Col], #[Table], 声明式, SchemaDiff, SchemaParser, setup:upgrade, Setup/Install.php, s:up, 模块升级, register.php, 版本号, LocalModel, LocalDescription, column does not exist, 列不存在, env, env.php, 配置, config, configuration, server, servers, 服务器配置, worker_count, 进程数, 智能模式, auto, 环境变量, cache, session, db, database config, 后台, 菜单, menu, backend menu, menu.xml, 后台菜单
globs:
  - "app/code/**/*.php"
  - "**/register.php"
  - "**/Model/**/*.php"
  - "app/etc/env*.php"
alwaysApply: false
---

# Module Development with Mandatory Testing

This skill enforces a complete module development workflow that **REQUIRES** both unit tests and E2E tests. **No exceptions** - all module development must include comprehensive testing.

## CRITICAL: Testing is NON-NEGOTIABLE

**Every module development task MUST include:**

1. **Unit Tests** (PHPUnit) - Test individual components in isolation
2. **E2E Tests** (Playwright) - Test complete user workflows through the browser

**Development is NOT complete until ALL tests pass.**

## Development Workflow

### Phase 1: Planning

Before writing any code:

1. Identify components to create (Models, Controllers, Services, etc.)
2. Plan unit test cases for each component
3. Plan E2E test cases for user workflows
4. Create test directory structure

### Phase 2: Test-Driven Development (TDD)

Follow TDD workflow for each component:

```
1. Write failing test → 2. Implement code → 3. Make test pass → 4. Refactor
```

### Phase 3: Implementation with Tests

For each component:

| Component | Unit Test Location | What to Test |
|-----------|-------------------|--------------|
| Model | `Test/Unit/Model/` | CRUD operations, validation, relationships |
| Service | `Test/Unit/Service/` | Business logic, calculations, transformations |
| Controller | `Test/Unit/Controller/` | Request handling, response format (⚠️ See `weline-routing` skill for route naming) |
| Helper | `Test/Unit/Helper/` | Utility functions, formatting |

### Phase 4: E2E Testing

After unit tests pass, write E2E tests:

| Feature Type | E2E Test Location | What to Test |
|--------------|------------------|--------------|
| Backend Admin | `test/e2e/backend/` | Admin workflows, forms, tables |
| Frontend Page | `test/e2e/frontend/` | User interactions, navigation |

---

## 依赖注入（Dependency Injection）⚠️

Weline 框架使用**构造函数注入**模式。任何在类中使用的模型、服务都必须通过构造函数注入。

### 依赖注入三步骤

**1. 声明私有属性**
```php
private ModelClass $propertyName;
private ServiceClass $serviceName;
```

**2. 构造函数参数注入**
```php
public function __construct(
    ModelClass $propertyName,
    ServiceClass $serviceName
) {
```

**3. 构造函数内赋值**
```php
    $this->propertyName = $propertyName;
    $this->serviceName = $serviceName;
}
```

### 完整示例

```php
<?php

namespace Vendor\ModuleName\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Vendor\ModuleName\Model\MyModel;
use Vendor\ModuleName\Service\MyService;

class MyController extends BackendController
{
    // ✅ 1. 声明私有属性
    private MyModel $myModel;
    private MyService $myService;
    
    // ✅ 2. 构造函数参数注入
    public function __construct(
        MyModel $myModel,
        MyService $myService
    ) {
        // ✅ 3. 构造函数内赋值
        $this->myModel = $myModel;
        $this->myService = $myService;
    }
    
    public function index()
    {
        // ✅ 现在可以安全使用
        $data = $this->myModel->getData();
        $result = $this->myService->process($data);
        
        return $this->fetchJson(['success' => true, 'data' => $result]);
    }
}
```

### 常见错误 ❌

**错误 1：使用未注入的依赖**
```php
class MyController extends BackendController
{
    // ❌ 只声明属性，但没有注入
    // private MyModel $myModel;
    
    public function __construct() {
        // ❌ 没有注入
    }
    
    public function index()
    {
        // ❌ Undefined property 错误
        $data = $this->myModel->getData();
    }
}
```

**错误 2：属性类型与注入类型不匹配**
```php
class MyController extends BackendController
{
    // ❌ 类型声明错误
    private WrongModel $myModel;
    
    public function __construct(
        // ❌ 注入的是 MyModel，但属性声明是 WrongModel
        MyModel $myModel
    ) {
        $this->myModel = $myModel;  // 类型不匹配
    }
}
```

**错误 3：忘记赋值**
```php
class MyController extends BackendController
{
    private MyModel $myModel;
    
    public function __construct(MyModel $myModel)
    {
        // ❌ 忘记赋值给属性
        // $this->myModel = $myModel;
    }
    
    public function index()
    {
        // ❌ $this->myModel 仍为 null
        $data = $this->myModel->getData();
    }
}
```

### 为什么使用依赖注入？

| 优点 | 说明 |
|------|------|
| **松耦合** | 依赖通过接口注入，易于替换实现 |
| **可测试** | 可以注入 Mock 对象进行单元测试 |
| **自动装配** | 框架的 ObjectManager 自动解析依赖关系 |
| **类型安全** | 构造函数类型声明确保注入的对象类型正确 |
| **易维护** | 依赖关系明确，代码更易理解和维护 |

### 检查清单

创建控制器/服务时，确保：

- [ ] 所有使用的模型/服务都已在构造函数注入
- [ ] 私有属性已声明且类型正确
- [ ] 构造函数参数类型与属性类型一致
- [ ] 构造函数内已将参数赋值给属性
- [ ] 使用 IDE 的类型提示功能检查错误

---

### Phase 5: Verification

Run all tests and verify:

```bash
# Run unit tests
php bin/w phpunit:run -b YourModule

# Run E2E tests
cd tests/e2e
npm start -- --module=YourModule
```

## Directory Structure

```
app/code/Vendor/ModuleName/
├── Controller/
│   └── Backend/               # 后台控制器（若有）
├── Model/
├── Service/
├── etc/
│   ├── env.php                # 模块路由等配置（若有后台需配置 router/backend_router）
│   └── backend/
│       └── menu.xml           # 后台菜单（涉及后台功能时 REQUIRED）
├── view/
│   └── templates/
│       └── Backend/           # 后台模板（若有）
├── Test/                      # REQUIRED: Unit Tests
│   └── Unit/
├── test/                      # REQUIRED: E2E Tests
│   └── e2e/
└── register.php               # 模块注册文件（包含版本号）
```

---

## 后台功能与菜单 ⚠️ 涉及后台必须开发菜单

**规则：凡模块提供后台页面（Backend Controller / 后台列表、表单、设置等），必须同时开发后台菜单，否则用户无法在侧栏找到入口。**

### 必须完成的项

| 项目 | 说明 | 路径/示例 |
|------|------|-----------|
| 菜单配置 | 后台入口注册到菜单树 | `etc/backend/menu.xml` |
| 路由配置 | 供菜单 action 中 `*` 解析为模块路由 | `etc/env.php` 中 `router`、`backend_router` |
| 控制器与视图 | 菜单指向的页面 | `Controller/Backend/*`、`view/templates/Backend/*` |
| ACL 权限 | 菜单与控制器权限一致 | 控制器类/方法上的 `#[AclAttribute(...)]`，source 与 menu source 对应 |

### menu.xml 规范

- 文件位置：`app/code/Vendor/ModuleName/etc/backend/menu.xml`
- 根元素需带：`noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"`
- 每个菜单项 `<add>` 必须包含：`source`、`name`、`title`、`action`、`parent`、`icon`、`order`
- 父菜单：`parent` 可为 `Weline_Backend::business_module`（业务模块）或 `Weline_Backend::system_service`（系统服务）等；子菜单的 `parent` 为父菜单的 `source`
- 动作：使用 `*/backend/控制器名/方法名`，`*` 会在收集菜单时被替换为当前模块的 `backend_router`

### env.php 路由

- 路径：`app/code/Vendor/ModuleName/etc/env.php`
- 若模块有后台，需配置 `router` 与（建议）`backend_router`，以便菜单和 URL 正确生成：
  ```php
  return [
      'router' => 'your_module',        // 前端路由前缀
      'backend_router' => 'your_module', // 后台路由前缀（菜单 * 替换为此值）
  ];
  ```

### 菜单收集与生效

- 菜单在 **系统升级** 时写入数据库：执行 `php bin/w s:up`
- 模块需处于 **已启用** 状态，其菜单才会被收集并显示
- 新增或修改 `menu.xml` 后，需再次执行 `s:up` 才能看到或更新菜单

### ACL 父子级关系 ⚠️ 关键

**ACL 的三层父子级结构：**

```
menu.xml 定义的菜单项（type=menus）
    └── 控制器类上的 #[Acl]（继承 menu.xml 的 parent_source）
        └── 控制器方法上的 #[Acl]（parent_source = 控制器类的 source_id）
```

**关键规则：**

1. **menu.xml 定义菜单层级**：`parent` 属性指定父菜单
2. **控制器类 `#[Acl]`**：如果 `source_id` 与 menu.xml 中某项相同，自动继承其 `parent_source`
3. **控制器方法 `#[Acl]`**：父级自动指向控制器类的 `source_id`

> 详细说明参见技能 `acl-permission-system`

### 后台开发检查清单

涉及后台时请确认：

- [ ] 已创建 `etc/backend/menu.xml`，至少有一个子菜单指向后台页
- [ ] 已创建或更新 `etc/env.php`，配置 `router` / `backend_router`
- [ ] 控制器类/方法已加 `#[AclAttribute(...)]`，source 与菜单、权限体系一致
- [ ] 模板中链接使用 `$this->getBackendUrl('*/backend/控制器/方法')` 等，保证 * 解析正确
- [ ] 执行过 `php bin/w s:up`，且模块已启用，侧栏能看到对应菜单

---

## 模块版本升级 ⚠️ 重要

### register.php 结构

每个模块都有一个 `register.php` 文件用于注册模块信息：

```php
<?php
use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,           // 类型
    'Vendor_ModuleName',        // 模块名
    __DIR__,                    // 模块目录
    '1.0.0',                    // ⚠️ 版本号（关键！）
    '模块描述',                  // 描述
    ['Dependency_Module']       // 依赖模块
);
```

### 数据库表结构与业务初始化（声明式 Schema）

**已废弃**：Model 的 `install()`、`upgrade()`、`setup()` 及 `ModelSetup`/`hasField()` 表结构维护方式已废弃，不再通过 register.php 版本号触发 Model 升级。

**当前机制：**

1. **表结构**：在 Model 上使用 **声明式注解** `#[Table]`、`#[Col]`、`#[Index]` 定义表与字段，由 **SchemaDiffStage** 在 `php bin/w setup:upgrade` 时解析并同步到数据库。**必须为每个参与建表的列添加 #[Col]**，仅常量无注解的列不会被 SchemaParser 解析。`LocalModel` 子类（多语言翻译表）须显式为 `schema_fields_ID`、`schema_fields_local_code` 等加 #[Col]。详见 **database-model-standards** 技能中「2.1 SchemaParser 解析行为」与「2.2 LocalModel 子类表结构声明」。
2. **业务初始化 / 种子数据**：放在模块 **Setup/Install.php**（首次安装）、**Setup/Upgrade.php**（升级时）中执行，不在 Model 内写 install/upgrade/setup 逻辑。
3. **register.php 版本号**：仍可用于模块加载与依赖等；不再用于“触发 Model upgrade()”。

**加字段 / 改表结构**：在对应 Model 上增加或修改 `#[Col]`/`#[Index]`，然后执行 `php bin/w setup:upgrade` 即可。

---

## Unit Test Template

```php
<?php
declare(strict_types=1);

namespace Vendor\ModuleName\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vendor\ModuleName\Model\YourModel;
use Weline\Framework\Manager\ObjectManager;

class YourModelTest extends TestCase
{
    private YourModel $model;
    
    protected function setUp(): void
    {
        $this->model = ObjectManager::getInstance(YourModel::class);
    }
    
    public function testCreate(): void
    {
        // Arrange
        $data = ['name' => 'Test', 'status' => 1];
        
        // Act
        $this->model->setData($data);
        $result = $this->model->save();
        
        // Assert
        $this->assertNotNull($result->getId());
    }
    
    public function testRead(): void
    {
        // Test read operations
    }
    
    public function testUpdate(): void
    {
        // Test update operations
    }
    
    public function testDelete(): void
    {
        // Test delete operations
    }
}
```

## E2E Test Template

```javascript
// test/e2e/backend/your-feature.spec.js
const { test, expect } = require('@playwright/test');

test.describe('YourModule Admin Feature', () => {
  test.beforeEach(async ({ page }) => {
    // Login to admin
    await page.goto('/admin/login');
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/**');
  });
  
  test('should display list page', async ({ page }) => {
    await page.goto('/admin/yourmodule/index');
    await expect(page.locator('h1, h4')).toContainText(/列表|List/);
    await expect(page.locator('table')).toBeVisible();
  });
  
  test('should create new item', async ({ page }) => {
    await page.goto('/admin/yourmodule/create');
    await page.fill('input[name="name"]', 'Test Item');
    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-success')).toBeVisible();
  });
  
  test('should edit item', async ({ page }) => {
    await page.goto('/admin/yourmodule/edit?id=1');
    await page.fill('input[name="name"]', 'Updated Item');
    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-success')).toBeVisible();
  });
  
  test('should delete item', async ({ page }) => {
    await page.goto('/admin/yourmodule/index');
    await page.click('button.delete:first-child');
    await page.click('button.confirm-delete');
    await expect(page.locator('.alert-success')).toBeVisible();
  });
});
```

## Required Test Cases

### Unit Tests MUST Cover:

- [ ] Model CRUD operations
- [ ] Model validation rules
- [ ] Service business logic
- [ ] Controller request/response handling
- [ ] Helper utility functions
- [ ] Error scenarios and edge cases

### E2E Tests MUST Cover:

- [ ] Page loads correctly
- [ ] Form submissions work
- [ ] Data displays in tables/lists
- [ ] CRUD operations through UI
- [ ] Error messages display correctly
- [ ] Navigation works correctly
- [ ] User workflow completion

## Running Tests

### Unit Tests

```bash
# Run all module tests
php bin/w phpunit:run -b YourModule

# Run specific test class
php bin/w phpunit:run -b YourModule --filter=YourModelTest

# Run with coverage
php bin/w phpunit:run -b YourModule --coverage-html coverage/
```

### E2E Tests

```bash
# Navigate to e2e test directory
cd tests/e2e

# Run all tests
npm start

# Run specific module tests
npm start -- --module=YourModule

# Run with UI mode (debugging)
npm run test:ui

# Run headed (visible browser)
npm run test:headed
```

## Completion Checklist

**Module development is NOT complete until:**

- [ ] All unit tests written
- [ ] All unit tests passing
- [ ] All E2E tests written  
- [ ] All E2E tests passing
- [ ] Code coverage >= 80%
- [ ] No linter errors
- [ ] i18n translations added
- [ ] **若涉及后台**：已配置 `etc/backend/menu.xml` 与 `etc/env.php` 路由，并执行 `s:up` 后菜单可见

## Prohibited Practices

### NEVER Skip Testing

- **MUST NOT**: Complete module without unit tests
- **MUST NOT**: Complete module without E2E tests
- **MUST NOT**: Submit code with failing tests
- **MUST NOT**: Use manual testing only

### NEVER Leave Tests for Later

- **MUST NOT**: "I'll add tests later"
- **MUST NOT**: "Tests are optional"
- **MUST NOT**: "It works, no need to test"

## Penalty for Skipping Tests

If module development is completed without tests:

1. **Development is considered incomplete**
2. **Points deducted from quality score**
3. **Must add tests before feature is accepted**

## Quick Reference Commands

```bash
# Create unit test
php bin/w phpunit:create -b YourModule Model/YourModel

# Run unit tests
php bin/w phpunit:run -b YourModule

# Collect E2E tests
cd tests/e2e && npm run collect

# Run E2E tests
cd tests/e2e && npm start
```

## Extends 衍生功能集成

### 何时使用 Extends

当你的模块需要：
- **实现其他模块的扩展点**（如 AI 适配器、SEO Provider）
- **定义自己的扩展点**（允许其他模块扩展你的功能）
- **在不修改原模块的情况下添加功能**

### 快速指南

**实现扩展点（Extending）：**
```bash
# 1. 查看目标模块的扩展点定义
cat app/code/{TargetModule}/extends.php

# 2. 创建扩展目录
mkdir -p app/code/YourModule/extends/module/{TargetModule}/{ExtensionPoint}

# 3. 实现接口
# 命名空间：YourModule\Extends\Module\{TargetModule}\{ExtensionPoint}
```

**定义扩展点（Defining）：**
```bash
# 1. 创建 extends.php 规约文件
# 2. 创建 extends.md 文档
# 3. 定义接口（Interface）
# 4. 创建 Registry Service（收集实现）
```

### 示例：实现 SEO Sitemap Provider

```php
<?php
// app/code/YourModule/extends/module/Weline_Seo/SitemapProvider/YourProvider.php

namespace YourModule\Extends\Module\Weline_Seo\SitemapProvider;

use Weline\Seo\Interface\SitemapProviderInterface;

class YourProvider implements SitemapProviderInterface
{
    public function getScope(): string
    {
        return 'your_scope';
    }
    
    public function getModule(): string
    {
        return 'YourVendor_YourModule';
    }
    
    public function generateSitemaps(): array
    {
        // 实现逻辑
        return [];
    }
    
    public function getDescription(): string
    {
        return __('Your Provider Description');
    }
}
```

### 常见扩展点

| 模块 | 扩展点 | 接口 | 用途 |
|------|--------|------|------|
| Weline_Seo | SitemapProvider | `SitemapProviderInterface` | 生成 Sitemap |
| Weline_Seo | FeedProvider | `FeedProviderInterface` | 提供 SEO Feed |
| Weline_Ai | Adapter | `ScenarioAdapterInterface` | AI 场景适配 |

**详细指南**：使用 `implement-extends` 或 `create-extends` 技能

---

## 环境配置（env.php）

### 配置文件位置

```
app/etc/
├── env.php           # 实际配置文件（不提交到 Git）
└── env.sample.php    # 配置示例文件（提交到 Git）
```

### 配置优先级

**命令行参数 > env 配置 > 默认值**

### 配置结构（v2）

`env.php` 使用分组结构，顶级配置项为：

| 分组 | 说明 | 便捷方法 |
|------|------|----------|
| `system` | 系统配置（env、deploy、maintenance、lang、currency） | `Env::system('deploy')` |
| `db` | 数据库配置 | `Env::get('db')` |
| `sandbox_db` | 沙盒数据库配置 | `Env::get('sandbox_db')` |
| `cache` | 缓存配置 | `Env::get('cache')` |
| `session` | 会话配置 | `Env::get('session')` |
| `log` | 日志配置 | `Env::get('log')` |
| `server` | 服务器配置 | `Env::get('server')` |
| `router` | 路由配置（area_routes） | `Env::router('area_routes')` |
| `theme` | 主题配置 | `Env::get('theme')` |
| `dev` | 开发配置（php_cs、static_rand_version、phpunit_server） | `Env::dev('php_cs')` |

### 读取配置

```php
use Weline\Framework\App\Env;

// ===== 推荐方式：使用便捷方法 =====

// 读取 system 分组
$deploy = Env::system('deploy');                    // 'dev' 或 'prod'
$maintenance = Env::system('maintenance');          // bool
$lang = Env::system('lang');                        // 'zh_Hans_CN'

// 读取 router 分组
$areaRoutes = Env::router('area_routes');           // array

// 读取 dev 分组
$phpCs = Env::dev('php_cs');                        // bool
$staticRand = Env::dev('static_rand_version');      // bool

// 读取其他分组
$serverConfig = Env::get('server');                 // 整个 server 分组
$dbHost = Env::get('db.host');                      // 点号语法获取嵌套值

// ===== 使用 w_array_get 辅助函数（原始数组场景） =====

// 当直接操作 $config 数组（如 App::init() 中加载 env.php 后）时：
$config = require APP_PATH . 'etc/env.php';
$deploy = w_array_get($config, 'system.deploy', 'dev');   // 点号语法
$phpCs = w_array_get($config, 'dev.php_cs', false);

// ===== 传统方式（兼容） =====

$envConfig = Env::getInstance()->getConfig();
$workerCount = $envConfig['server']['worker_count'] ?? 'auto';
```

### ⚠️ 禁止：isset + 多层数组访问

```php
// ❌ 错误：冗长且易出错
if (isset($config['system']['deploy']) && $config['system']['deploy'] === 'dev') { }

// ✅ 正确：使用 w_array_get 或 Env 便捷方法
if (w_array_get($config, 'system.deploy') === 'dev') { }
if (Env::system('deploy') === 'dev') { }
```

### 服务器配置（server）

```php
'server' => [
    'host' => '127.0.0.1',      // 监听地址
    'port' => 9981,             // 监听端口
    'worker_count' => 'auto',   // Worker 数量：'auto'=智能模式，或具体数字
    'mode' => 'io',             // 工作模式：'io'=I/O密集型，'cpu'=CPU密集型
],
```

#### 智能模式（worker_count: 'auto'）

根据服务器性能自动推算进程数：

| 模式 | 计算公式 | 适用场景 |
|------|---------|---------|
| `io` | CPU核心数 × 2 | 数据库查询、API请求、文件I/O |
| `cpu` | CPU核心数 | 图像处理、加密计算、复杂算法 |

#### 多实例配置（servers）

```php
'servers' => [
    'api' => [
        'host' => '127.0.0.1',
        'port' => 9001,
        'worker_count' => 4,
        'mode' => 'io',
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 9002,
        'worker_count' => 2,
        'mode' => 'cpu',
    ],
],
```

启动指定实例：

```bash
php bin/w server:start api
php bin/w server:start websocket
```

### 添加新配置项

⚠️ **重要**：添加新配置项时，必须同时更新 `env.sample.php`！

1. 在 `env.php` 中添加配置
2. 在 `env.sample.php` 中添加带注释的示例
3. 在代码中使用 `Env::getInstance()->getConfig()` 读取

---

## Related Skills

- `implement-extends` - **实现扩展点**：使用其他模块定义的扩展点
- `create-extends` - **定义扩展点**：为你的模块创建可扩展接口
- `error-learning` - **自动学习**：遇到模块开发错误时自动调用
- `php-unit-testing` - Detailed PHPUnit testing guide
- `theme-development` - **REQUIRED for Theme/JS Development**: Module loading, URL generation, API requests
- `weline-routing` - **REQUIRED for Controllers**: Route naming, HTTP method mapping, URL generation
- `friendly-notifications` - User-friendly UI notifications (avoid alert/confirm/prompt)
- `error-tracking` - 错误跟踪和记录
- `frontend-automation-testing` - Detailed Playwright testing guide
- `quality-assurance` - Complete QA checklist
- `code-generation-standards` - Code standards
