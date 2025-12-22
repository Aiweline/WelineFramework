# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::html-lang-end`
- **显示名称**：基础布局 HTML 语言属性结束
- **功能说明**：在渲染基础布局的 `<html>` 标签的 `lang` 属性之后触发，允许其他模块在 `lang` 属性后注入额外内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--html-lang-end.phtml`

## 使用场景

- 在 `<html>` 标签中添加额外的属性（如 `dir="rtl"`、`class="dark-mode"` 等）
- 根据语言设置添加特定属性
- 支持 RTL（从右到左）语言布局
- 添加主题模式相关的属性

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme--frontend--layouts--base--html-lang-end.phtml 文件中 -->

<!-- 示例1：为 RTL 语言添加 dir 属性 -->
<?php
$userLang = $_SERVER['WELINE_USER_LANG'] ?? Cookie::getLang() ?? 'zh_Hans_CN';
$rtlLanguages = ['ar', 'he', 'fa', 'ur'];
$langCode = explode('_', $userLang)[0];
if (in_array($langCode, $rtlLanguages)) {
    echo ' dir="rtl"';
}
?>

<!-- 示例2：添加主题模式类 -->
<?php
$themeMode = $this->getData('theme_mode') ?? 'light';
echo ' class="theme-' . htmlspecialchars($themeMode, ENT_QUOTES, 'UTF-8') . '"';
?>
```

## 返回值说明

- Hook 应该输出（echo）额外的 HTML 属性字符串
- 输出内容会直接插入到 `lang` 属性之后
- 建议使用 `htmlspecialchars()` 进行转义，确保安全性

## 执行顺序

1. `html-lang` - 在 `<html lang="">` 属性值位置执行
2. `html-lang-end` - 在 `lang` 属性之后执行

## 注意事项

- 此 hook 会在所有前端布局页面的 `<html>` 标签中执行
- Hook 输出的内容会直接插入到 HTML 中，请确保输出的是有效的 HTML 属性
- 如果不需要添加额外属性，可以不实现此 hook
- 多个模块实现此 hook 时，所有输出会按顺序连接

