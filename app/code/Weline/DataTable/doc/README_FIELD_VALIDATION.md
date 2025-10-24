# DataTable 字段验证功能

## 概述

DataTable模块现在支持字段验证功能，当指定的field字段在model中找不到时会自动报错，避免运行时错误。

## 功能特性

- **自动字段验证**：在渲染field标签时自动验证字段是否在model中存在
- **友好的错误信息**：提供详细的错误信息，包括可用字段列表
- **多种model获取方式**：支持从父标签、TableContext等多种方式获取model信息
- **完整的测试覆盖**：包含完整的单元测试用例

## 使用方法

### 基本用法

```html
<!-- 正确的用法：字段存在于model中 -->
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <w:field name="id" sortable="true">ID</w:field>
        <w:field name="name" sortable="true">名称</w:field>
        <w:field name="email" sortable="true">邮箱</w:field>
    </w:t-header>
</w:d-table>
```

### 错误示例

```html
<!-- 错误的用法：字段不存在于model中 -->
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <w:field name="non_existent_field" sortable="true">不存在的字段</w:field>
    </w:t-header>
</w:d-table>
```

这将抛出异常：
```
字段"non_existent_field"在model"Weline\Demo\Model\Demo"中不存在！可用字段：id, name, email, created_at, updated_at
```

## 错误处理

### 1. 字段不存在错误

当指定的字段在model中不存在时，会抛出`Weline\Framework\App\Exception`异常：

```php
try {
    // 渲染包含不存在字段的标签
    $result = $fieldTag->render();
} catch (\Weline\Framework\App\Exception $e) {
    // 处理字段不存在错误
    echo $e->getMessage();
}
```

### 2. Model类名无法确定错误

当无法确定model类名时，会抛出异常：

```
无法确定model类名，请确保field标签位于d-table标签内或指定了model属性！
```

### 3. Model实例化错误

当model实例化失败时，会抛出异常：

```
验证字段时发生错误：[具体错误信息]
```

## 支持的Model获取方式

### 1. 从父标签获取

```html
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <!-- 自动继承父标签的model -->
        <w:field name="id">ID</w:field>
    </w:t-header>
</w:d-table>
```

### 2. 从TableContext获取

```html
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header scope="custom-header">
        <!-- 从TableContext获取model -->
        <w:field name="name">名称</w:field>
    </w:t-header>
</w:d-table>
```

### 3. 直接指定model

```html
<w:t-header model="Weline\Demo\Model\Demo" scope="custom-header">
    <!-- 直接指定model -->
    <w:field name="email">邮箱</w:field>
</w:t-header>
```

## 测试

运行测试用例：

```bash
# 运行所有DataTable测试
php bin/phpunit app/code/Weline/DataTable/test/

# 运行字段验证测试
php bin/phpunit app/code/Weline/DataTable/test/FieldValidationTest.php
```

### 测试用例说明

1. **testFieldExistsValidation()** - 测试字段存在的情况
2. **testFieldNotExistsValidation()** - 测试字段不存在的情况
3. **testFieldValidationWithTableContext()** - 测试从TableContext获取model
4. **testFieldValidationWithoutModel()** - 测试无法确定model类名
5. **testFieldCallbackWithValidation()** - 测试完整的回调函数
6. **testFieldCallbackWithInvalidField()** - 测试回调函数抛出异常

## 最佳实践

### 1. 确保Model字段定义正确

```php
<?php

namespace Weline\Demo\Model;

use Weline\Framework\Database\Model;

class Demo extends Model
{
    // 定义字段常量
    const fields_ID = 'id';
    const fields_NAME = 'name';
    const fields_EMAIL = 'email';
    const fields_CREATE_TIME = 'created_at';
    const fields_UPDATE_TIME = 'updated_at';
}
```

### 2. 使用IDE自动补全

在IDE中定义字段常量可以获得更好的代码提示和错误检查。

### 3. 定期运行测试

定期运行测试用例确保字段验证功能正常工作。

## 注意事项

1. **性能影响**：字段验证会增加少量性能开销，但可以避免运行时错误
2. **缓存机制**：Model字段信息会被缓存，提高验证性能
3. **错误信息**：错误信息包含可用字段列表，便于调试
4. **向后兼容**：此功能不会影响现有的正常使用

## 更新日志

- **v1.0.0** - 初始版本，添加字段验证功能
- 支持自动字段验证
- 支持多种model获取方式
- 提供友好的错误信息
- 包含完整的测试用例 