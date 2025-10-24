# DataTable 属性继承功能说明

## 概述

DataTable 模块现在支持属性继承功能，允许子标签（如 `t-header` 和 `t-filter`）自动从父标签 `d-table` 继承属性，简化了标签的使用。

## 核心组件

### 1. TableContext 助手类

`Weline\DataTable\Helper\TableContext` 是属性继承的核心助手类，提供以下功能：

- **上下文管理**：存储和检索表格上下文
- **属性继承**：自动从父表格继承属性
- **属性验证**：验证必需的属性
- **上下文清理**：清理过期的上下文

### 2. 主要方法

```php
// 设置表格上下文
TableContext::setTableContext(string $scope, array $attributes): void

// 获取表格上下文
TableContext::getTableContext(string $scope): ?array

// 继承表格属性
TableContext::inheritTableAttributes(array $attributes, string $childScope, array $inheritKeys = []): array

// 验证必需属性
TableContext::validateRequiredAttributes(array $attributes, array $requiredKeys, string $tagName): void

// 清理上下文
TableContext::clearTableContext(string $scope): void
```

## 使用示例

### 基础用法（自动继承）

```html
<!-- 父表格标签 -->
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table" editable="true" searchable="true" sortable="true">
    
    <!-- 子标签自动继承父标签的 model 和 scope -->
    <w:t-header>
        <w:field name="id" sortable="true">ID</w:field>
        <w:field name="name" sortable="true">名称</w:field>
        <w:field name="status" sortable="true">状态</w:field>
    </w:t-header>
    
    <w:t-filter>
        <w:field name="name" type="text" placeholder="搜索名称"></w:field>
        <w:field name="status" type="select" options="1:启用,0:禁用"></w:field>
    </w:t-filter>
    
</w:d-table>
```

在这个例子中：
- `t-header` 会自动继承 `model="Weline\Demo\Model\Demo"` 和 `scope="demo-table-header"`
- `t-filter` 会自动继承 `model="Weline\Demo\Model\Demo"` 和 `scope="demo-table-filter"`
- 子标签还会继承相关的功能属性（如 `searchable`、`sortable` 等）

### 高级用法（部分覆盖）

```html
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table" searchable="true">
    
    <!-- 覆盖继承的 sortable 属性 -->
    <w:t-header sortable="false">
        <w:field name="id">ID</w:field>
        <w:field name="name">名称</w:field>
    </w:t-header>
    
    <!-- 手动指定 scope，但仍然继承 model -->
    <w:t-filter scope="custom-filter-scope">
        <w:field name="name" type="text" placeholder="搜索名称"></w:field>
    </w:t-filter>
    
</w:d-table>
```

### 独立使用（手动指定所有属性）

```html
<!-- 不在 d-table 内部使用时，需要手动指定所有必需属性 -->
<w:t-header model="Weline\Demo\Model\Demo" scope="standalone-header" sortable="true">
    <w:field name="id" sortable="true">ID</w:field>
    <w:field name="name" sortable="true">名称</w:field>
</w:t-header>

<w:t-filter model="Weline\Demo\Model\Demo" scope="standalone-filter" searchable="true">
    <w:field name="name" type="text" placeholder="搜索名称"></w:field>
</w:t-filter>
```

## 继承规则

### 1. 自动继承的属性

默认情况下，以下属性会自动继承：

- `model`：数据模型类名
- `scope`：数据作用域（会自动添加后缀）
- `searchable`：是否启用搜索
- `sortable`：是否启用排序
- `editable`：是否启用编辑

### 2. Scope 生成规则

- `t-header` 的 scope：`{table-scope}-header`
- `t-filter` 的 scope：`{table-scope}-filter`
- 其他子标签的 scope：`{table-scope}-child`

### 3. 属性覆盖规则

- 子标签手动指定的属性会覆盖继承的属性
- 如果子标签没有指定某个属性，则使用继承的值
- 如果父标签也没有指定某个属性，则使用默认值

## 技术实现

### 1. 上下文存储

表格上下文存储在静态数组中，以 scope 为键：

```php
private static array $tableContexts = [];
```

### 2. 继承逻辑

```php
public static function inheritTableAttributes(array $attributes, string $childScope, array $inheritKeys = []): array
{
    $parentContext = self::findParentTableContext($childScope);
    
    if (!$parentContext) {
        return $attributes;
    }

    // 合并默认继承键和自定义继承键
    $defaultInheritKeys = ['model', 'scope', 'searchable', 'sortable', 'editable'];
    $inheritKeys = array_merge($defaultInheritKeys, $inheritKeys);

    foreach ($inheritKeys as $key) {
        if (!isset($attributes[$key]) && isset($parentContext[$key])) {
            $attributes[$key] = $parentContext[$key];
        }
    }

    // 特殊处理 scope
    if (empty($attributes['scope']) && !empty($parentContext['scope'])) {
        $attributes['scope'] = $parentContext['scope'] . '-' . self::getChildScopeSuffix($childScope);
    }

    return $attributes;
}
```

### 3. 父上下文查找

```php
public static function findParentTableContext(string $childScope): ?array
{
    foreach (self::$tableContexts as $tableScope => $tableContext) {
        if (str_starts_with($childScope, $tableScope) || empty($childScope)) {
            return $tableContext;
        }
    }
    return null;
}
```

## 错误处理

### 1. 必需属性验证

如果子标签无法获取必需的属性（如 `model` 或 `scope`），会抛出异常：

```php
TableContext::validateRequiredAttributes(
    ['model' => $model, 'scope' => $scope], 
    ['model', 'scope'], 
    'TableHeader'
);
```

### 2. 常见错误

- **缺少 model 属性**：`TableHeader标签必须指定model属性或位于d-table标签内！`
- **缺少 scope 属性**：`TableHeader标签必须指定scope属性或位于d-table标签内！`

## 最佳实践

### 1. 推荐用法

```html
<!-- 推荐：在 d-table 内部使用子标签，享受自动继承 -->
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table">
    <w:t-header>
        <!-- 自动继承 model 和 scope -->
    </w:t-header>
    <w:t-filter>
        <!-- 自动继承 model 和 scope -->
    </w:t-filter>
</w:d-table>
```

### 2. 避免的用法

```html
<!-- 不推荐：子标签独立使用需要手动指定所有属性 -->
<w:t-header model="Weline\Demo\Model\Demo" scope="demo-header">
    <!-- 需要手动指定所有属性 -->
</w:t-header>
```

### 3. 性能考虑

- 上下文存储在内存中，避免在大量表格中使用相同的 scope
- 定期清理不再使用的上下文
- 使用有意义的 scope 名称，便于调试和维护

## 测试

运行测试来验证属性继承功能：

```bash
php bin/m test Weline\DataTable\test\TableInheritanceTest
```

测试覆盖了以下场景：
- 上下文设置和获取
- 属性继承功能
- TableHeader 属性继承
- TableFilter 属性继承
- 必需属性验证
- 上下文清理

## 总结

属性继承功能大大简化了 DataTable 标签的使用，特别是：

1. **减少重复代码**：子标签无需重复指定相同的属性
2. **提高可维护性**：修改父标签属性会自动影响子标签
3. **增强灵活性**：子标签可以覆盖继承的属性
4. **保持向后兼容**：独立使用的标签仍然可以手动指定所有属性

这个功能使得 DataTable 组件更加易用和强大。 