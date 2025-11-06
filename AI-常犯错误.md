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

---

## 参考文档

- [Weline Framework 开发文档](../docs/dev/开发文档.md)
- [模型开发最佳实践](../docs/WelineFramework模型开发最佳实践.md)
- [数据库迁移系统规范](../docs/Weline_Database_Migration_System_Spec.md)

---

*最后更新：2025-01-XX*
*文档维护：AI 代码审查助手*
*PHP 版本要求：PHP 8.2+*

