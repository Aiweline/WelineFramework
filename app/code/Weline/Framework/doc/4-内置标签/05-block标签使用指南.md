# block 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `block` 标签的使用方法。`block` 标签用于在模板中引入和渲染 Block 组件。

## 什么是 block 标签

`block` 标签是 WelineFramework 提供的 Block 组件标签，用于在模板中引入和渲染 Block 组件。Block 是框架的组件系统，允许开发者创建可复用的页面组件。

## 为什么需要 block 标签

在模板中使用 `block` 标签提供了以下优势：

- **组件化**：将页面拆分为可复用的组件
- **模块化**：每个 Block 可以独立开发和维护
- **可扩展性**：支持 Block 的替换和扩展
- **代码复用**：Block 可以在多个页面中复用

## 语法格式

`block` 标签支持以下三种语法格式：

### 1. `<block>` 标签格式

```html
<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>
<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml" vars="item|pageSize|page"/>
```

### 2. `@block()` 格式

```html
@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)
@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300)
```

### 3. `@block{}` 格式

```html
@block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}
@block{Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300}
```

## 使用方法

### 基本用法

最简单的用法是指定 Block 类和模板：

```html
<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>
```

### 使用 @block() 格式

```html
@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)
```

**语法说明**：
- 使用 `|` 分隔 Block 类和模板路径
- 第一个参数是 Block 类的完整命名空间路径
- 第二个参数是模板路径（模块名::路径）

### 传递变量

使用 `vars` 属性传递变量到 Block：

```html
<block class="Weline\Admin\Block\Demo" 
       template="Weline_Admin::block/demo.phtml" 
       vars="item|pageSize|page"/>
```

**说明**：
- `vars` 属性使用 `|` 分隔变量名
- 这些变量会从当前模板作用域传递到 Block

### 设置缓存

使用 `cache` 属性设置 Block 缓存时间（秒）：

```html
<block class="Weline\Admin\Block\Demo" 
       template="Weline_Admin::block/demo.phtml" 
       cache="300"/>
```

### 使用 @block() 格式传递参数

```html
@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300)
```

**语法说明**：
- 使用 `|` 分隔参数
- 参数格式：`参数名=参数值`
- 支持的参数：`template`、`cache`、`vars` 等

## 属性说明

### class 属性（必填）

指定 Block 类的完整命名空间路径：

```html
<block class="Weline\Admin\Block\Demo" template="..."/>
```

### template 属性（可选）

指定 Block 使用的模板路径：

```html
<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>
```

**模板路径格式**：
- `模块名::路径`：如 `Weline_Admin::block/demo.phtml`
- 相对于模块 view 目录的路径

### vars 属性（可选）

指定要传递到 Block 的变量名，使用 `|` 分隔：

```html
<block class="..." template="..." vars="item|pageSize|page"/>
```

### cache 属性（可选）

设置 Block 缓存时间（秒）：

```html
<block class="..." template="..." cache="300"/>
```

## 完整示例

### 示例 1：基本 Block

```html
<div class="page">
    <header>
        <block class="Weline\Frontend\Block\Header" template="Weline_Frontend::blocks/header.phtml"/>
    </header>
    
    <main>
        <block class="Weline\Frontend\Block\Content" template="Weline_Frontend::blocks/content.phtml"/>
    </main>
    
    <footer>
        <block class="Weline\Frontend\Block\Footer" template="Weline_Frontend::blocks/footer.phtml"/>
    </footer>
</div>
```

### 示例 2：传递变量

```html
<div class="product-list">
    <block class="Weline\Product\Block\List" 
           template="Weline_Product::blocks/list.phtml" 
           vars="products|pageSize|currentPage"/>
</div>
```

### 示例 3：使用 @block() 格式

```html
<div class="sidebar">
    @block(Weline\Admin\Block\Sidebar|Weline_Admin::blocks/sidebar.phtml)
</div>

<div class="content">
    @block(Weline\Admin\Block\Content|template=Weline_Admin::blocks/content.phtml|cache=600)
</div>
```

### 示例 4：条件加载 Block

```html
<if condition="$showSidebar">
    <div class="sidebar">
        <block class="Weline\Admin\Block\Sidebar" template="Weline_Admin::blocks/sidebar.phtml"/>
    </div>
</if>
```

## Block 类定义

Block 类需要继承 `\Weline\Framework\View\Block`：

```php
<?php
namespace Weline\Admin\Block;

use Weline\Framework\View\Block;

class Demo extends Block
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }
    
    public function getData()
    {
        // Block 逻辑
        return $this->getData('items');
    }
}
```

## 注意事项

### 1. class 属性必填

`<block>` 标签必须提供 `class` 属性：

```html
<!-- 正确 -->
<block class="Weline\Admin\Block\Demo" template="..."/>

<!-- 错误：缺少 class 属性 -->
<block template="..."/>
```

### 2. 模板路径格式

模板路径使用 `模块名::路径` 格式：

```html
<!-- 正确 -->
<block class="..." template="Weline_Admin::block/demo.phtml"/>

<!-- 错误：缺少模块名 -->
<block class="..." template="block/demo.phtml"/>
```

### 3. 变量传递

通过 `vars` 属性传递的变量必须存在于当前模板作用域：

```html
<!-- 如果 $item 不存在，Block 中无法访问 -->
<block class="..." template="..." vars="item"/>
```

### 4. 缓存机制

- `cache` 属性设置缓存时间（秒）
- 缓存基于 Block 类和参数生成唯一键
- 缓存可以提高性能，但需要注意缓存失效

## 常见问题

### Q1: Block 未找到？

**A**: 检查以下几点：
1. 确保 Block 类路径正确
2. 确保 Block 类已正确加载
3. 检查 Block 类是否继承 `\Weline\Framework\View\Block`

### Q2: 模板未找到？

**A**: 检查以下几点：
1. 确保模板路径格式正确（`模块名::路径`）
2. 确保模板文件存在
3. 检查模板文件权限

### Q3: 变量未传递？

**A**: 检查以下几点：
1. 确保变量已通过 `assign()` 传递到模板
2. 检查 `vars` 属性中的变量名是否正确
3. 确保变量存在于当前模板作用域

## 相关文档

- [Block 标签以及其他框架标签简介](../2-快速开始/07-block标签以及其他框架标签简介.md)

