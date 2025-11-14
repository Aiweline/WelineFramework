# Weline_Sticker 模块扩展文档

## 概述

Weline_Sticker 模块提供了非侵入式修改其他模块文件的能力，允许其他模块在不直接修改源代码的情况下，通过 Sticker 规则来扩展和定制目标模块的功能。本文档详细说明如何使用 Sticker 扩展点。

## 快速开始

### 创建模块级 Sticker

1. 在您的模块中创建扩展目录：`extends/module/Weline_Sticker/`
2. 创建与目标模块文件路径相同的 Sticker 规则文件
3. 使用 `w:sticker` 标签定义修改规则

### 创建主题级 Sticker

1. 在您的主题中创建扩展目录：`extends/theme/{主题名}/Weline_Sticker/`
2. 按照相同的路径结构创建 Sticker 规则文件

## 详细说明

### Sticker 扩展点

**路径**: `extends/module/Weline_Sticker` 和 `extends/theme/{主题名}/Weline_Sticker`

**支持类型**: `module`（模块级）和 `theme`（主题级）

**用途**: 非侵入式修改其他模块的模板文件、配置文件等

**要求**:
- 文件路径必须与目标文件路径完全一致
- 使用 `w:sticker` 标签定义修改规则
- 遵循 Sticker 规则语法

### 模块级 Sticker

**路径格式**: `extends/module/Weline_Sticker/{目标模块名}/{目标文件路径}`

**示例**:
- 目标文件: `Weline/Demo/view/templates/Backend/index.phtml`
- Sticker 文件: `extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml`

**适用场景**: 
- 直接修改特定模块的特定文件
- 适用于模块定制和功能扩展

### 主题级 Sticker

**路径格式**: `extends/theme/{主题名}/Weline_Sticker/{目标模块名}/{目标文件路径}`

**示例**:
- 目标文件: `Weline/Demo/view/templates/Backend/index.phtml`
- Sticker 文件: `extends/theme/default/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml`

**适用场景**:
- 基于主题的文件覆盖
- 主题定制和样式调整

## 规则语法

### 基本结构

```html
<w:sticker action="replace|before|after" position="all|1|2-3">
    <w:sticker:target>
        目标代码（需要修改的原始代码）
    </w:sticker:target>
    <w:sticker:code>
        修改后的代码（新代码或插入的代码）
    </w:sticker:code>
</w:sticker>
```

### 属性说明

- **action**: 操作类型
  - `replace`: 替换目标代码
  - `before`: 在目标代码前插入
  - `after`: 在目标代码后追加

- **position**: 位置参数
  - `all`: 匹配所有位置
  - `N`: 只匹配第 N 个位置
  - `N-M`: 匹配第 N 到 M 个位置

### 完整示例

```html
<!-- 替换所有匹配的标题 -->
<w:sticker action="replace" position="all">
    <w:sticker:target>
        <h1>原始标题</h1>
    </w:sticker:target>
    <w:sticker:code>
        <h1 class="custom-title">自定义标题</h1>
    </w:sticker:code>
</w:sticker>

<!-- 在第2个按钮前插入内容 -->
<w:sticker action="before" position="2">
    <w:sticker:target>
        <button class="btn btn-primary">按钮文字</button>
    </w:sticker:target>
    <w:sticker:code>
        <div class="inserted-content">插入的内容</div>
    </w:sticker:code>
</w:sticker>

<!-- 在第1到第3个段落后追加内容 -->
<w:sticker action="after" position="1-3">
    <w:sticker:target>
        <p>段落内容</p>
    </w:sticker:target>
    <w:sticker:code>
        <p class="appended">追加的内容</p>
    </w:sticker:code>
</w:sticker>
```

## 高级用法

### 多规则组合

一个 Sticker 文件中可以包含多个 `<w:sticker>` 标签，可以同时进行多种修改：

```html
<!-- 修改标题 -->
<w:sticker action="replace" position="1">
    <w:sticker:target>
        <h1>默认标题</h1>
    </w:sticker:target>
    <w:sticker:code>
        <h1>个性化标题</h1>
    </w:sticker:code>
</w:sticker>

<!-- 添加自定义样式 -->
<w:sticker action="after" position="1">
    <w:sticker:target>
        <head>
    </w:sticker:target>
    <w:sticker:code>
        <head>
            <style>
                .custom-style { color: #ff0000; }
            </style>
    </w:sticker:code>
</w:sticker>

<!-- 在页面底部添加脚本 -->
<w:sticker action="before" position="1">
    <w:sticker:target>
        </body>
    </w:sticker:target>
    <w:sticker:code>
            <script>
                console.log('页面加载完成');
            </script>
    </w:sticker:code>
</w:sticker>
```

### 复杂位置选择

使用范围选择可以精确定位需要修改的位置：

```html
<!-- 只修改第2到第4个列表项 -->
<w:sticker action="replace" position="2-4">
    <w:sticker:target>
        <li>原始列表项</li>
    </w:sticker:target>
    <w:sticker:code>
        <li class="modified">修改后的列表项</li>
    </w:sticker:code>
</w:sticker>
```

## 最佳实践

### 1. 文件路径规范

- 始终使用相对路径，从模块根目录开始
- 保持与目标文件完全一致的目录结构
- 使用正斜杠 `/` 作为路径分隔符

### 2. 代码匹配原则

- 目标代码应该足够具体，避免误匹配
- 考虑代码的上下文，确保唯一性
- 避免匹配过于通用或常见的代码片段

### 3. 错误处理

- 检查目标代码是否存在
- 验证修改是否生效
- 关注编译日志中的警告和错误

### 4. 性能优化

- 避免在频繁访问的文件上使用大量 Sticker
- 合并多个相关的小修改为一个文件
- 定期清理不再使用的 Sticker 规则

## 常见问题

### Q: 如何知道我的 Sticker 规则是否生效？

A: 系统会在 `generated/extends/module/Weline_Sticker/` 目录下生成编译后的文件，您可以在该目录中查看修改结果。

### Q: Sticker 规则冲突如何处理？

A: 系统会自动检测冲突，如果两个模块尝试修改同一段代码的相同位置，会在编译时报告冲突。

### Q: 可以修改任何类型的文件吗？

A: 主要支持文本文件，如 PHP、HTML、CSS、JS 等。对于二进制文件，修改可能会导致文件损坏。

### Q: 开发环境和生产环境有什么区别？

A: 
- 开发环境：自动检测文件变化并重新编译
- 生产环境：需要手动运行 `sticker:refresh` 命令来更新编译文件

## 示例代码

### 完整的模块级 Sticker 示例

假设我们要修改 `Weline_Demo` 模块的首页模板：

```html
<!-- 文件: extends/module/Weline_Sticker/Weline/Demo/view/templates/Frontend/index.phtml -->

<!-- 修改页面标题 -->
<w:sticker action="replace" position="1">
    <w:sticker:target>
        <title>Demo 首页</title>
    </w:sticker:target>
    <w:sticker:code>
        <title>我的自定义 Demo 首页</title>
    </w:sticker:code>
</w:sticker>

<!-- 添加自定义 CSS -->
<w:sticker action="after" position="1">
    <w:sticker:target>
        <head>
    </w:sticker:target>
    <w:sticker:code>
        <head>
            <link rel="stylesheet" href="/custom.css">
    </w:sticker:code>
</w:sticker>

<!-- 在主要内容前添加横幅 -->
<w:sticker action="before" position="1">
    <w:sticker:target>
        <main class="content">
    </w:sticker:target>
    <w:sticker:code>
        <div class="banner">
            <h2>欢迎来到我的网站！</h2>
        </div>
        <main class="content">
    </w:sticker:code>
</w:sticker>
```

## 注册表信息

系统会在 `generated/extends.php` 文件中记录所有扩展信息，您可以通过以下方式查看：

1. **命令行方式**: 使用 `extends:scan` 命令扫描
2. **管理界面**: 在后台管理中查看扩展管理
3. **直接查看**: 检查 `generated/extends.php` 文件

## 相关文档

- [Weline_Sticker 完整文档](README.md)
- [使用指南](doc/usage.md)
- [开发文档](doc/development.md)
- [需求文档](doc/requirements.md)

## 支持和贡献

如有问题或建议，请通过以下方式联系：

- 邮箱: aiweline@qq.com
- 论坛: https://bbs.aiweline.com
- 文档: 查看模块的 doc/ 目录
