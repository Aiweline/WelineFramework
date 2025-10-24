# WelineFramework 模型开发最佳实践

> 本文档总结了在开发 Weline_Ai 模块过程中学到的关键经验和最佳实践。  
> 最后更新：2025-10-12  
> 适用版本：WelineFramework 2.x+

---

## 📋 目录

1. [模型必备要素](#1-模型必备要素)
2. [表名推导机制](#2-表名推导机制)
3. [数据库API使用](#3-数据库api使用)
4. [单元测试策略](#4-单元测试策略)
5. [常见陷阱与解决方案](#5-常见陷阱与解决方案)
6. [开发检查清单](#6-开发检查清单)

---

## 1. 模型必备要素

### 1.1 完整的模型结构

一个标准的WelineFramework模型必须包含以下要素：

```php
<?php
namespace Weline\YourModule\Model;

use Weline\Framework\Database\Model;

class YourModel extends Model
{
    // ✅ 必需：字段常量定义（用于getModelData()）
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_CREATED_AT = 'created_at';
    // ... 所有数据库字段都需要常量
    
    // ✅ 必需：主键定义
    public array $_unit_primary_keys = ['id'];
    
    // ✅ 推荐：索引字段（用于排序优化）
    public array $_index_sort_keys = ['id', 'name'];
    
    // ✅ 必需：初始化方法
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 框架会自动推导表名：YourModel → your_model
    }
    
    // ✅ 必需：数据库安装方法
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('模型描述')
                ->addColumn('id', 'INTEGER', null, 'primary key auto_increment', 'ID')
                ->addColumn('name', 'VARCHAR', 255, 'not null', '名称')
                ->addColumn('created_at', 'TIMESTAMP', null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
                ->create();
        }
    }
}
```

### 1.2 为什么需要fields_*常量？

**关键发现**：`AbstractModel::getModelData()`只提取`fields_*`常量定义的字段！

```php
// AbstractModel.php 第1252-1273行
public function getModelData(string $field = ''): array|string
{
    foreach ($this->getModelFields() as $key => $val) {
        if (isset($data[$val])) {
            $this->_model_fields_data[$val] = $field_data;
        }
    }
    return $this->_model_fields_data;
}

// getModelFields() 从类常量 fields_* 中提取
```

**后果**：如果没有定义`fields_*`常量，`save()`时会报错：**"插入数据不能为空"**

### 1.3 禁止手动设置$_table

❌ **错误做法**：
```php
class YourModel extends Model
{
    protected string $_table = 'custom_table_name'; // 违反Constitution XI.A
    
    public function _init(): void
    {
        $this->useMainDbMaster();
        $this->_table = 'custom_table_name'; // 违反Constitution XI.A
    }
}
```

✅ **正确做法**：
```php
class YourModel extends Model
{
    // 框架自动推导：YourModel → your_model
    // 不需要声明 $_table
    
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 让框架自动推导表名
    }
}
```

---

## 2. 表名推导机制

### 2.1 自动推导规则

WelineFramework的表名推导规则（`AbstractModel::processTable()`，第304-329行）：

| 模型类名 | 推导后的表名 | 说明 |
|---------|------------|------|
| `AiModel` | `ai` | ⚠️ **实际**: 框架会去除 `Model` 后缀！ |
| `AiApiKey` | `ai_api_key` | 复合词自动分割 |
| `UserProfile` | `user_profile` | 标准命名 |
| `OrderItem` | `order_item` | 两个单词 |
| `Queue` | `queue` | 单个单词 |

**推导过程（实际行为）**：
1. 提取类名
2. **如果类名以 `Model` 结尾，则去除该后缀**
3. 使用`w_split_by_capital()`按大写字母分割
4. 转为小写并用下划线连接
5. 添加表前缀（如果配置了）

**⚠️ 特别注意 - AiModel 的表名**：
- ✅ **实际行为**: `AiModel` → 去除`Model`后缀 → `Ai` → `ai`
- ❌ **常见误解**: `AiModel` → `Ai` + `Model` → `ai_model`
- 🔍 **验证方法**: 查看实际数据库表名，Weline_Ai 模块使用的是 `ai` 表
- ❌ 禁止手动声明 `protected $_table = 'ai'`（违反 Constitution XI.A）
- ✅ 正确做法：让框架自动推导，保持类名与表名的一致性

**验证方法**：
```php
$model = ObjectManager::getInstance(AiModel::class);
echo $model->getTable(); // 输出: ai （框架自动去除了 Model 后缀）
```

### 2.2 验证表名

```php
// 在测试或调试时验证表名
$model = new YourModel();
$tableName = $model->getTable();  // ✅ 正确：使用getTable()
echo $tableName; // 输出：`prefix_your_model`

// ❌ 错误：getMainTable() 方法不存在
$tableName = $model->getMainTable(); // Fatal Error!
```

---

## 3. 数据库API使用

### 3.1 获取表字段信息

✅ **正确方法**：使用`Model::columns()`

```php
// Model.php 第22-31行
$model = new YourModel();
$columnsInfo = $model->columns(); // 返回 SHOW FULL COLUMNS 的结果

// 提取列名
$columnNames = array_column($columnsInfo, 'Field');

// $columnsInfo 结构示例：
// [
//     ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', ...],
//     ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', ...],
// ]
```

❌ **错误方法**：
```php
$connection = $model->getConnection();
$fields = $connection->describeTable($tableName); // ❌ 方法不存在
$fields = $connection->getTableFields($tableName); // ❌ 方法不存在
```

### 3.2 模型操作API

#### 保存数据
```php
// 新增
$model = new YourModel();
$model->setData('name', 'Test');
$model->save(); // 返回新ID

// 更新
$model->load(1);
$model->setData('name', 'Updated');
$model->save(); // 返回ID
```

#### 删除数据
```php
// ✅ 正确：必须调用fetch()执行删除
$model->load(1);
$model->delete()->fetch();

// ❌ 错误：不调用fetch()，删除不会执行
$model->delete();
```

#### 查询数据
```php
// 单条记录
$model = new YourModel();
$model->load(1); // 按主键加载

// 多条记录
$items = $model->where('status', 'active')
               ->select()
               ->fetch()
               ->getItems();

// 条件查询
$model->where('name', 'Test')
      ->where('status', 'active', '=', 'AND')
      ->select()
      ->fetch();
```

### 3.3 ConnectionFactory的限制

**重要**：WelineFramework的`ConnectionFactory`不提供直接的数据删除和索引查询方法。

#### ❌ 不存在的方法

```php
$connection = $model->getConnection();

// ❌ 错误：这些方法都不存在
$connection->delete($table, $conditions);     // Fatal Error!
$connection->getIndexList($tableName);        // Fatal Error!
$connection->describeTable($tableName);       // Fatal Error!
$connection->getTableFields($tableName);      // Fatal Error!
```

#### ✅ 正确的替代方案

**删除数据**：使用Model的`delete()`方法

```php
// ❌ 错误
protected function tearDown(): void
{
    $connection = $this->model->getConnection();
    $connection->delete(
        $this->model->getTable(),
        ['model_code LIKE ?' => '%test%']
    );
}

// ✅ 正确：使用Model方式删除
protected function tearDown(): void
{
    try {
        $testModels = ObjectManager::getInstance(YourModel::class)
            ->clearData()
            ->reset()
            ->select()
            ->fetch();
        
        if ($testModels && method_exists($testModels, 'getItems')) {
            foreach ($testModels->getItems() as $testModel) {
                $modelCode = $testModel->getData(YourModel::fields_MODEL_CODE);
                if ($modelCode && strpos($modelCode, 'test') !== false) {
                    $testModel->delete()->fetch();
                }
            }
        }
    } catch (\Exception $e) {
        // 忽略清理错误
    }
    
    parent::tearDown();
}
```

**验证唯一索引**：通过尝试插入重复数据

```php
// ❌ 错误
public function testUniqueIndexExists(): void
{
    $connection = $this->model->getConnection();
    $indexes = $connection->getIndexList($tableName); // Fatal Error!
    // ...
}

// ✅ 正确：通过实际插入重复数据验证
public function testUniqueIndexExists(): void
{
    // 创建第一个测试模型
    $model1 = ObjectManager::getInstance(YourModel::class);
    $model1->clearData()->reset();
    $model1->setData(YourModel::fields_SUPPLIER, 'test-supplier')
           ->setData(YourModel::fields_MODEL_CODE, 'test-code')
           ->setData(YourModel::fields_NAME, 'Test 1')
           ->save();
    
    $this->assertNotEmpty($model1->getId(), '第一个模型应成功创建');
    
    // 尝试创建第二个具有相同supplier+model_code的模型（应该失败）
    $model2 = ObjectManager::getInstance(YourModel::class);
    $model2->clearData()->reset();
    $model2->setData(YourModel::fields_SUPPLIER, 'test-supplier')
           ->setData(YourModel::fields_MODEL_CODE, 'test-code')
           ->setData(YourModel::fields_NAME, 'Test 2');
    
    $duplicateInsertFailed = false;
    try {
        $model2->save();
    } catch (\Exception $e) {
        $duplicateInsertFailed = true;
        $this->assertStringContainsString(
            'UNIQUE constraint failed',
            $e->getMessage(),
            '唯一索引约束应该阻止重复的supplier+model_code'
        );
    }
    
    $this->assertTrue(
        $duplicateInsertFailed,
        '应该抛出唯一索引冲突异常，证明唯一索引存在'
    );
    
    // 清理测试数据
    if ($model1->getId()) {
        $model1->delete()->fetch();
    }
}
```

**Rationale**：
1. **设计理念**：WelineFramework强调通过Model层操作数据，而非直接使用ConnectionFactory
2. **类型安全**：Model方法提供了更好的类型检查和参数验证
3. **更真实的测试**：通过实际插入数据验证索引，比查询元数据更可靠
4. **减少耦合**：不依赖底层数据库驱动的特定API

---

## 4. 单元测试策略

### 4.1 ObjectManager单例问题

**问题**：`ObjectManager::getInstance()`返回单例，导致数据污染。

**解决方案**：每次操作后重新加载

```php
// ❌ 错误：数据会被单例污染
$model1 = ObjectManager::getInstance(YourModel::class);
$model1->setData('name', 'Model1');
$model1->save();

$model2 = ObjectManager::getInstance(YourModel::class);
$model2->setData('name', 'Model2');
$model2->save();

// $model1->getData('name') 现在是 'Model2'！（单例污染）

// ✅ 正确：每次save后重新load
private function createTestModel(array $data): YourModel
{
    $model = ObjectManager::getInstance(YourModel::class);
    $model->clearData();
    $model->reset();
    
    foreach ($data as $field => $value) {
        $model->setData($field, $value);
    }
    
    $model->save();
    $id = $model->getId();
    
    // ✅ 关键：重新加载一个独立实例
    $freshModel = ObjectManager::getInstance(YourModel::class);
    $freshModel->clearData();
    $freshModel->reset();
    $freshModel->load($id);
    
    return $freshModel;
}
```

### 4.2 测试文件结构

WelineFramework的测试约定：

```
app/code/Weline/YourModule/
├── test/                      # ✅ 小写test目录
│   ├── YourModelTest.php     # 测试类名：XxxTest
│   └── YourControllerTest.php
├── Test/Unit/               # ❌ 旧版本结构（会报错）
```

**测试基类**：
```php
namespace Weline\YourModule\test;

use Weline\Framework\UnitTest\TestCore; // ✅ 继承TestCore

class YourModelTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
    }
}
```

### 4.3 测试验证模式

```php
// ✅ 验证模型创建
$model = $this->createTestModel(['name' => 'Test']);
$this->assertNotEmpty($model->getId(), '模型应被保存');
$this->assertEquals('Test', $model->getData('name'), '名称应匹配');

// ✅ 验证删除
$id = $model->getId();
$model->delete()->fetch();

$deletedModel = ObjectManager::getInstance(YourModel::class);
$deletedModel->clearData();
$deletedModel->reset();
$deletedModel->load($id);

$this->assertEmpty($deletedModel->getId(), '模型应被删除');

// ✅ 验证更新
$model->setData('name', 'Updated');
$model->save();

$reloadedModel = ObjectManager::getInstance(YourModel::class);
$reloadedModel->clearData();
$reloadedModel->reset();
$reloadedModel->load($model->getId());

$this->assertEquals('Updated', $reloadedModel->getData('name'), '应更新成功');
```

---

## 5. 常见陷阱与解决方案

### 5.1 "插入数据不能为空"

**症状**：`save()`时报错："保存数据出错! 消息: 插入数据不能为空！"

**原因**：缺少`fields_*`常量定义

**解决**：
```php
// 为所有数据库字段添加常量
public const fields_ID = 'id';
public const fields_NAME = 'name';
// ... 所有字段
```

### 5.2 "getMainTable() 方法不存在"

**症状**：`Call to undefined method getMainTable()`

**原因**：框架中没有`getMainTable()`方法

**解决**：使用`getTable()`
```php
// ❌ 错误
$tableName = $model->getMainTable();

// ✅ 正确
$tableName = $model->getTable();
```

### 5.3 删除操作不生效

**症状**：调用`delete()`后数据仍然存在

**原因**：未调用`fetch()`执行SQL

**解决**：
```php
// ❌ 错误
$model->delete();

// ✅ 正确
$model->delete()->fetch();
```

### 5.4 单元测试数据污染

**症状**：测试中创建的两个模型ID相同

**原因**：`ObjectManager`单例导致

**解决**：save后重新load（见4.1节）

---

## 6. 开发检查清单

### 6.1 新模型开发检查

- [ ] 定义了所有`fields_*`常量（对应数据库字段）
- [ ] 定义了`$_unit_primary_keys`数组
- [ ] 实现了`_init()`方法（调用`useMainDbMaster()`）
- [ ] 实现了`install()`方法（与fields_*一致）
- [ ] **没有**手动设置`$_table`或`$_id_field_name`
- [ ] 表名符合命名约定（CamelCase → snake_case）

### 6.2 单元测试检查

- [ ] 测试文件位于`test/`目录（小写）
- [ ] 继承自`Weline\Framework\UnitTest\TestCore`
- [ ] 命名空间为`Weline\YourModule\test`
- [ ] 使用`clearData() + reset() + load()`避免单例污染
- [ ] 删除操作调用了`.fetch()`
- [ ] `tearDown()`中清理了测试数据

### 6.3 代码审查检查

- [ ] 参考了成熟模块（如`Weline_Queue`）的实现
- [ ] 使用了正确的数据库API（`columns()`而非`describeTable()`）
- [ ] 所有字段常量与`install()`方法一致
- [ ] 没有在`install()`外部操作`$setup->dropTable()`

---

## 7. 参考资源

### 7.1 框架源码参考

| 文件 | 关键内容 |
|-----|---------|
| `AbstractModel.php` | `processTable()`（304行）、`getModelData()`（1252行）、`save()`（601行） |
| `Model.php` | `columns()`（22行）、查询方法 |
| `functions.php` | `w_split_by_capital()`（116行） |

### 7.2 示例模块

| 模块 | 学习要点 |
|-----|---------|
| `Weline_Queue` | 标准模型结构、字段定义 |
| `Weline_Ai` | 复杂字段、单元测试 |

### 7.3 相关文档

- [Constitution v2.13.2 - 项目开发规范](../.specify/memory/constitution.md)
- [常见问题修复指南](./常见问题修复指南.md)
- [AI模块商用准备计划](../specs/002-ai-module-production-ready/plan.md)

---

## 8. 版本历史

| 版本 | 日期 | 更新内容 |
|-----|------|---------|
| 1.0.0 | 2025-10-12 | 初始版本，记录Weline_Ai模块开发经验 |

---

**文档维护者**：AI Assistant  
**反馈渠道**：通过Issue或Pull Request提交改进建议

