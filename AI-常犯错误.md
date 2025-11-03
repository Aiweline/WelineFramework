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

## 快速检查清单

生成代码时，请检查以下事项：

- [ ] `register.php` 文件是否包含完整的 `Register::register()` 调用，参数是否完整
- [ ] 数据库升级方法是否使用 `hasField()` 而不是 `tableColumnExist()`
- [ ] 接口实现的方法签名是否与接口定义完全一致（参数类型、返回类型）
- [ ] 子类属性的访问级别是否与父类一致或更宽松
- [ ] 子类属性的类型声明是否与父类完全一致
- [ ] XML 配置文件是否使用正确的命名空间和属性格式
- [ ] 是否存在类名冲突（控制器类名与导入的模型类名相同），如有则使用别名

---

## 参考文档

- [Weline Framework 开发文档](../docs/dev/开发文档.md)
- [模型开发最佳实践](../docs/WelineFramework模型开发最佳实践.md)
- [数据库迁移系统规范](../docs/Weline_Database_Migration_System_Spec.md)

---

*最后更新：2025-01-XX*
*文档维护：AI 代码审查助手*

