# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::homepage::html-lang-end`
- **显示名称**：首页布局 HTML 语言属性结束
- **功能说明**：在渲染首页布局的 `<html>` 标签的 `lang` 属性之后触发，允许其他模块在 `lang` 属性后注入额外内容。此 hook 仅适用于首页布局。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--homepage--html-lang-end.phtml`

## 使用场景

- 在首页的 `<html>` 标签中添加额外的属性（如 `dir="rtl"`、`class="homepage-theme"` 等）
- 根据首页特定需求添加属性
- 支持首页特定的主题模式
- 添加首页专用的 CSS 类或数据属性

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--homepage--html-lang-end.phtml 文件中 -->

<!-- 示例1：为首页添加特定的类 -->
<?php
echo ' class="homepage-layout"';
?>

<!-- 示例2：添加首页主题模式 -->
<?php
$homepageTheme = $this->getData('homepage_theme') ?? 'default';
echo ' data-theme="' . htmlspecialchars($homepageTheme, ENT_QUOTES, 'UTF-8') . '"';
?>
```

## 返回值说明

- Hook 应该输出（echo）额外的 HTML 属性字符串
- 输出内容会直接插入到 `lang` 属性之后
- 建议使用 `htmlspecialchars()` 进行转义，确保安全性

## 执行顺序

1. `base::html-lang` - 在 `<html lang="">` 属性值位置执行（所有布局）
2. `homepage::html-lang-end` - 在 `lang` 属性之后执行（仅首页布局）

## 注意事项

- 此 hook 仅在首页布局页面的 `<html>` 标签中执行
- Hook 输出的内容会直接插入到 HTML 中，请确保输出的是有效的 HTML 属性
- 如果不需要添加额外属性，可以不实现此 hook
- 多个模块实现此 hook 时，所有输出会按顺序连接
- 此 hook 在 `base::html-lang-end` 之后执行（如果存在）

