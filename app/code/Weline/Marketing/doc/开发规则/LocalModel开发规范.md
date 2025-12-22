# LocalModel 开发规范

## 概述

本文档定义了 Weline_Marketing 模块中使用 LocalModel 进行多语言翻译的开发规范，包括使用规范、测试编写规范、代码质量标准和常见问题解决方案。

## LocalModel 使用规范

### 1. 模型定义规范

#### 1.1 创建 LocalDescription 模型

**位置**: `app/code/Weline/Marketing/Model/Rule/LocalDescription.php`

**必须继承**: `Weline\I18n\LocalModel`

**必须定义**:
```php
<?php
declare(strict_types=1);

namespace Weline\Marketing\Model\Rule;

use Weline\I18n\LocalModel;
use Weline\Marketing\Model\Rule\Rule;

class LocalDescription extends LocalModel
{
    // 表名常量
    public const table = 'weline_marketing_rule_local_description';
    
    // 索引器常量
    public const indexer = 'marketing_rule_local_description';
    
    // 关联主表ID字段（必须）
    public const fields_ID = Rule::fields_ID;
    
    // 多语言字段定义
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
}
```

#### 1.2 字段常量规范

- **fields_ID**: 必须关联到主表的主键字段
- **多语言字段**: 使用 `fields_` 前缀，命名清晰
- **字段名**: 使用小写字母和下划线

### 2. 控制器使用规范

#### 2.1 加载翻译数据

在控制器中查询数据时，必须调用 `loadLocalDescription()` 方法：

```php
$rule = ObjectManager::getInstance(Rule::class);
$rule->reset()
    ->loadLocalDescription()  // 必须调用
    ->pagination()
    ->select()
    ->fetch();
```

#### 2.2 指定语言代码

如果需要指定特定语言，传入语言代码：

```php
$rule->loadLocalDescription('zh_Hans_CN');  // 指定中文
$rule->loadLocalDescription('en_US');      // 指定英文
```

#### 2.3 数据传递到视图

确保翻译数据正确传递到视图：

```php
$this->assign('rules', $rule->getItems());  // 包含翻译数据
```

### 3. 视图模板使用规范

#### 3.1 使用 <local> 标签

在视图模板中使用 `<local>` 标签显示翻译：

```html
<local model="Weline\Marketing\Model\Rule\LocalDescription" 
       field="name" 
       id="{{rule.id}}" 
       name="rule-name-{{rule.id}}">
    {{rule.local_name|rule.name}}
</local>
```

#### 3.2 模板变量语法

- **ID变量**: `{{rule.id}}` - 规则ID
- **翻译变量**: `{{rule.local_name|rule.name}}` - 优先使用翻译，不存在时回退到原始名称

#### 3.3 回退机制

始终提供回退值，确保翻译不存在时也能正常显示：

```html
{{rule.local_name|rule.name}}  <!-- 正确：有回退 -->
{{rule.local_name}}            <!-- 错误：无回退 -->
```

## 测试编写规范

### 1. 测试文件结构

```
Test/
├── Unit/
│   ├── Model/
│   │   └── Rule/
│   │       ├── LocalDescriptionTest.php
│   │       └── RuleLocalDescriptionTest.php
│   ├── Controller/
│   │   └── Backend/
│   │       └── RuleTest.php
│   └── View/
│       └── Backend/
│           └── Rule/
│               └── LocalTagTest.php
└── Integration/
    └── RuleLocalDescriptionIntegrationTest.php
```

### 2. 测试类规范

#### 2.1 类命名

- 测试类名: `*Test.php` 或 `Test*.php`
- 继承: `PHPUnit\Framework\TestCase` 或 `Weline\Framework\UnitTest\TestCore`

#### 2.2 测试方法命名

- 方法名: `test*` 开头，使用驼峰命名
- 方法名应清晰描述测试内容

**示例**:
```php
public function testLoadLocalDescription(): void
public function testTranslationDataPassedToView(): void
```

### 3. 测试用例结构

每个测试方法应包含：

1. **测试目标**: 明确测试的功能点
2. **前置条件**: 测试所需的数据和环境
3. **测试步骤**: 具体的测试操作
4. **预期结果**: 明确的断言验证
5. **后置清理**: 测试数据清理

**示例**:
```php
public function testSaveAndLoadTranslation(): void
{
    // 1. 前置条件：创建测试规则
    $rule = ObjectManager::getInstance(Rule::class);
    $rule->setData([...]);
    $rule->save();
    $ruleId = $rule->getId();
    
    try {
        // 2. 测试步骤：保存和读取翻译
        $translation = ObjectManager::getInstance(LocalDescription::class);
        $translation->setData([...]);
        $translation->save();
        
        $loaded = ObjectManager::getInstance(LocalDescription::class);
        $loaded->reset()->where(...)->find()->fetch();
        
        // 3. 预期结果：验证数据
        $this->assertEquals('测试规则', $loaded->getData('name'));
        
    } finally {
        // 4. 后置清理：删除测试数据
        $translation->delete();
        $rule->delete();
    }
}
```

### 4. 断言使用规范

#### 4.1 功能正确性断言

```php
$this->assertTrue($condition);
$this->assertFalse($condition);
$this->assertEquals($expected, $actual);
```

#### 4.2 数据类型断言

```php
$this->assertInstanceOf(ExpectedClass::class, $actual);
$this->assertIsArray($actual);
$this->assertIsString($actual);
```

#### 4.3 数据存在性断言

```php
$this->assertNotEmpty($actual);
$this->assertNull($actual);
$this->assertNotNull($actual);
```

#### 4.4 异常处理断言

```php
$this->expectException(\Exception::class);
$this->expectExceptionMessage('Expected message');
```

### 5. 测试数据管理

#### 5.1 测试数据创建

- 在 `setUp()` 中创建基础测试数据
- 在测试方法中创建特定测试数据
- 使用 `ObjectManager::getInstance()` 获取模型实例

#### 5.2 测试数据清理

- 在 `tearDown()` 中清理基础测试数据
- 在测试方法的 `finally` 块中清理特定测试数据
- 确保所有测试数据都被清理，避免影响其他测试

**示例**:
```php
protected function tearDown(): void
{
    if ($this->ruleModel->getId()) {
        // 清理关联的翻译数据
        $this->localModel->reset()
            ->where(LocalDescription::fields_ID, $this->ruleModel->getId())
            ->select()
            ->fetch()
            ->walk(function ($item) {
                $item->delete();
            });
        $this->ruleModel->delete();
    }
    parent::tearDown();
}
```

## 代码质量标准

### 1. 编码规范

- 遵循 PSR-12 编码规范
- 使用类型声明: `declare(strict_types=1);`
- 完整的 PHPDoc 注释
- 清晰的变量和方法命名

### 2. 代码注释

#### 2.1 类注释

```php
/**
 * LocalDescription 模型单元测试
 * 
 * 测试营销规则多语言翻译模型的功能
 */
```

#### 2.2 方法注释

```php
/**
 * 测试：loadLocalDescription() 方法功能验证
 * 
 * 验证 Rule 模型能够正确加载 LocalDescription 翻译数据
 */
```

### 3. 错误处理

- 所有数据库操作都应使用 try-catch 处理异常
- 测试方法中的异常不应被忽略
- 提供有意义的错误消息

### 4. 性能考虑

- 避免在测试中创建大量数据
- 使用批量操作减少数据库查询
- 及时清理测试数据，避免数据库膨胀

## 常见问题和解决方案

### 问题 1: loadLocalDescription() 返回空数据

**原因**: 
- 未正确调用 `loadLocalDescription()`
- 语言代码不匹配
- 翻译记录不存在

**解决方案**:
1. 确保在查询前调用 `loadLocalDescription()`
2. 检查语言代码是否正确
3. 验证翻译记录是否存在

### 问题 2: 视图模板中翻译不显示

**原因**:
- 模板变量语法错误
- 数据未正确传递到视图
- `<local>` 标签配置错误

**解决方案**:
1. 检查模板变量语法: `{{rule.local_name|rule.name}}`
2. 验证控制器中数据传递: `$this->assign('rules', $rule->getItems())`
3. 检查 `<local>` 标签的 model、field、id 属性

### 问题 3: 测试数据清理失败

**原因**:
- 外键约束
- 数据关联未清理
- 删除顺序错误

**解决方案**:
1. 先删除关联数据（翻译），再删除主数据（规则）
2. 使用 `walk()` 方法遍历删除关联数据
3. 在 `finally` 块中确保清理执行

### 问题 4: 复合主键冲突

**原因**:
- 同一规则ID和语言代码的组合已存在
- 未正确处理复合主键

**解决方案**:
1. 检查是否已存在相同的翻译记录
2. 使用 `update()` 而非 `save()` 更新现有记录
3. 验证复合主键配置正确

## 最佳实践

### 1. 模型设计

- 保持 LocalDescription 模型简单，只包含翻译字段
- 使用常量定义字段名，避免硬编码
- 确保字段名与数据库表结构一致

### 2. 控制器设计

- 统一在查询时调用 `loadLocalDescription()`
- 避免在多个地方重复加载翻译数据
- 确保数据格式符合视图要求

### 3. 视图设计

- 始终提供回退机制
- 使用 `<local>` 标签统一处理翻译显示
- 保持模板代码简洁易读

### 4. 测试设计

- 每个功能点至少一个正向测试用例
- 每个边界情况至少一个测试用例
- 每个异常情况至少一个测试用例
- 保持测试独立，不依赖其他测试

## 参考资源

- [WelineFramework 单元测试指南](../../../../docs/dev/单元测试.md)
- [LocalModel 接口定义](../../../../app/code/Weline/I18n/LocalModelInterface.php)
- [测试用例文档](../测试/规则名称多语言翻译测试用例.md)

