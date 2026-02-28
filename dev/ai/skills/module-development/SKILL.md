---
name: module-development
description: |
  Module development workflow for Weline Framework. CRITICAL for database schema changes and env configuration!
  
  Use when:
  - Creating modules, controllers, models, services
  - Adding backend controllers or admin pages（涉及后台时须同时开发菜单：etc/backend/menu.xml、etc/env.php）
  - Adding/modifying database columns or fields
  - Upgrading module schema (install/upgrade methods)
  - Modifying register.php or version numbers
  - Database migration, table structure changes
  - Configuring env.php settings (server, cache, db, session, router, backend_router, etc.)
  - Working with server configuration and smart mode
  
  Keywords: 模块, module, 开发模块, create module, new module, 版本升级, upgrade, 字段升级, version, 加字段, 添加字段, 新增字段, add column, add field, 数据库升级, database upgrade, schema, 表结构, install, ModelSetup, alterTable, addColumn, hasField, s:up, 模块升级, register.php, 版本号, env, env.php, 配置, config, configuration, server, servers, 服务器配置, worker_count, 进程数, 智能模式, auto, 环境变量, cache, session, db, database config, 后台, 菜单, menu, backend menu, menu.xml, 后台菜单
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

### 版本升级触发机制

**⚠️ 关键规则：当需要执行 Model 的 `upgrade()` 方法时，必须更新 register.php 中的版本号！**

框架通过比较 register.php 中的版本号与数据库中存储的版本号来判断是否需要执行升级：
- 版本号相同 → 跳过 upgrade()
- 版本号变化 → 执行 upgrade()

### 升级流程

**1. 修改 Model 的 upgrade() 方法**

```php
// Model/YourModel.php
public function upgrade(ModelSetup $setup, Context $context): void
{
    // 添加新字段
    if (!$setup->hasField('new_column')) {
        $setup->alterTable()
            ->addColumn(
                'new_column',
                TableInterface::column_type_VARCHAR,
                255,
                'default ""',
                '新字段描述'
            )
            ->alter();
    }
}
```

**2. 更新 register.php 版本号**

```php
// ❌ 错误：不更新版本号，upgrade() 不会执行
Register::register(
    Register::MODULE,
    'Vendor_ModuleName',
    __DIR__,
    '1.0.0',  // ❌ 版本号未变
    '模块描述'
);

// ✅ 正确：更新版本号触发升级
Register::register(
    Register::MODULE,
    'Vendor_ModuleName',
    __DIR__,
    '1.0.1',  // ✅ 版本号增加
    '模块描述'
);
```

**3. 执行系统升级命令**

```bash
php bin/m s:up --module=Vendor_ModuleName
```

### 版本号规范

推荐使用语义化版本号 (Semantic Versioning)：

| 版本变化 | 含义 | 示例 |
|---------|------|------|
| `1.0.0` → `1.0.1` | 补丁版本：小修复、新字段 | 添加数据库字段 |
| `1.0.0` → `1.1.0` | 次版本：新功能、向后兼容 | 添加新 API |
| `1.0.0` → `2.0.0` | 主版本：重大变更、不兼容 | 架构重构 |

### 常见错误 ❌

**错误 1：只修改 upgrade() 不更新版本号**
```bash
# 修改了 Model/YourModel.php 的 upgrade() 方法
# 但忘记更新 register.php 的版本号
php bin/m s:up
# ❌ 结果：upgrade() 未执行，字段未添加
```

**错误 2：版本号格式错误**
```php
// ❌ 版本号必须是字符串格式
'1.0'      // ❌ 缺少补丁版本
'v1.0.0'   // ❌ 不要加 v 前缀
1.0        // ❌ 不是字符串

// ✅ 正确格式
'1.0.0'
'1.0.1'
'2.1.3'
```

### 升级检查清单

当添加/修改数据库字段时：

- [ ] 在 Model 的 `upgrade()` 方法中添加字段升级逻辑
- [ ] 使用 `hasField()` 检查字段是否存在（避免重复添加）
- [ ] **更新 register.php 中的版本号**
- [ ] 运行 `php bin/m s:up --module=ModuleName` 执行升级
- [ ] 验证字段已成功添加到数据库

---

## ModelSetup API 参考

`ModelSetup` 是模型数据库操作的核心类，用于 `install()` 和 `upgrade()` 方法中。

### 检查方法

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `$setup->tableExist()` | 检查表是否存在 | `bool` |
| `$setup->hasField('column_name')` | 检查字段是否存在 | `bool` |
| `$setup->hasIndex('index_name')` | 检查索引是否存在 | `bool` |

### 创建表

```php
public function install(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() === false) {
        $setup->createTable('表描述')
            ->addColumn(
                'id',
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '主键ID'
            )
            ->addColumn(
                'name',
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '名称'
            )
            ->addColumn(
                'status',
                TableInterface::column_type_INTEGER,
                0,
                'default 1',
                '状态'
            )
            ->addColumn(
                'created_at',
                TableInterface::column_type_TIMESTAMP,
                0,
                'default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'uk_name',
                'name',
                '名称唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                'status',
                '状态索引'
            )
            ->create();
    }
}
```

### 删除表

```php
// 删除表（如果存在）
$setup->dropTable();

// 删除指定表名
$setup->dropTable('table_name');

// 强制删除（忽略外键约束，仅 MySQL）
$setup->forceDropTable();
```

### 添加字段

```php
public function upgrade(ModelSetup $setup, Context $context): void
{
    if (!$setup->tableExist()) {
        return;
    }
    
    // 添加单个字段
    if (!$setup->hasField('new_field')) {
        $setup->alterTable()
            ->addColumn(
                'new_field',                           // 字段名
                TableInterface::column_type_VARCHAR,   // 类型
                255,                                   // 长度
                'default ""',                          // 配置
                '字段描述'                              // 注释
            )
            ->alter();  // ⚠️ 必须调用 alter() 执行
    }
    
    // 添加多个字段（链式调用）
    $alter = $setup->alterTable();
    $needAlter = false;
    
    if (!$setup->hasField('field_a')) {
        $alter->addColumn('field_a', TableInterface::column_type_VARCHAR, 100, 'default ""', '字段A');
        $needAlter = true;
    }
    
    if (!$setup->hasField('field_b')) {
        $alter->addColumn('field_b', TableInterface::column_type_INTEGER, 0, 'default 0', '字段B');
        $needAlter = true;
    }
    
    if ($needAlter) {
        $alter->alter();
    }
}
```

### 添加索引

```php
// 添加唯一索引（单字段）
if (!$setup->hasIndex('uk_email')) {
    $setup->alterTable()
        ->addIndex(
            TableInterface::index_type_UNIQUE,
            'uk_email',
            'email',
            '邮箱唯一索引'
        )
        ->alter();
}

// 添加唯一索引（多字段组合）
if (!$setup->hasIndex('uk_website_account')) {
    $setup->alterTable()
        ->addIndex(
            TableInterface::index_type_UNIQUE,
            'uk_website_account',
            ['website_id', 'account_id'],  // 多字段用数组
            '站点账户组合唯一索引'
        )
        ->alter();
}

// 添加普通索引
if (!$setup->hasIndex('idx_status')) {
    $setup->alterTable()
        ->addIndex(
            TableInterface::index_type_KEY,
            'idx_status',
            'status',
            '状态索引'
        )
        ->alter();
}
```

### 删除索引

```php
if ($setup->hasIndex('old_index_name')) {
    $setup->alterTable()
        ->dropIndex('old_index_name')
        ->alter();
}
```

### 删除字段

```php
if ($setup->hasField('deprecated_field')) {
    $setup->alterTable()
        ->deleteColumn('deprecated_field')
        ->alter();
}

// 带数据备份的删除（推荐）
$setup->deleteColumnWithBackup('field_name');
```

### 修改字段

```php
// 修改字段类型/长度
$setup->alterTable()
    ->modifyColumn(
        'field_name',
        TableInterface::column_type_VARCHAR,
        500,  // 新长度
        'default ""',
        '修改后的描述'
    )
    ->alter();
```

### 字段类型常量

```php
use Weline\Framework\Database\Api\Db\TableInterface;

TableInterface::column_type_INTEGER      // 整数
TableInterface::column_type_BIGINT       // 大整数
TableInterface::column_type_SMALLINT     // 小整数
TableInterface::column_type_TINYINT      // 微整数
TableInterface::column_type_VARCHAR      // 可变字符串
TableInterface::column_type_CHAR         // 固定字符串
TableInterface::column_type_TEXT         // 长文本
TableInterface::column_type_LONGTEXT     // 超长文本
TableInterface::column_type_DECIMAL      // 精确小数（如 '10,2'）
TableInterface::column_type_FLOAT        // 浮点数
TableInterface::column_type_DOUBLE       // 双精度浮点
TableInterface::column_type_BOOLEAN      // 布尔值
TableInterface::column_type_DATE         // 日期
TableInterface::column_type_DATETIME     // 日期时间
TableInterface::column_type_TIMESTAMP    // 时间戳
TableInterface::column_type_JSON         // JSON 数据
TableInterface::column_type_BLOB         // 二进制数据
```

### 索引类型常量

```php
TableInterface::index_type_KEY           // 普通索引
TableInterface::index_type_UNIQUE        // 唯一索引
TableInterface::index_type_FULLTEXT      // 全文索引
TableInterface::index_type_SPATIAL       // 空间索引
```

### 完整 upgrade() 示例

```php
public function upgrade(ModelSetup $setup, Context $context): void
{
    // 1. 前置检查：表必须存在
    if (!$setup->tableExist()) {
        return;
    }
    
    // 2. 删除旧索引（如果存在）
    if ($setup->hasIndex('old_unique_key')) {
        $setup->alterTable()
            ->dropIndex('old_unique_key')
            ->alter();
    }
    
    // 3. 添加新索引
    if (!$setup->hasIndex('new_composite_key')) {
        $setup->alterTable()
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'new_composite_key',
                ['field_a', 'field_b'],
                '组合唯一索引'
            )
            ->alter();
    }
    
    // 4. 添加新字段
    if (!$setup->hasField('new_config')) {
        $setup->alterTable()
            ->addColumn(
                'new_config',
                TableInterface::column_type_TEXT,
                0,
                '',
                'JSON 配置'
            )
            ->alter();
    }
    
    // 5. 添加带默认值的字段
    if (!$setup->hasField('priority')) {
        $setup->alterTable()
            ->addColumn(
                'priority',
                TableInterface::column_type_DECIMAL,
                '3,2',
                'default 0.50',
                '优先级（0.00-1.00）'
            )
            ->alter();
    }
}
```

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

### 读取配置

```php
use Weline\Framework\App\Env;

// 获取配置
$envConfig = Env::getInstance()->getConfig();

// 读取指定配置
$serverConfig = $envConfig['server'] ?? [];
$dbConfig = $envConfig['db'] ?? [];

// 检查配置项
$workerCount = $serverConfig['worker_count'] ?? 'auto';
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
