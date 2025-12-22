# Marketing LocalModel 测试用例编写规则

## 规则概述

本文档定义了为 Weline_Marketing 模块的 LocalModel 功能编写单元测试用例的规则和标准。所有测试用例必须遵循这些规则，确保测试覆盖全面、标准化、高质量。

## 核心原则

### 1. 测试驱动开发 (TDD)

- **MUST**: 先编写测试用例，确保测试失败
- **MUST**: 实现功能使测试通过
- **MUST**: 重构代码保持测试通过
- **MUST NOT**: 删除测试文件，即使测试暂时失败也必须修复而非删除

### 2. 测试覆盖要求

- **MUST**: 每个功能点至少 1 个正向测试用例
- **MUST**: 每个边界情况至少 1 个测试用例
- **MUST**: 每个异常情况至少 1 个测试用例
- **MUST**: 所有测试用例必须通过

### 3. 代码覆盖率标准

- **模型层**: ≥ 90%
- **控制器层**: ≥ 80%
- **视图层**: ≥ 70%
- **整体覆盖率**: ≥ 85%

## 测试用例编写规则

### 1. 测试文件命名

- **MUST**: 测试文件以 `*Test.php` 或 `Test*.php` 结尾
- **MUST**: 测试文件位于对应的 `Test/Unit/` 或 `Test/Integration/` 目录
- **MUST**: 测试文件路径与源代码路径对应

**示例**:
```
源代码: app/code/Weline/Marketing/Model/Rule/LocalDescription.php
测试文件: app/code/Weline/Marketing/Test/Unit/Model/Rule/LocalDescriptionTest.php
```

### 2. 测试类结构

#### 2.1 类声明

```php
<?php
declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;

class LocalDescriptionTest extends TestCase
{
    // 测试类内容
}
```

#### 2.2 必需方法

- **MUST**: 实现 `setUp()` 方法初始化测试环境
- **MUST**: 实现 `tearDown()` 方法清理测试数据
- **MUST**: 所有测试方法以 `test` 开头

### 3. 测试方法编写规范

#### 3.1 方法命名

- **MUST**: 方法名以 `test` 开头
- **MUST**: 使用驼峰命名法
- **MUST**: 方法名清晰描述测试内容

**示例**:
```php
public function testModelInstantiation(): void
public function testSaveAndLoadTranslation(): void
public function testFallbackWhenTranslationNotExists(): void
```

#### 3.2 方法结构

每个测试方法必须包含以下部分：

1. **测试目标注释**: 描述测试的功能点
2. **前置条件**: 创建测试所需的数据和环境
3. **测试步骤**: 执行具体的测试操作
4. **预期结果**: 使用断言验证结果
5. **后置清理**: 清理测试数据（使用 try-finally）

**模板**:
```php
/**
 * 测试：功能描述
 * 
 * 详细说明测试的内容和目的
 */
public function testFunctionName(): void
{
    // 1. 前置条件：创建测试数据
    $testData = [...];
    
    try {
        // 2. 测试步骤：执行操作
        $result = $this->model->someMethod($testData);
        
        // 3. 预期结果：验证断言
        $this->assertNotNull($result);
        $this->assertEquals($expected, $result);
        
    } finally {
        // 4. 后置清理：删除测试数据
        $this->cleanupTestData();
    }
}
```

### 4. 断言使用规范

#### 4.1 功能正确性断言

```php
// 验证布尔值
$this->assertTrue($condition, '错误消息');
$this->assertFalse($condition, '错误消息');

// 验证相等性
$this->assertEquals($expected, $actual, '错误消息');
$this->assertNotEquals($expected, $actual, '错误消息');
```

#### 4.2 数据类型断言

```php
// 验证类型
$this->assertInstanceOf(ExpectedClass::class, $actual);
$this->assertIsArray($actual);
$this->assertIsString($actual);
$this->assertIsInt($actual);
```

#### 4.3 数据存在性断言

```php
// 验证存在性
$this->assertNotEmpty($actual, '数据不应为空');
$this->assertNull($actual, '数据应为空');
$this->assertNotNull($actual, '数据不应为空');
```

#### 4.4 异常处理断言

```php
// 验证异常
$this->expectException(\Exception::class);
$this->expectExceptionMessage('Expected error message');
```

### 5. 测试数据管理

#### 5.1 测试数据创建

- **MUST**: 在 `setUp()` 中创建基础测试数据
- **MUST**: 在测试方法中创建特定测试数据
- **MUST**: 使用 `ObjectManager::getInstance()` 获取模型实例

**示例**:
```php
protected function setUp(): void
{
    parent::setUp();
    $this->model = ObjectManager::getInstance(LocalDescription::class);
}
```

#### 5.2 测试数据清理

- **MUST**: 在 `tearDown()` 中清理基础测试数据
- **MUST**: 在测试方法的 `finally` 块中清理特定测试数据
- **MUST**: 确保所有测试数据都被清理，避免影响其他测试

**示例**:
```php
protected function tearDown(): void
{
    if ($this->model->getId()) {
        $this->model->delete();
    }
    parent::tearDown();
}
```

### 6. 测试用例分类

#### 6.1 模型层测试

**位置**: `Test/Unit/Model/Rule/`

**必须测试**:
- 模型实例化和继承关系
- 字段常量定义
- 数据设置和获取
- 多语言翻译保存和读取
- 复合主键处理
- CRUD 操作

#### 6.2 集成测试

**位置**: `Test/Unit/Model/Rule/` 或 `Test/Integration/`

**必须测试**:
- `loadLocalDescription()` 方法功能
- 翻译数据自动加载
- 多语言字段合并
- 不同语言代码的翻译切换
- 翻译不存在时的回退机制

#### 6.3 控制器测试

**位置**: `Test/Unit/Controller/Backend/`

**必须测试**:
- `loadLocalDescription()` 调用验证
- 翻译数据传递到视图
- 搜索功能兼容性
- 分页功能兼容性
- 异常处理机制

#### 6.4 视图测试

**位置**: `Test/Unit/View/Backend/Rule/`

**必须测试**:
- 模板变量数据准备
- 模板变量语法验证
- 翻译数据正确显示
- 翻译不存在时的回退显示
- 多语言切换功能

## 测试用例模板

### 模型测试模板

```php
<?php
declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;

class LocalDescriptionTest extends TestCase
{
    private LocalDescription $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(LocalDescription::class);
    }

    protected function tearDown(): void
    {
        if ($this->model->getId()) {
            $this->model->delete();
        }
        parent::tearDown();
    }

    /**
     * 测试：功能描述
     * 
     * 详细说明测试的内容和目的
     */
    public function testFunctionName(): void
    {
        // 测试实现
    }
}
```

### 集成测试模板

```php
<?php
declare(strict_types=1);

namespace Weline\Marketing\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

class RuleLocalDescriptionIntegrationTest extends TestCase
{
    private Rule $ruleModel;
    private LocalDescription $localModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleModel = ObjectManager::getInstance(Rule::class);
        $this->localModel = ObjectManager::getInstance(LocalDescription::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->ruleModel->getId()) {
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

    /**
     * 测试：完整流程测试
     */
    public function testCompleteFlow(): void
    {
        // 测试实现
    }
}
```

## 质量标准

### 1. 代码规范

- **MUST**: 遵循 PSR-12 编码规范
- **MUST**: 使用类型声明 `declare(strict_types=1);`
- **MUST**: 完整的 PHPDoc 注释
- **MUST**: 清晰的变量和方法命名

### 2. 测试质量

- **MUST**: 每个测试方法只测试一个功能点
- **MUST**: 测试方法独立，不依赖其他测试
- **MUST**: 测试数据完整清理，不影响其他测试
- **MUST**: 断言消息清晰，便于调试

### 3. 文档要求

- **MUST**: 测试类和方法都有清晰的注释
- **MUST**: 复杂测试逻辑有详细说明
- **MUST**: 测试用例文档保持更新

## 禁止事项

### 1. 测试文件

- **MUST NOT**: 删除测试文件，即使测试暂时失败
- **MUST NOT**: 创建临时测试脚本（如 `check_*.php`, `test_*.php`）
- **MUST NOT**: 在测试文件中硬编码配置

### 2. 测试方法

- **MUST NOT**: 在测试方法中忽略异常
- **MUST NOT**: 测试方法之间共享状态
- **MUST NOT**: 测试方法依赖执行顺序

### 3. 测试数据

- **MUST NOT**: 使用生产数据作为测试数据
- **MUST NOT**: 测试数据不清理
- **MUST NOT**: 测试数据影响其他测试

## 参考资源

- [测试用例文档](../../app/code/Weline/Marketing/doc/测试/规则名称多语言翻译测试用例.md)
- [开发规范文档](../../app/code/Weline/Marketing/doc/开发规则/LocalModel开发规范.md)
- [WelineFramework 单元测试指南](../../docs/dev/单元测试.md)

## 更新记录

- 2024-01-XX: 初始版本，定义 LocalModel 测试用例编写规则

