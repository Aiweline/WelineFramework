# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::head::module-declarations`
- **显示名称**：Head 模块声明
- **功能说明**：在 head 中 theme.js 加载之后触发，允许其他模块注入 JS 模块声明。使用 `Weline.declare()` 声明需要加载的模块。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/head/module-declarations.phtml`

## 使用场景

- 在 head 中声明需要立即加载的 JS 模块
- 在 head 中声明需要按需加载的 JS 模块
- 为模块提供统一的模块声明入口

## 示例代码

```phtml
<?php
/**
 * 模块声明 Hook
 * Hook名称：Weline_Theme::frontend::partials::head::module-declarations
 * 
 * @hook-priority 150      Hook优先级：150（composer位置默认优先级）
 * @hook-sort-order 0      Hook排序顺序：0（默认值）
 * @hook-solo false        Hook独享：false（不独占）
 */
?>
<!-- 在模块的 view/hooks/Weline_Theme/frontend/partials/head/module-declarations.phtml 文件中 -->
<script data-no-extract="true">
    // 声明搜索模块，立即加载
    if (window.Weline && window.Weline.declare) {
        Weline.declare('search', true, 'WeShop_Search::js/search.js');
    }
    
    // 声明其他模块，按需加载
    if (window.Weline && window.Weline.declare) {
        Weline.declare('myModule', false, 'My_Module::js/my-module.js');
    }
</script>
```

## Weline.declare() 方法说明

`Weline.declare()` 方法用于声明 JS 模块，语法如下：

```javascript
Weline.declare(moduleName, autoLoad, modulePath)
```

### 参数说明

- `moduleName` (string): 模块名称，用于后续通过 `Weline.load()` 加载
- `autoLoad` (boolean): 是否立即加载，`true` 表示立即加载，`false` 表示按需加载
- `modulePath` (string, 可选): 模块路径，格式为 `ModuleName::path/to/file.js`。如果不提供，将使用默认路径规则

### 模块路径格式

模块路径支持两种格式：

1. **模块路径格式**：`ModuleName::path/to/file.js`
   - 例如：`WeShop_Search::js/search.js`
   - 会被解析为：`/WeShop/Search/view/statics/js/search.js` (开发模式) 或 `/static/WeShop/Search/js/search.js` (生产模式)

2. **绝对路径**：`/absolute/path/to/file.js`
   - 直接使用提供的路径

## 执行顺序

1. Frontend 模块的 head.phtml 加载基础资源
2. Theme 模块的 head partial 加载主题资源
3. theme.js 加载并初始化
4. **`module-declarations` hook 触发** - 允许模块声明
5. 其他 head 相关 hook 执行

## 注意事项

- 此 hook 在 `theme.js` 加载之后执行，因此可以安全使用 `Weline.declare()` 方法
- 使用 `data-no-extract="true"` 标记，确保脚本不会被提取到外部文件
- 建议在声明模块前检查 `window.Weline && window.Weline.declare` 是否存在
- 模块声明应该在 head 中完成，以便在页面加载时就能使用
