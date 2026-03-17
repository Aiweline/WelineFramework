---
name: i18n-internationalization
description: 国际化。用户可见文本用 __()、<lang>、@lang、i18n/*.csv。taglib/自定义标签属性必须用 @lang() 或 @lang{}。禁止 %1/%2。
globs:
  - "**/i18n/**/*.csv"
  - "**/*.phtml"
alwaysApply: false
---

# i18n-internationalization（极简版）

## 何时使用

- 创建/修改任何用户可见文本
- 翻译、多语言、国际化
- 按钮、标签、错误消息、菜单项
- **taglib 属性、自定义标签属性**（如 file-manager 的 title、placeholder）

## 必做

- 普通 PHP/HTML：用 `__('原文')` 或 `<lang>原文</lang>`
- **taglib/自定义标签属性**：必须用 `@lang(原文)` 或 `@lang{原文}`，**禁止** `<?= __('...') ?>`、`<?= htmlspecialchars(...) ?>` 等任何 PHP（会触发 unexpected identifier / ParseError，标签解析器无法正确处理属性内嵌 PHP）
- 翻译文件放 `i18n/zh_Hans_CN.csv`、`i18n/en_US.csv`
- 占位符用 `%{1}`、`%{name}` 带花括号，禁止 `%1`、`%2`

## 最小示例

```php
echo __('用户管理');
echo __('欢迎 %{name}!', ['name' => $username]);
```

```html
<lang>保存</lang>
```

```html
<!-- 标签属性：用 @lang，编译期预展开，无嵌套引号 -->
<file-manager title='@lang(选择深色Logo)' value='<?= $logoVal ?>'/>
<input placeholder='@lang{请输入}'/>
```

## 禁止

- 硬编码用户可见文本
- 标签属性中用 `<?= __('...') ?>`（改用 @lang）
- 使用 `%1`、`%2` 格式（必须 `%{1}`、`%{name}`）
