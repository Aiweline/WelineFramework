# 组件 Meta 信息格式方案总结

## 方案概述

本方案为 Theme 模块中的 phtml 组件文件提供了一套标准化的 Meta 信息格式规范，支持：

1. ✅ **参数类型定义**：明确每个参数的数据类型
2. ✅ **默认值配置**：支持字面量和 PHP 变量表达式作为默认值
3. ✅ **必填标记**：明确标记必填参数
4. ✅ **自动解析工具**：提供 `ComponentMetaParser` 类用于解析 Meta 信息
5. ✅ **代码生成**：支持根据 Meta 信息自动生成参数获取代码

## 核心文件

### 1. 格式规范文档
- **文件**：`app/code/Weline/Theme/doc/组件Meta信息格式规范.md`
- **内容**：详细的格式规范、示例和最佳实践

### 2. 解析工具类
- **文件**：`app/code/Weline/Theme/Helper/ComponentMetaParser.php`
- **功能**：
  - 解析组件 Meta 信息
  - 生成参数默认值代码
  - 验证参数类型
  - 生成文档

### 3. 使用示例文档
- **文件**：`app/code/Weline/Theme/doc/组件Meta信息使用示例.md`
- **内容**：详细的使用示例和实际应用场景

### 4. 格式示例文件
- **文件**：`app/code/Weline/Theme/doc/组件Meta信息格式示例.phtml`
- **内容**：完整的组件示例，展示新格式的使用

## 格式对比

### 旧格式

```php
/**
 * 组件：Pagination
 * 
 * 分页组件，用于数据分页导航
 * 字段
 * @info description 描述：分页组件，用于数据分页导航
 * @info name 名称：Pagination分页组件
 * @param currentPage 当前页码（必填）
 * @param totalPages 总页数（必填）
 * @param baseUrl 基础URL（必填）
 * @param pageParam 页码参数名
 */
```

### 新格式

```php
/**
 * 组件：Pagination
 * 
 * 分页组件，用于数据分页导航
 * 
 * @component Pagination
 * @description 分页组件，用于数据分页导航
 * 
 * @param int currentPage 当前页码 [required] [default=(int)($this->getRequest()->getParam('page') ?: 1)]
 * @param int totalPages 总页数 [required] [default=1]
 * @param string baseUrl 基础URL [required] [default=$this->getRequest()->getUriString()]
 * @param string pageParam 页码参数名 [default='page']
 */
```

## 主要特性

### 1. 类型定义

支持以下类型：
- `string`：字符串
- `int`：整数
- `float`：浮点数
- `bool`：布尔值
- `array`：数组
- `object`：对象
- `callable`：可调用
- `mixed`：混合类型

### 2. 默认值支持

#### 字面量默认值
```php
@param string type 按钮类型 [default='primary']
@param int maxVisible 最大可见页码数 [default=5]
@param bool showFirstLast 是否显示首页/末页 [default=true]
@param array data 数据数组 [default=[]]
```

#### PHP 变量表达式默认值
```php
@param string baseUrl 基础URL [default=$this->getRequest()->getUriString()]
@param int currentPage 当前页码 [default=(int)($this->getRequest()->getParam('page') ?: 1)]
@param string icon 图标类名 [default=$type === 'success' ? 'fa-check-circle' : 'fa-info-circle']
```

### 3. 必填标记

```php
@param int currentPage 当前页码 [required] [default=1]
@param string message 提示信息 [required]
```

## 使用流程

### 步骤 1：定义组件 Meta 信息

在组件文件头部按照新格式定义 Meta 信息：

```php
<?php
/**
 * 组件：Alert
 * 
 * 提示信息组件，支持多种类型和样式
 * 
 * @component Alert
 * @description 提示信息组件，支持多种类型和样式
 * 
 * @param string type 类型 (success/error/warning/info) [default='info']
 * @param string message 提示信息 [required]
 * @param string title 标题 [default='']
 * @param bool dismissible 是否可关闭 [default=false]
 */
```

### 步骤 2：使用解析工具

```php
use Weline\Theme\Helper\ComponentMetaParser;

$meta = ComponentMetaParser::parse($filePath);
```

### 步骤 3：生成代码或文档

```php
// 生成参数获取代码
$codes = ComponentMetaParser::generateAllDefaultValueCodes($meta['params']);

// 生成文档
$doc = ComponentMetaParser::formatAsDocumentation($meta);
```

## 应用场景

1. **组件配置表单生成**：根据 Meta 信息自动生成配置表单
2. **代码生成工具**：自动生成组件参数获取代码
3. **文档自动生成**：根据 Meta 信息生成组件文档
4. **参数验证**：验证传入的参数是否符合类型要求
5. **IDE 支持**：为 IDE 提供自动补全和类型提示

## 优势

1. **标准化**：统一的格式规范，便于维护和理解
2. **类型安全**：明确的类型定义，减少错误
3. **灵活性**：支持 PHP 变量表达式，满足复杂场景
4. **自动化**：提供工具类，支持自动解析和代码生成
5. **可扩展**：易于扩展新的功能和特性

## 迁移建议

1. **逐步迁移**：不需要一次性迁移所有组件，可以逐步进行
2. **保持兼容**：新格式与旧格式可以共存，不影响现有功能
3. **工具辅助**：可以使用工具类帮助生成新格式的代码
4. **文档同步**：迁移时同步更新相关文档

## 后续计划

1. **IDE 插件**：开发 IDE 插件，提供 Meta 信息编辑和验证
2. **代码生成器**：创建命令行工具，自动生成组件代码
3. **测试工具**：开发组件参数测试工具
4. **缓存优化**：添加解析结果缓存，提升性能

## 相关文档

- [组件Meta信息格式规范.md](./组件Meta信息格式规范.md)
- [组件Meta信息使用示例.md](./组件Meta信息使用示例.md)
- [组件Meta信息格式示例.phtml](./组件Meta信息格式示例.phtml)

## 技术支持

如有问题或建议，请参考相关文档或联系开发团队。

