# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::html-lang`
- **显示名称**：基础布局 HTML 语言属性
- **功能说明**：在渲染基础布局的 `<html>` 标签的 `lang` 属性时触发，允许其他模块自定义语言代码。此 hook 适用于所有使用基础布局的页面。默认返回当前语言代码。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--html-lang.phtml`

## 使用场景

- 自定义 HTML lang 属性值
- 根据用户语言设置动态返回语言代码
- 支持多语言网站的 SEO 优化
- 语言代码格式转换（如 `zh_Hans_CN` → `zh-Hans-CN`）

## 示例代码

```php
<?php
/**
 * Theme模块 - HTML语言属性Hook
 * 
 * 返回当前HTML语言代码，用于<html lang="">标签
 */
use Weline\Framework\Http\Cookie;

// 获取当前用户语言
$userLang = $_SERVER['WELINE_USER_LANG'] ?? Cookie::getLang() ?? 'zh_Hans_CN';

// 转换为HTML lang格式（将下划线替换为连字符）
$htmlLang = str_replace('_', '-', $userLang);

// 输出HTML语言代码
echo htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8');
```

## 返回值说明

- Hook 应该直接输出（echo）语言代码字符串
- 语言代码格式应为 HTML lang 标准格式（如 `zh-CN`、`en-US` 等）
- 建议使用 `htmlspecialchars()` 进行转义，确保安全性

## 执行顺序

1. `html-lang` - 在 `<html lang="">` 属性值位置执行
2. `html-lang-end` - 在 `lang` 属性之后执行（如果存在）

## 注意事项

- 此 hook 会在所有前端布局页面的 `<html>` 标签中执行
- Hook 应该返回有效的 HTML lang 属性值
- 如果 hook 没有实现，系统会使用默认值 `zh-Hans-CN`
- 语言代码应该符合 BCP 47 标准（如 `zh-CN`、`en-US`、`ja-JP` 等）

