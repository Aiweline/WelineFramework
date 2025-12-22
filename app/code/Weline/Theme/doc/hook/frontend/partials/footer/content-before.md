# Weline Theme 模块 - Hook 文档

## 概述

本文档详细说明了 Weline Theme 模块提供的 `Weline_Theme::frontend::partials::footer::content-before` Hook 及其使用方法。该 Hook 允许其他模块在页脚主要内容渲染之前注入内容。

## Hook 信息

### 基本信息

- **Hook 名称**：`Weline_Theme::frontend::partials::footer::content-before`
- **显示名称**：页脚内容之前
- **Hook 类型**：标准格式 Hook
- **定义模块**：Weline_Theme
- **区域（Area）**：frontend
- **类型（Type）**：partials
- **组件（Component）**：footer
- **位置（Position）**：content-before

### 功能说明

在渲染页脚主要内容之前触发，允许其他模块在页脚内容开始处注入内容。

### 触发时机

该 Hook 在以下时机触发：
- **触发位置**：主题模板文件中，页脚主要内容区域之前
- **触发条件**：当主题渲染页脚主要内容之前自动触发

## 使用方法

### 基本用法

在模块的 `view/hooks/` 目录下创建对应的模板文件：

对于标准格式的 Hook，文件名格式为：`ModuleName--area--type--component--position.phtml`

例如，对于 Hook `Weline_Theme::frontend::partials::footer::content-before`，文件名应为：
```
view/hooks/Weline_Theme--frontend--partials--footer--content-before.phtml
```

### 模板文件示例

```phtml
<!-- view/hooks/Weline_Theme--frontend--partials--footer--content-before.phtml -->
<div class="custom-footer-content-before">
    <p>这是通过 Hook 注入的自定义内容</p>
</div>
```

### 使用场景

该 Hook 适用于以下场景：
- 在页脚内容开始处注入自定义 HTML 内容
- 添加页脚顶部横幅或通知
- 扩展页脚功能
- 自定义页脚布局

## 注意事项

1. **文件位置**：Hook 模板文件必须放在模块的 `view/hooks/` 目录下
2. **文件命名**：文件名必须严格按照规范命名，区分大小写
3. **内容输出**：模板文件中的内容会直接输出到页面，请确保输出的是有效的 HTML
4. **性能考虑**：Hook 在每次页面渲染时都会执行，应避免执行耗时操作
5. **依赖关系**：如果 Hook 内容依赖其他资源（CSS、JS），请确保这些资源已正确加载

## 相关文件

- **Hook 规约文件**：`app/code/Weline/Theme/hook.php`
- **Hook 接口定义**：`app/code/Weline/Framework/Hook/HookInterface.php`
- **主题文档**：`app/code/Weline/Theme/doc/README.md`

## 扩展开发

如果需要扩展该 Hook 的功能，可以：

1. **创建新的 Hook 模板文件**：在模块的 `view/hooks/` 目录下创建对应的模板文件
2. **使用条件判断**：在模板中使用 PHP 代码进行条件判断，控制内容的显示
3. **加载资源**：在模板中加载所需的 CSS 和 JavaScript 资源

## 更新日志

- **初始版本**：添加 `Weline_Theme::frontend::partials::footer::content-before` Hook 文档

## 相关资源

- [Weline Framework Hook 系统文档](../../../../../Framework/doc/hook/README.md)
- [主题开发指南](../../README.md)
- [Partials 配置系统使用指南](../../Partials配置系统使用指南.md)
