# AI 代码生成常犯错误记录

本文档记录在使用 AI 生成 Weline Framework 代码时常见的错误及修复方法。

## 目录
- [模块注册文件错误](#模块注册文件错误)
- [数据库表结构升级错误](#数据库表结构升级错误)
- [接口实现错误](#接口实现错误)
- [类属性访问级别错误](#类属性访问级别错误)
- [类属性类型声明错误](#类属性类型声明错误)
- [XML 配置文件格式错误](#xml-配置文件格式错误)
- [类名冲突错误](#类名冲突错误)
- [删除功能实现错误](#删除功能实现错误)
- [PHP 8.2+ 兼容性错误](#php-82-兼容性错误)
- [禁止使用 fetchOne() 方法](#禁止使用-fetchone-方法)
- [ORM 查询使用规范](#orm-查询使用规范)
- [环境配置读取错误](#环境配置读取错误)
- [禁止使用 routes.xml 文件](#禁止使用-routesxml-文件)

---

## 模块注册文件错误

### 错误示例
```php
// ❌ 错误：register.php 文件为空或 Register::register() 参数缺失
```

### 正确写法
```php
<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,           // 必需：注册类型
    'Module_Name',              // 必需：模块名称
    __DIR__,                    // 必需：目录路径（不能为空）
    '1.0.0',                    // 可选：版本号
    '模块描述',                  // 可选：描述
    [                           // 可选：依赖模块数组
        'Weline_Framework'
    ]
);
```

### 要点
- `Register::register()` 的前三个参数是必需的：类型、模块名、目录路径
- 目录路径必须使用 `__DIR__`，不能为空
- 依赖数组可以为空，但建议至少包含 `Weline_Framework`

---

## 数据库表结构升级错误

### 错误示例
```php
// ❌ 错误：使用不存在的 tableColumnExist 方法
if (!$setup->tableColumnExist(self::fields_IS_PUBLISHED)) {
    // ...
}
```

### 正确写法
```php
// ✅ 正确：使用 hasField 方法检查字段是否存在
public function upgrade(ModelSetup $setup, Context $context): void
{
    // 必须先检查表是否存在
    if ($setup->tableExist() && !$setup->hasField(self::fields_IS_PUBLISHED)) {
        $setup->alterTable()->addColumn(
            self::fields_IS_PUBLISHED,        // 字段名
            self::fields_IS_ACTIVE,           // 插入位置（after字段）
            TableInterface::column_type_SMALLINT,
            1,
            'not null default 0',
            '是否发布'
        )
        ->addIndex(TableInterface::index_type_KEY, 'idx_is_published', [self::fields_IS_PUBLISHED], '发布状态索引')
        ->alter();  // 必须调用 alter() 提交变更
    }
}
```

### 要点
- 使用 `$setup->hasField($fieldName)` 而不是 `tableColumnExist()`
- 添加字段前必须先检查表是否存在：`$setup->tableExist()`
- 使用 `$setup->alterTable()->addColumn()->alter()` 完成字段添加
- 必须调用 `alter()` 方法提交变更
- 不直接使用 `getConnection()->getTable()`，统一通过 `ModelSetup` 操作

---

## 接口实现错误

### 错误示例
```php
// ❌ 错误：方法签名与接口不匹配
use Weline\Framework\Setup\Data\DataInterface;

class Install implements InstallInterface
{
    public function setup(DataInterface $setup, Context $context): string
    {
        // ...
    }
}
```

### 正确写法
```php
// ✅ 正确：使用正确的参数类型和返回类型
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        // 安装逻辑
    }
}
```

### 要点
- `InstallInterface::setup()` 方法签名：`setup(Setup $setup, Context $context): void`
- 参数类型必须是 `Setup`，不是 `DataInterface`
- 返回类型必须是 `void`，不能是 `string` 或其他类型

---

## 类属性访问级别错误

### 错误示例
```php
// ❌ 错误：子类属性访问级别比父类更严格
class Multipass extends BackendController
{
    private BackendSession $session;  // 错误：父类中是 protected
}
```

### 正确写法
```php
// ✅ 正确：子类属性访问级别必须与父类相同或更宽松
class Multipass extends BackendController
{
    protected BackendSession $session;  // 正确：与父类一致
}
```

### 要点
- 子类的属性访问级别不能比父类更严格
- 如果父类是 `protected`，子类必须是 `protected` 或 `public`，不能是 `private`
- 访问级别从宽松到严格：`public` > `protected` > `private`

---

## 类属性类型声明错误

### 错误示例
```php
// ❌ 错误：属性类型必须与父类完全匹配
use Weline\Backend\Session\BackendSession;

class Multipass extends BackendController
{
    protected BackendSession $session;  // 错误：父类中是 Framework\App\Session\BackendSession
}
```

### 正确写法
```php
// ✅ 正确：属性类型必须与父类完全一致
use Weline\Framework\App\Session\BackendSession as FrameworkBackendSession;
use Weline\Backend\Session\BackendSession as BackendBackendSession;

class Multipass extends BackendController
{
    protected FrameworkBackendSession $session;  // 类型必须与父类一致
    
    public function __construct(BackendBackendSession $session)
    {
        parent::__construct();
        $this->session = $session;  // 运行时类型兼容
    }
    
    public function someMethod()
    {
        // 如果需要使用子类特有方法，使用类型断言或改用父类方法
        $this->session->login($user);  // 使用父类的 login 方法
    }
}
```

### 要点
- 属性类型声明必须与父类**完全一致**，不能使用子类型
- 即使子类继承自父类型，属性声明也必须使用父类型
- 运行时可以通过类型兼容性赋值子类型实例
- 如果需要子类特有方法，使用父类的通用方法，或进行类型转换

---

## XML 配置文件格式错误

### 错误示例
```xml
<!-- ❌ 错误：命名空间和属性格式不正确 -->
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
        xsi:noNamespaceSchemaLocation="urn:weline:framework:etc/events.xsd">
    <event name="Module::event_name">
        <observer name="Observer_Name" instance="Module\Observer\Observer" />
    </event>
</config>
```

### 正确写法
```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <!-- 事件说明 -->
    <event name="Module::event_name">
        <observer name="Module::observer_name" 
                  instance="Module\Observer\Observer" 
                  disabled="false" 
                  shared="true" 
                  sort="0"/>
    </event>
</config>
```

### 要点
- 命名空间前缀使用 `xmlns:xs` 而不是 `xmlns:xsi`
- Schema 位置必须是 `urn:Weline_Framework::Event/etc/xsd/event.xsd`
- 必须添加 `xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd"` 属性
- observer 必须包含以下属性：
  - `name`: 使用 `Module::observer_name` 格式
  - `instance`: 完整的类命名空间路径
  - `disabled="false"`: 是否禁用
  - `shared="true"`: 是否共享实例
  - `sort="0"`: 执行顺序

---

## 类名冲突错误

### 错误示例
```php
// ❌ 错误：控制器类名与导入的模型类名冲突
namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\Account;  // 导入模型类

class Account extends BackendController  // 错误：与导入的类名冲突
{
    private function getAccountModel(): Account
    {
        return ObjectManager::getInstance(Account::class);  // 无法确定是哪个 Account
    }
}
```

### 错误信息
```
Cannot declare class Weline\Cdn\Controller\Backend\Account because the name is already in use
```

### 正确写法
```php
// ✅ 正确：使用别名导入避免类名冲突
namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\Account as AccountModel;  // 使用别名

class Account extends BackendController  // 控制器类名保持不变
{
    private function getAccountModel(): AccountModel  // 使用别名
    {
        return ObjectManager::getInstance(AccountModel::class);
    }
    
    public function index()
    {
        $account = $this->getAccountModel();
        // 使用 AccountModel:: 访问常量
        $account->setData(AccountModel::fields_NAME, 'test');
    }
}
```

### 要点
- 当控制器类名与导入的模型类名相同时，必须使用别名（`as`）导入
- 别名命名建议：模型类使用 `Model` 后缀，如 `Account as AccountModel`
- 使用别名后，所有对模型类的引用都要使用别名
- 包括类型声明、常量访问、类名获取等都要使用别名

---

## 删除功能实现错误

### 错误示例
```php
// ❌ 错误：使用自定义 JavaScript 函数实现删除，没有使用框架提供的 w-delete 组件
function deleteAccount(id) {
    if (!confirm('确定要删除吗？')) {
        return;
    }
    fetch('/api/delete', {
        method: 'POST',
        body: 'id=' + id
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        }
    });
}

// ❌ 错误：后端只支持表单数据，不支持 JSON 请求
public function postDelete(): string
{
    $id = (int)$this->request->getPost('id');  // 只支持表单数据
    // ...
}
```

### 正确写法

#### 前端模板
```html
<!-- ✅ 正确：使用 w-delete 组件 -->
<button type="button" 
        class="btn btn-danger" 
        w-delete="true"
        w-url="<?= $this->getBackendUrl('*/backend/account/delete') ?>"
        w-method="POST"
        w-var-id="<?= $account->getData('account_id') ?>"
        w-msg="<?= __('确定要删除账户 "%{1}" 吗？此操作不可恢复！', htmlspecialchars($account->getData('name'))) ?>">
    <i class="mdi mdi-delete"></i> <?= __('删除') ?>
</button>

<!-- 在页面底部引入组件 -->
<js:part name="w-delete"/>
```

#### 后端控制器
```php
// ✅ 正确：使用 getParams() 同时支持 JSON 和表单数据
public function postDelete(): string
{
    // 支持 JSON 和表单数据
    $params = $this->request->getParams();
    $id = (int)($params['id'] ?? 0);

    if (!$id) {
        return $this->jsonResponse([
            'success' => false,
            'message' => __('账户ID不能为空')
        ]);
    }

    try {
        $account = $this->getAccountModel()->reset()->load($id);
        
        if (!$account->getData(AccountModel::fields_ACCOUNT_ID)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户不存在')
            ]);
        }

        $account->delete()->fetch();

        return $this->jsonResponse([
            'success' => true,
            'message' => __('账户删除成功')
        ]);
    } catch (\Exception $e) {
        return $this->jsonResponse([
            'success' => false,
            'message' => __('删除失败：%{1}', $e->getMessage())
        ]);
    }
}
```

### 要点
- **必须使用 `w-delete` 组件**：框架提供了智能删除确认组件，不要自定义 JavaScript 实现
- **必须引入组件**：在页面底部使用 `<js:part name="w-delete"/>` 引入组件
- **后端支持 JSON**：使用 `$this->request->getParams()` 同时支持 JSON 和表单数据，因为 `w-delete` 组件在 POST 请求时发送 JSON 数据
- **自动移除行**：删除成功后组件会自动移除对应的表格行，无需手动刷新页面
- **确认消息**：使用 `w-msg` 属性自定义确认消息，建议包含要删除的记录名称
- **HTTP方法**：使用 `w-method="POST"` 指定 HTTP 方法
- **参数传递**：使用 `w-var-*` 属性传递额外参数，如 `w-var-id="123"`

---

## PHP 8.2+ 兼容性错误

**重要提示**：PHP 8.2+ 对函数参数类型要求更严格，很多函数不再接受 `null` 值。必须确保传递给这些函数的参数是期望的类型。

### htmlspecialchars() 不能传入 null

#### 错误示例
```php
// ❌ 错误：PHP 8.2+ 中 htmlspecialchars() 不接受 null
$name = $page->getData('name');  // 可能返回 null
echo htmlspecialchars($name);  // 错误：Deprecated: Passing null to parameter #1 of htmlspecialchars() is deprecated
```

#### 正确写法
```php
// ✅ 正确：使用 null 合并运算符确保传递字符串
$name = $page->getData('name') ?? '';
echo htmlspecialchars($name);

// ✅ 或者使用三元运算符
$name = $page ? ($page->getData('name') ?? '') : '';
echo htmlspecialchars($name);

// ✅ 复杂表达式需要正确嵌套括号
echo htmlspecialchars(($page ? ($page->getData('handle') ?? '') : ''));
```

#### 常见场景
- 模板文件中：`htmlspecialchars($var ?? '')`
- 数组访问：`htmlspecialchars($array['key'] ?? '')`
- 对象方法调用：`htmlspecialchars(($obj ? $obj->getData('key') : '') ?? '')`
- 字符串拼接：`"style='" . htmlspecialchars($bgColor ?? '') . "'"`

---

### json_decode() 不能传入 null

#### 错误示例
```php
// ❌ 错误：PHP 8.2+ 中 json_decode() 不接受 null
$locales = $page->getData('locales');  // 可能返回 null
$data = json_decode($locales, true);  // 错误：Deprecated: Passing null to parameter #1 of json_decode() is deprecated
```

#### 正确写法
```php
// ✅ 正确：使用 null 合并运算符确保传递字符串
$locales = $page->getData('locales') ?? '';
$data = json_decode($locales, true);

// ✅ 或者直接使用
$data = json_decode($page->getData('locales') ?? '', true);
```

#### 常见场景
- 模型数据：`json_decode($model->getData('config') ?? '', true)`
- 请求数据：`json_decode($this->request->getPost('data') ?? '', true)`
- 文件内容：`json_decode(file_get_contents($file) ?: '', true)`

---

### addColumn() 的 $options 参数不能是 null

#### 错误示例
```php
// ❌ 错误：addColumn() 的 $options 参数必须是 string，不能是 null
$setup->createTable('表名')
    ->addColumn(
        self::fields_DETAILS,
        TableInterface::column_type_TEXT,
        null,      // $length 可以是 null
        null,      // ❌ 错误：$options 不能是 null
        '字段注释'
    )
    ->create();
```

#### 错误信息
```
Fatal error: Uncaught TypeError: Weline\Framework\Database\Connection\Adapter\Mysql\Table\Create::addColumn(): 
Argument #4 ($options) must be of type string, null given
```

#### 正确写法
```php
// ✅ 正确：对于可空字段，使用空字符串 '' 而不是 null
$setup->createTable('表名')
    ->addColumn(
        self::fields_DETAILS,
        TableInterface::column_type_TEXT,
        null,      // $length 可以是 null（TEXT 类型不需要长度）
        '',        // ✅ 正确：$options 使用空字符串表示可空字段
        '字段注释'
    )
    ->create();

// ✅ 对于非空字段
->addColumn(
    self::fields_MESSAGE,
    TableInterface::column_type_TEXT,
    null,
    'not null',   // ✅ 正确：非空字段使用 'not null'
    '消息内容'
)
```

#### 常见选项值
- 可空字段：`''`（空字符串）
- 非空字段：`'not null'`
- 带默认值：`'not null default 0'`
- 主键自增：`'primary key auto_increment'`

---

### 其他常见 PHP 8.2+ 兼容性问题

#### str_replace() / str_contains() 等字符串函数
```php
// ❌ 错误
str_replace('old', 'new', $text);  // $text 可能为 null

// ✅ 正确
str_replace('old', 'new', $text ?? '');
```

#### array_merge() / array_key_exists() 等数组函数
```php
// ❌ 错误
array_merge($array1, $array2);  // $array1 或 $array2 可能为 null

// ✅ 正确
array_merge($array1 ?? [], $array2 ?? []);
```

#### count() / sizeof() 等统计函数
```php
// ❌ 错误（PHP 8.2+ 中 count(null) 会报错）
count($array);  // $array 可能为 null

// ✅ 正确
count($array ?? []);
```

#### strlen() / mb_strlen() 等长度函数
```php
// ❌ 错误
strlen($string);  // $string 可能为 null

// ✅ 正确
strlen($string ?? '');
```

---

### 要点总结

1. **使用 null 合并运算符 `??`**：
   - 对于可能为 null 的变量，使用 `$var ?? ''` 或 `$var ?? []` 提供默认值
   - 字符串类型使用 `''`，数组类型使用 `[]`

2. **复杂表达式需要正确嵌套括号**：
   ```php
   // ❌ 错误：括号不匹配
   htmlspecialchars($page ? $page->getData('handle') ?? '' : '')
   
   // ✅ 正确：明确优先级
   htmlspecialchars(($page ? ($page->getData('handle') ?? '') : ''))
   ```

3. **检查函数签名**：
   - 查看 PHP 文档确认函数是否接受 null
   - PHP 8.2+ 中很多函数参数类型声明为 `string`，不再接受 null

4. **常见需要处理的函数**：
   - `htmlspecialchars()`, `htmlentities()`
   - `json_decode()`, `json_encode()`
   - `strlen()`, `mb_strlen()`
   - `str_replace()`, `str_contains()`, `str_starts_with()`, `str_ends_with()`
   - `count()`, `sizeof()`
   - `array_merge()`, `array_key_exists()`
   - 框架的 `addColumn()`, `alterColumn()` 等方法的 `$options` 参数

5. **批量修复建议**：
   - 使用 IDE 的查找替换功能批量修复
   - 搜索模式：`htmlspecialchars($` 替换为 `htmlspecialchars(($`（需要手动检查）
   - 搜索模式：`json_decode($` 替换为 `json_decode($`（需要手动检查）

---

## 禁止使用 fetchOne() 方法

**重要提示**：Weline Framework 中禁止使用 `fetchOne()` 方法。该方法在 `ConnectionFactory` 和 `ConnectorInterface` 中不存在，会导致运行时错误。

### 错误信息
```
Call to undefined method Weline\Framework\Database\ConnectionFactory::fetchOne()
Call to undefined method Weline\Framework\Database\Connection\Api\ConnectorInterface::fetchOne()
```

### 错误示例

#### 在 Model 对象上使用 fetchOne()
```php
// ❌ 错误：禁止使用 fetchOne()
$adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
$existing = $adapter->reset()->where('code', 'default')->fetchOne();

$config = $this->clear()
    ->where(self::fields_USER_ID, $userId)
    ->where(self::fields_CONFIG_KEY, $key)
    ->fetchOne();
```

#### 在 ConnectionFactory 上使用 fetchOne()
```php
// ❌ 错误：ConnectionFactory 没有 fetchOne() 方法
$setup->getConnection()->fetchOne("SHOW COLUMNS FROM `{$table}` LIKE 'field_name'");

$connection->fetchOne("SELECT COUNT(*) FROM users");
```

### 正确写法

#### 对于 Model 对象：使用 find()->fetch()
```php
// ✅ 正确：使用 find()->fetch() 获取单条记录
$adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
$existing = $adapter->reset()->where('code', 'default')->find()->fetch();

if ($existing && $existing->getId()) {
    // 处理已存在的记录
}

// ✅ 正确：查询用户配置
$config = $this->clear()
    ->where(self::fields_USER_ID, $userId)
    ->where(self::fields_CONFIG_KEY, $key)
    ->find()->fetch();

if ($config && $config->getId()) {
    return $config->getData(self::fields_CONFIG_VALUE);
}
```

#### 对于 ConnectionFactory：使用 query()->fetch()
```php
// ✅ 正确：使用 query()->fetch() 执行 SQL 查询
$result = $setup->getConnection()->query("SHOW COLUMNS FROM `{$table}` LIKE 'field_name'")->fetch();
$exists = !empty($result);

// ✅ 正确：查询记录数
$result = $connection->query("SELECT COUNT(*) as count FROM users")->fetch();
$count = $result[0]['count'] ?? 0;

// ✅ 正确：查询单条记录
$result = $connection->query("SELECT * FROM users WHERE id = 1")->fetch();
$user = $result[0] ?? null;
```

#### 使用 ModelSetup 的 hasField() 方法（推荐）
```php
// ✅ 正确：使用 hasField() 检查字段是否存在（推荐方式）
if ($setup->tableExist() && !$setup->hasField('field_name')) {
    $setup->alterTable()->addColumn(
        'field_name',
        '',
        TableInterface::column_type_VARCHAR,
        100,
        '',
        '字段注释'
    )->alter();
}
```

### 常见场景和正确用法

#### 场景1：检查记录是否存在
```php
// ❌ 错误
$user = $model->where('username', $username)->fetchOne();

// ✅ 正确
$user = $model->where('username', $username)->find()->fetch();
if ($user && $user->getId()) {
    // 记录存在
}
```

#### 场景2：获取单条记录
```php
// ❌ 错误
$adapter = $adapterModel->reset()
    ->where('code', $code)
    ->where('is_active', 1)
    ->fetchOne();

// ✅ 正确
$adapter = $adapterModel->reset()
    ->where('code', $code)
    ->where('is_active', 1)
    ->find()->fetch();
```

#### 场景3：执行原生 SQL 查询
```php
// ❌ 错误
$count = $connection->fetchOne("SELECT COUNT(*) FROM users");

// ✅ 正确
$result = $connection->query("SELECT COUNT(*) as count FROM users")->fetch();
$count = $result[0]['count'] ?? 0;
```

#### 场景4：检查字段是否存在
```php
// ❌ 错误
if (!$setup->getConnection()->fetchOne("SHOW COLUMNS FROM `{$table}` LIKE 'field_name'")) {
    // 添加字段
}

// ✅ 正确方式1：使用 hasField()（推荐）
if (!$setup->hasField('field_name')) {
    // 添加字段
}

// ✅ 正确方式2：使用 query()->fetch()
$result = $setup->getConnection()->query("SHOW COLUMNS FROM `{$table}` LIKE 'field_name'")->fetch();
if (empty($result)) {
    // 添加字段
}
```

### 要点总结

1. **Model 对象查询**：
   - ❌ 禁止：`->fetchOne()`
   - ✅ 正确：`->find()->fetch()`
   - 返回类型：Model 对象或数组

2. **ConnectionFactory 查询**：
   - ❌ 禁止：`->fetchOne()`
   - ✅ 正确：`->query($sql)->fetch()`
   - 返回类型：数组（二维数组，每行是一个关联数组）

3. **字段检查**：
   - ❌ 禁止：使用 `fetchOne()` 执行 `SHOW COLUMNS`
   - ✅ 推荐：使用 `$setup->hasField($fieldName)`
   - ✅ 备选：使用 `query()->fetch()` 执行 SQL

4. **返回值处理**：
   - `find()->fetch()` 返回 Model 对象，可以直接调用 `getId()` 等方法
   - `query()->fetch()` 返回数组，需要从数组中提取数据：`$result[0]['field_name']`

5. **空值检查**：
   - Model 查询：检查 `$model && $model->getId()`
   - SQL 查询：检查 `!empty($result)` 或使用 `$result[0] ?? null`

### 迁移指南

如果代码中使用了 `fetchOne()`，按以下步骤迁移：

1. **查找所有使用**：搜索 `->fetchOne(` 或 `fetchOne(`
2. **判断上下文**：
   - 如果是 Model 对象：替换为 `->find()->fetch()`
   - 如果是 ConnectionFactory：替换为 `->query($sql)->fetch()`
3. **调整返回值处理**：
   - Model 查询：保持原有逻辑（返回 Model 对象）
   - SQL 查询：从数组结果中提取数据
4. **测试验证**：确保功能正常

---

## ORM 查询使用规范

**重要提示**：Weline Framework 提供了强大的 ORM 系统，所有数据库查询必须使用 ORM 方法，禁止在业务逻辑中直接使用原生 SQL 查询。

### 核心原则

1. **必须使用 ORM 方法**：所有数据查询、更新、删除操作必须使用 ORM 方法
2. **必须调用 fetch()**：ORM 使用惰性执行机制，必须调用 `fetch()` 或 `fetchArray()` 才会真正执行
3. **禁止直接 SQL**：在 Model 和 Controller 的业务逻辑中，禁止直接使用 `query()` 执行 SELECT 查询
4. **DDL 操作例外**：表结构变更（ALTER TABLE、CREATE TABLE 等）可以在 `setup()` 和 `upgrade()` 方法中使用原生 SQL

### 错误示例

#### 错误1：直接使用 query() 执行 SELECT 查询
```php
// ❌ 错误：在业务逻辑中直接使用 SQL 查询
public function getUserList()
{
    $result = $this->query("SELECT * FROM users WHERE status = 1")->fetch();
    return $result;
}

// ❌ 错误：使用 ConnectionFactory 直接执行 SELECT
$connection = $this->getConnection();
$users = $connection->query("SELECT * FROM users")->fetch();
```

#### 错误2：缺少 fetch() 调用
```php
// ❌ 错误：缺少 fetch()，查询不会执行
$users = $this->where('status', 1)->select();
// $users 是查询构建器对象，不是查询结果

// ❌ 错误：删除操作缺少 fetch()
$this->where('id', $id)->delete();
// 删除不会执行

// ❌ 错误：更新操作缺少 fetch()
$this->where('status', 0)->update(['is_active' => 1]);
// 更新不会执行
```

#### 错误3：错误的链式调用
```php
// ❌ 错误：在 find() 后直接调用 getData()
$user = $this->where('id', 1)->find()->getData('name');
// find() 返回查询构建器，需要先 fetch()

// ❌ 错误：在 select() 后直接遍历
foreach ($this->where('status', 1)->select() as $user) {
    // 不会执行，select() 返回查询构建器
}
```

### 正确写法

#### 正确1：使用 ORM 方法查询
```php
// ✅ 正确：使用 ORM 方法查询单条记录
public function getUser($id)
{
    $user = $this->where('id', $id)->find()->fetch();
    if ($user && $user->getId()) {
        return $user;
    }
    return null;
}

// ✅ 正确：使用 ORM 方法查询多条记录
public function getActiveUsers()
{
    $users = $this->where('status', 1)
        ->order('create_time', 'DESC')
        ->select()
        ->fetch()
        ->getItems();
    return $users;
}

// ✅ 正确：使用 ORM 方法统计
public function getUserCount()
{
    return $this->where('status', 1)->total();
}
```

#### 正确2：必须调用 fetch() 或 fetchArray()
```php
// ✅ 正确：查询必须调用 fetch() 或 fetchArray()
$users = $this->where('status', 1)->select()->fetch();

// ✅ 正确：获取数组结果（推荐，直接返回数组）
$users = $this->where('status', 1)->select()->fetchArray();
// $users 是二维数组，每行是一个关联数组

// ✅ 正确：删除必须调用 fetch()
$this->where('id', $id)->delete()->fetch();

// ✅ 正确：更新必须调用 fetch()
$this->where('status', 0)
    ->update(['is_active' => 1])
    ->fetch();

// ✅ 正确：单条记录也可以使用 fetchArray()
$user = $this->where('id', 1)->find()->fetchArray();
// $user 是单个关联数组，如果没有记录返回空数组
```

#### 正确3：正确的链式调用
```php
// ✅ 正确：find() 后必须 fetch()
$user = $this->where('id', 1)->find()->fetch();
if ($user && $user->getId()) {
    $name = $user->getData('name');
}

// ✅ 正确：select() 后必须 fetch() 再 getItems()
$users = $this->where('status', 1)
    ->select()
    ->fetch()
    ->getItems();
foreach ($users as $user) {
    // 处理每条记录
}
```

### ORM 查询方法详解

#### 基础查询方法

```php
// 单条记录查询（返回 Model 对象）
$user = $model->where('id', 1)->find()->fetch();
// 返回：Model 对象或 false

// 单条记录查询（返回数组）
$user = $model->where('id', 1)->find()->fetchArray();
// 返回：单个关联数组，如果没有记录返回空数组 []

// 多条记录查询（返回 Model 对象数组）
$users = $model->where('status', 1)->select()->fetch()->getItems();
// 返回：Model 对象数组

// 多条记录查询（返回数组，推荐）
$users = $model->where('status', 1)->select()->fetchArray();
// 返回：二维数组，每行是一个关联数组

// 统计记录数
$count = $model->where('status', 1)->total();

// 按主键加载（返回 Model 对象）
$user = $model->load(1);
```

#### 条件查询

```php
// 等值查询
$users = $model->where('username', 'john')->select()->fetch();

// 比较查询
$users = $model->where('age', 18, '>=')->select()->fetch();

// 模糊查询
$users = $model->where('username', '%admin%', 'like')->select()->fetch();

// IN 查询
$users = $model->where('id', [1, 2, 3], 'in')->select()->fetch();

// 多条件组合
$users = $model->where('status', 1)
    ->where('age', 18, '>=', 'AND')
    ->where('username', '%admin%', 'like', 'OR')
    ->select()
    ->fetch();
```

#### 排序和分页

```php
// 排序
$users = $model->order('create_time', 'DESC')->select()->fetch();

// 多字段排序
$users = $model->order('status', 'ASC')
    ->order('create_time', 'DESC')
    ->select()
    ->fetch();

// 分页查询
$users = $model->pagination(1, 20)
    ->where('status', 1)
    ->select()
    ->fetch();
```

#### 更新和删除

```php
// 更新单条记录（已加载的模型）
$user = $model->load(1);
$user->setData('name', 'New Name');
$user->save();

// 批量更新（必须调用 fetch()）
$model->where('status', 0)
    ->update(['is_active' => 1])
    ->fetch();

// 删除单条记录（已加载的模型）
$user = $model->load(1);
$user->delete()->fetch();

// 批量删除（必须调用 fetch()）
$model->where('status', 0)->delete()->fetch();
```

### 何时可以使用原生 SQL

以下情况可以使用原生 SQL：

1. **表结构变更（DDL）**：在 `setup()` 和 `upgrade()` 方法中
   ```php
   public function upgrade(ModelSetup $setup, Context $context): void
   {
       // ✅ 正确：表结构变更可以使用原生 SQL
       if (!$setup->hasField('new_field')) {
           $setup->query("ALTER TABLE {$this->getTable()} ADD new_field VARCHAR(100)");
       }
   }
   ```

2. **复杂统计查询**：当 ORM 无法表达复杂查询时
   ```php
   // ✅ 正确：复杂统计可以使用原生 SQL
   $result = $this->query("
       SELECT 
           DATE(create_time) as date,
           COUNT(*) as count
       FROM users
       WHERE create_time >= '2024-01-01'
       GROUP BY DATE(create_time)
   ")->fetchArray();
   ```

3. **数据库管理操作**：TRUNCATE、DROP 等
   ```php
   // ✅ 正确：数据库管理操作可以使用原生 SQL
   $setup->query('TRUNCATE TABLE ' . $this->getTable());
   ```

### 常见错误场景

#### 场景1：忘记调用 fetch() 或 fetchArray()
```php
// ❌ 错误
$users = $model->where('status', 1)->select();
foreach ($users as $user) {  // 不会执行
    // ...
}

// ✅ 正确方式1：使用 fetch() 获取 Model 对象数组
$users = $model->where('status', 1)->select()->fetch()->getItems();
foreach ($users as $user) {
    // $user 是 Model 对象
    $name = $user->getData('name');
}

// ✅ 正确方式2：使用 fetchArray() 获取数组（推荐）
$users = $model->where('status', 1)->select()->fetchArray();
foreach ($users as $user) {
    // $user 是关联数组
    $name = $user['name'] ?? '';
}
```

#### 场景2：在业务逻辑中使用原生 SQL
```php
// ❌ 错误
public function getUserByEmail($email)
{
    return $this->query("SELECT * FROM users WHERE email = '{$email}'")->fetch();
}

// ✅ 正确
public function getUserByEmail($email)
{
    return $this->where('email', $email)->find()->fetch();
}
```

#### 场景3：错误的返回值处理
```php
// ❌ 错误
$user = $model->where('id', 1)->find();
$name = $user->getData('name');  // 错误：$user 是查询构建器

// ✅ 正确方式1：使用 fetch() 返回 Model 对象
$user = $model->where('id', 1)->find()->fetch();
if ($user && $user->getId()) {
    $name = $user->getData('name');
}

// ✅ 正确方式2：使用 fetchArray() 返回数组（推荐）
$user = $model->where('id', 1)->find()->fetchArray();
if (!empty($user)) {
    $name = $user['name'] ?? '';
}
```

### 要点总结

1. **必须使用 ORM 方法**：
   - ✅ 使用 `where()->select()->fetch()` 或 `where()->select()->fetchArray()` 查询多条记录
   - ✅ 使用 `where()->find()->fetch()` 或 `where()->find()->fetchArray()` 查询单条记录
   - ✅ 使用 `where()->update()->fetch()` 批量更新
   - ✅ 使用 `where()->delete()->fetch()` 批量删除
   - ✅ **推荐使用 `fetchArray()`**：当不需要 Model 对象的方法时，使用 `fetchArray()` 更高效
   - ❌ 禁止在业务逻辑中使用 `query("SELECT ...")`

2. **必须调用 fetch()**：
   - ORM 使用惰性执行，必须调用 `fetch()` 或 `fetchArray()` 才会执行
   - `select()`, `find()`, `update()`, `delete()` 都返回查询构建器，不是结果

3. **返回值处理**：
   - `find()->fetch()` 返回 Model 对象或 false
   - `find()->fetchArray()` 返回单个关联数组，没有记录返回空数组 `[]`
   - `select()->fetch()` 返回 Model 对象，调用 `getItems()` 获取 Model 对象数组
   - `select()->fetchArray()` 直接返回二维数组（推荐），每行是一个关联数组
   - **推荐使用 `fetchArray()`**：当不需要 Model 对象的方法时，使用 `fetchArray()` 更高效

4. **例外情况**：
   - 表结构变更（`setup()`, `upgrade()` 方法中）可以使用原生 SQL
   - 复杂统计查询可以使用原生 SQL
   - 数据库管理操作可以使用原生 SQL

---

## 环境配置读取错误

**重要提示**：Weline Framework 中读取环境配置必须使用 `Env::get()` 静态方法，使用点号（`.`）分隔符访问嵌套配置。不要使用 `getConfig()` 或 `getData()` 方法，它们可能无法正确读取嵌套配置。

### 错误示例

#### 错误1：使用 getConfig() 读取嵌套配置
```php
// ❌ 错误：getConfig() 虽然支持点号，但返回 null
$routerCacheEnabled = Env::getInstance()->getConfig('cache/status/router_cache') ?? 1;
// 返回 null，因为 getConfig() 不支持斜杠分隔符

// ❌ 错误：使用 getData() 虽然可以，但不是推荐方式
$routerCacheEnabled = Env::getInstance()->getData('cache/status/router_cache') ?? 1;
// 可以工作，但不是框架推荐的方式
```

#### 错误2：使用错误的路径分隔符
```php
// ❌ 错误：使用斜杠分隔符
$value = Env::get('cache/status/router_cache');
// getConfig() 不支持斜杠，会返回 null

// ❌ 错误：使用 getInstance() 调用实例方法
$value = Env::getInstance()->getConfig('cache.status.router_cache');
// 可以工作，但不是推荐方式，应该使用静态方法
```

### 正确写法

#### 正确1：使用静态方法 Env::get()
```php
// ✅ 正确：使用静态方法 Env::get()，使用点号分隔符
$routerCacheEnabled = Env::get('cache.status.router_cache', 1);
$frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);

// ✅ 正确：第二个参数是默认值
$adminArea = Env::get('admin', 'admin');
$apiArea = Env::get('api', 'rest');
```

#### 正确2：读取简单配置
```php
// ✅ 正确：读取顶级配置
$env = Env::get('env', 'local');
$debug = Env::get('debug', false);
$user = Env::get('user', 'www');

// ✅ 正确：读取嵌套配置（使用点号）
$cacheDefault = Env::get('cache.default', 'file');
$cacheStatus = Env::get('cache.status.router_cache', 1);
```

#### 正确3：读取模块配置
```php
// ✅ 正确：读取模块配置（第二个参数是模块名）
$moduleConfig = Env::get('config_key', 'ModuleName');

// ✅ 正确：读取模块配置（第三个参数是模块名，第二个是默认值）
$moduleConfig = Env::get('config_key', 'default_value', 'ModuleName');
```

### 配置读取方法对比

| 方法 | 使用方式 | 支持分隔符 | 推荐度 | 说明 |
|------|---------|-----------|--------|------|
| `Env::get()` | 静态方法 | 点号（`.`） | ⭐⭐⭐⭐⭐ | **推荐**：框架标准方式 |
| `Env::getInstance()->getConfig()` | 实例方法 | 点号（`.`） | ⭐⭐⭐ | 可用但不推荐 |
| `Env::getInstance()->getData()` | 实例方法 | 斜杠（`/`）或点号（`.`） | ⭐⭐ | 可用但不推荐 |

### 常见配置读取场景

#### 场景1：读取缓存配置
```php
// ✅ 正确
$routerCacheEnabled = Env::get('cache.status.router_cache', 1);
$frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
$cacheDefault = Env::get('cache.default', 'file');
```

#### 场景2：读取数据库配置
```php
// ✅ 正确
$dbHost = Env::get('db.master.hostname', '127.0.0.1');
$dbName = Env::get('db.master.database', 'weline');
$dbUser = Env::get('db.master.username', 'root');
```

#### 场景3：读取系统配置
```php
// ✅ 正确
$adminArea = Env::get('admin', 'admin');
$apiArea = Env::get('api', 'rest');
$apiAdminArea = Env::get('api_admin', 'api_admin');
```

#### 场景4：读取模块配置
```php
// ✅ 正确：读取模块配置
$moduleConfig = Env::get('config_key', 'ModuleName');

// ✅ 正确：读取模块配置（带默认值）
$moduleConfig = Env::get('config_key', 'default_value', 'ModuleName');
```

### 要点总结

1. **必须使用静态方法**：
   - ✅ 使用 `Env::get('config.key', $default)`
   - ❌ 不要使用 `Env::getInstance()->getConfig()` 或 `getData()`

2. **必须使用点号分隔符**：
   - ✅ 使用 `'cache.status.router_cache'`
   - ❌ 不要使用 `'cache/status/router_cache'`

3. **提供默认值**：
   - ✅ 使用 `Env::get('key', $default)` 提供默认值
   - 如果配置不存在，会返回默认值

4. **配置路径规则**：
   - 顶级配置：`Env::get('key')`
   - 嵌套配置：`Env::get('parent.child.grandchild')`
   - 数组配置：`Env::get('cache.status.router_cache')`

5. **模块配置**：
   - 第二个参数是模块名：`Env::get('key', 'ModuleName')`
   - 第三个参数是模块名：`Env::get('key', $default, 'ModuleName')`

### 配置文件结构

配置文件 `app/etc/env.php` 的结构：
```php
return [
    'env' => 'local',
    'cache' => [
        'default' => 'file',
        'status' => [
            'router_cache' => 1,
            'frontend_cache' => 1,
        ],
    ],
    'db' => [
        'master' => [
            'hostname' => '127.0.0.1',
            'database' => 'weline',
        ],
    ],
];
```

对应的读取方式：
```php
$env = Env::get('env', 'local');
$cacheDefault = Env::get('cache.default', 'file');
$routerCache = Env::get('cache.status.router_cache', 1);
$dbHost = Env::get('db.master.hostname', '127.0.0.1');
```

---

## 禁止使用 routes.xml 文件

**重要提示**：Weline Framework 的路由系统是自动扫描和注册的，**禁止使用 `routes.xml` 文件进行路由配置**。`routes.xml` 不是框架的规约文件，使用它会导致路由无法正确注册。

### 错误示例

#### 错误1：创建 routes.xml 文件配置路由
```xml
<!-- ❌ 错误：禁止使用 routes.xml 文件 -->
<!-- app/code/Weline/YourModule/etc/frontend/routes.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<routes>
    <route path="/yourmodule/test" method="GET">
        <controller>Weline\YourModule\Controller\Test\Index::index</controller>
    </route>
</routes>
```

#### 错误2：认为需要手动配置路由
```php
// ❌ 错误：认为需要创建 routes.xml 才能访问控制器
// 实际上框架会自动扫描 Controller 目录并注册路由
```

### 正确做法

#### 正确1：路由自动注册机制
Weline Framework 的路由系统会自动扫描 `Controller/` 目录下的控制器，并根据控制器位置和方法名自动生成路由。

**路由生成规则**：
- `Controller/Frontend/Test/Index.php` → `{模块router}/frontend/test/index`
- `Controller/Backend/User/List.php` → `{模块router}/backend/user/list`
- `Controller/Api/Rest/V1/User.php` → `rest/v1/{模块router}/user`

**控制器方法解析**：
- `index()` → `/` 或 `/index`
- `getData()` → `/data` (GET请求)
- `postSave()` → `/save` (POST请求)

#### 正确2：路由注册流程
```bash
# ✅ 正确：运行 setup:upgrade 命令自动注册路由
php bin/w setup:upgrade

# ✅ 正确：升级指定模块并注册路由
php bin/w setup:upgrade -m YourModule
```

#### 正确3：控制器位置规范
```php
// ✅ 正确：将控制器放在正确的目录下，框架会自动注册路由

// 前端控制器：Controller/Frontend/Test/Index.php
namespace Weline\YourModule\Controller\Frontend\Test;
class Index extends FrontendController
{
    public function index() { /* 路由：yourmodule/frontend/test/index */ }
}

// 后端控制器：Controller/Backend/User/List.php
namespace Weline\YourModule\Controller\Backend\User;
class List extends BackendController
{
    public function index() { /* 路由：yourmodule/backend/user/list */ }
}
```

### 路由配置方式

#### 方式1：模块路由别名（推荐）
```php
// ✅ 正确：在 etc/env.php 中配置路由别名
<?php
return [
    'router' => 'yourmodule'  // 设置路由别名，默认为模块名
];
```

#### 方式2：控制器自动注册
```php
// ✅ 正确：框架自动扫描 Controller 目录并注册路由
// 无需任何配置文件，只需将控制器放在正确位置
```

### 常见错误场景

#### 场景1：创建 routes.xml 文件
```xml
<!-- ❌ 错误：不要创建 routes.xml 文件 -->
<routes>
    <route path="/test" method="GET">
        <controller>Test::index</controller>
    </route>
</routes>
```

**正确做法**：
- 删除 `routes.xml` 文件
- 将控制器放在 `Controller/Frontend/Test/Index.php`
- 运行 `php bin/w setup:upgrade` 注册路由

#### 场景2：认为路由需要手动配置
```php
// ❌ 错误：认为需要手动配置路由才能访问
// 实际上框架会自动注册
```

**正确做法**：
- 理解框架的路由自动注册机制
- 将控制器放在正确的目录结构下
- 运行 `setup:upgrade` 命令注册路由

#### 场景3：路由无法访问时创建 routes.xml
```php
// ❌ 错误：路由无法访问时，不应该创建 routes.xml
// 应该检查：
// 1. 控制器位置是否正确
// 2. 是否运行了 setup:upgrade
// 3. 路由路径是否正确
```

**正确排查步骤**：
1. 检查控制器是否在正确的目录（`Controller/Frontend/` 或 `Controller/Backend/`）
2. 运行 `php bin/w setup:upgrade -m YourModule` 注册路由
3. 检查路由列表：`php bin/w route:list | Select-String -Pattern "yourmodule"`
4. 验证控制器方法名是否正确

### 要点总结

1. **禁止使用 routes.xml**：
   - ❌ 不要创建 `etc/frontend/routes.xml` 或 `etc/backend/routes.xml`
   - ❌ `routes.xml` 不是框架的规约文件
   - ✅ 框架会自动扫描 Controller 目录并注册路由

2. **路由自动注册**：
   - ✅ 将控制器放在 `Controller/Frontend/` 或 `Controller/Backend/` 目录
   - ✅ 运行 `php bin/w setup:upgrade` 自动注册路由
   - ✅ 路由格式：`{模块router}/{控制器目录}/{控制器名}/{方法名}`

3. **路由配置方式**：
   - ✅ 在 `etc/env.php` 中配置 `router` 别名
   - ✅ 通过控制器位置和方法名自动生成路由
   - ❌ 不要使用 `routes.xml` 手动配置

4. **路由调试**：
   - 使用 `php bin/w route:list` 查看所有注册的路由
   - 使用 `php bin/w setup:upgrade` 重新注册路由
   - 检查控制器位置和方法名是否正确

5. **特殊路由需求**：
   - 如果需要自定义路由，应该通过 `Controller/Router.php` 实现 `RouterInterface` 接口
   - 而不是使用 `routes.xml` 文件

---

## 快速检查清单

生成代码时，请检查以下事项：

- [ ] `register.php` 文件是否包含完整的 `Register::register()` 调用，参数是否完整
- [ ] 数据库升级方法是否使用 `hasField()` 而不是 `tableColumnExist()`
- [ ] 接口实现的方法签名是否与接口定义完全一致（参数类型、返回类型）
- [ ] 子类属性的访问级别是否与父类一致或更宽松
- [ ] 子类属性的类型声明是否与父类完全一致
- [ ] XML 配置文件是否使用正确的命名空间和属性格式
- [ ] 是否存在类名冲突（控制器类名与导入的模型类名相同），如有则使用别名
- [ ] 删除功能是否使用 `w-delete` 组件而不是自定义 JavaScript
- [ ] 后端删除方法是否使用 `getParams()` 支持 JSON 和表单数据
- [ ] **PHP 8.2+ 兼容性**：所有 `htmlspecialchars()` 调用是否使用 `?? ''` 处理 null
- [ ] **PHP 8.2+ 兼容性**：所有 `json_decode()` 调用是否使用 `?? ''` 处理 null
- [ ] **PHP 8.2+ 兼容性**：`addColumn()` 的 `$options` 参数是否使用 `''` 而不是 `null`
- [ ] **PHP 8.2+ 兼容性**：所有字符串函数参数是否处理了可能的 null 值
- [ ] **禁止使用 fetchOne()**：所有 Model 查询是否使用 `->find()->fetch()` 而不是 `->fetchOne()`
- [ ] **禁止使用 fetchOne()**：所有 ConnectionFactory 查询是否使用 `->query()->fetch()` 而不是 `->fetchOne()`
- [ ] **禁止使用 fetchOne()**：字段检查是否使用 `hasField()` 或 `query()->fetch()` 而不是 `fetchOne()`
- [ ] **ORM 使用规范**：所有业务逻辑查询是否使用 ORM 方法而不是直接 SQL
- [ ] **ORM 使用规范**：所有 ORM 查询是否调用了 `fetch()` 或 `fetchArray()`
- [ ] **ORM 使用规范**：批量更新和删除是否调用了 `fetch()`
- [ ] **ORM 使用规范**：是否在业务逻辑中直接使用 `query("SELECT ...")`（禁止）
- [ ] **环境配置读取**：是否使用 `Env::get()` 静态方法而不是 `getInstance()->getConfig()`
- [ ] **环境配置读取**：是否使用点号（`.`）分隔符而不是斜杠（`/`）
- [ ] **环境配置读取**：是否提供了默认值作为第二个参数
- [ ] **禁止使用 routes.xml**：是否创建了 `routes.xml` 文件（禁止使用）
- [ ] **禁止使用 routes.xml**：是否理解路由自动注册机制
- [ ] **禁止使用 routes.xml**：是否通过控制器位置自动生成路由

---

## 参考文档

- [Weline Framework 开发文档](../docs/dev/开发文档.md)
- [模型开发最佳实践](../docs/WelineFramework模型开发最佳实践.md)
- [数据库迁移系统规范](../docs/Weline_Database_Migration_System_Spec.md)

---

*最后更新：2025-01-XX*
*文档维护：AI 代码审查助手*
*PHP 版本要求：PHP 8.2+*

