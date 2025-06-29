# Taglib 依赖管理功能说明

## 概述

Taglib 模块现在支持标签依赖管理功能，允许子标签通过 `parent()` 方法指定依赖的父标签，确保父标签在子标签之前被渲染。

## 核心功能

### 1. 依赖检测
- 自动检测标签类中的 `parent()` 方法
- 建立标签之间的依赖关系
- 支持多层依赖链

### 2. 依赖排序
- 使用拓扑排序算法对标签进行排序
- 确保父标签在子标签之前渲染
- 检测并防止循环依赖

### 3. 依赖验证
- 验证依赖的父标签是否存在
- 检查依赖关系的完整性
- 提供详细的错误和警告信息

## 实现原理

### 1. TagParser 更新

`Weline\Taglib\Observer\TagParser` 类已经更新，添加了以下功能：

```php
// 检查是否有parent()方法
if (method_exists($tagObject, 'parent')) {
    $parentTag = $tagObject::parent();
    if ($parentTag) {
        $tag_data['parent'] = $parentTag;
    }
}

// 对标签进行依赖排序
$sortedTags = $this->sortTagsByDependencies($module_tags);
```

### 2. 拓扑排序算法

使用深度优先搜索（DFS）实现拓扑排序：

```php
private function topologicalSort(
    string $tagName, 
    array $allTags, 
    array &$visited, 
    array &$recursionStack, 
    array &$sorted
): void {
    // 检测循环依赖
    if (isset($recursionStack[$tagName])) {
        $cycle = implode(' -> ', array_keys($recursionStack)) . ' -> ' . $tagName;
        throw new \Exception(__('检测到标签循环依赖：%{1}', [$cycle]));
    }

    // 如果有依赖的父标签，先处理父标签
    if (isset($allTags[$tagName]['parent'])) {
        $parentTag = $allTags[$tagName]['parent'];
        if (isset($allTags[$parentTag])) {
            $this->topologicalSort($parentTag, $allTags, $visited, $recursionStack, $sorted);
        }
    }

    // 添加到排序结果
    $sorted[] = $tagName;
}
```

## 使用方法

### 1. 在标签类中添加 parent() 方法

```php
class ChildTag implements TaglibInterface
{
    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return 'parent-tag';
    }

    // ... 其他方法
}
```

### 2. 支持多个父标签

```php
class MultiParentTag implements TaglibInterface
{
    /**
     * 指定多个父标签，用于依赖管理
     * @return string|null 父标签名称（用逗号分隔）
     */
    public static function parent(): ?string
    {
        return 'parent-tag1,parent-tag2,parent-tag3';
    }

    // ... 其他方法
}
```

### 3. 实际示例

#### DataTable 标签依赖示例

```php
// TableHeader.php
class TableHeader implements TaglibInterface
{
    public static function parent(): ?string
    {
        return 'd-table';
    }
    
    // ... 其他方法
}

// TableFilter.php
class TableFilter implements TaglibInterface
{
    public static function parent(): ?string
    {
        return 'd-table';
    }
    
    // ... 其他方法
}

// Field.php - 支持多个父标签
class Field implements TaglibInterface
{
    public static function parent(): ?string
    {
        return 't-header,t-filter'; // 依赖于t-header或t-filter标签
    }
    
    // ... 其他方法
}
```

#### 复杂依赖链示例

```php
// GrandChildTag.php
class GrandChildTag implements TaglibInterface
{
    public static function parent(): ?string
    {
        return 'child-tag';
    }
}

// ChildTag.php
class ChildTag implements TaglibInterface
{
    public static function parent(): ?string
    {
        return 'parent-tag';
    }
}

// ParentTag.php
class ParentTag implements TaglibInterface
{
    // 没有parent()方法，表示没有依赖
}
```

## 依赖关系示例

### 1. 简单依赖

```
child-tag -> parent-tag
```

渲染顺序：`parent-tag` → `child-tag`

### 2. 复杂依赖链

```
grandchild-tag -> child-tag -> parent-tag
```

渲染顺序：`parent-tag` → `child-tag` → `grandchild-tag`

### 3. 混合依赖

```
child-1 -> parent-1
child-2 -> parent-2
independent-tag (无依赖)
```

渲染顺序：`parent-1`, `parent-2`, `independent-tag` → `child-1`, `child-2`

### 4. 多父标签依赖

```
field -> t-header, t-filter
t-header -> d-table
t-filter -> d-table
```

渲染顺序：`d-table` → `t-header`, `t-filter` → `field`

### 5. 复杂多父标签依赖

```
child-tag -> parent-1, parent-2, parent-3
parent-1 -> grandparent
parent-2 -> grandparent
parent-3 -> grandparent
```

渲染顺序：`grandparent` → `parent-1`, `parent-2`, `parent-3` → `child-tag`

## 错误处理

### 1. 循环依赖检测

如果检测到循环依赖，会抛出异常：

```php
// 示例：tag-a -> tag-b -> tag-c -> tag-a
throw new \Exception('检测到标签循环依赖：tag-a -> tag-b -> tag-c -> tag-a');
```

### 2. 缺失父标签

如果依赖的父标签不存在，会产生错误：

```php
// 错误信息
"标签 'child-tag' 依赖的父标签 'non-existent-parent' 不存在"
```

### 3. 依赖验证

系统会验证依赖关系的完整性：

```php
private function validateDependencies(array $allTags): array
{
    $errors = [];
    $warnings = [];

    foreach ($allTags as $childTag => $tagData) {
        if (isset($tagData['parent']) && !isset($allTags[$tagData['parent']])) {
            $errors[] = __("标签 '%{1}' 依赖的父标签 '%{2}' 不存在", [$childTag, $tagData['parent']]);
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}
```

## 最佳实践

### 1. 依赖设计原则

- **单一依赖**：每个标签只依赖一个父标签
- **避免循环**：确保依赖关系不形成循环
- **合理层次**：避免过深的依赖链（建议不超过3层）

### 2. 命名规范

- 使用有意义的标签名称
- 保持依赖关系清晰
- 避免使用过于相似的名称

### 3. 性能考虑

- 依赖排序在标签解析阶段进行，不影响运行时性能
- 缓存机制确保依赖关系只计算一次
- 避免在 `parent()` 方法中进行复杂计算

## 测试

### 运行依赖管理测试

```bash
php bin/m test Weline\Taglib\test\TagDependencyTest
```

### 测试覆盖场景

1. **基本依赖排序**：验证父标签在子标签之前
2. **循环依赖检测**：确保能检测并报告循环依赖
3. **依赖验证**：验证依赖的父标签存在性
4. **复杂依赖链**：测试多层依赖关系
5. **无依赖标签**：确保独立标签正常工作
6. **混合依赖场景**：测试多种依赖关系混合的情况

## 示例测试用例

```php
public function testDependencySorting()
{
    $tags = [
        'child-tag' => ['parent' => 'parent-tag'],
        'parent-tag' => [],
        'independent-tag' => []
    ];

    $sortedTags = $this->sortTagsByDependencies($tags);
    $tagNames = array_keys($sortedTags);
    
    $parentIndex = array_search('parent-tag', $tagNames);
    $childIndex = array_search('child-tag', $tagNames);
    
    $this->assertLessThan($childIndex, $parentIndex);
}
```

## 总结

标签依赖管理功能提供了以下优势：

1. **自动排序**：确保标签按正确的依赖顺序渲染
2. **循环检测**：防止循环依赖导致的无限循环
3. **错误处理**：提供清晰的错误信息和验证
4. **向后兼容**：不影响现有的无依赖标签
5. **性能优化**：在解析阶段处理，不影响运行时性能

这个功能使得复杂的标签系统更加可靠和易于维护。 