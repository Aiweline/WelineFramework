---
name: i18n-internationalization
description: 国际化。所有用户可见文本必须用 __()、<lang> 标签、i18n/*.csv。禁止 %1/%2，必须用 %{1}/%{name}。
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

## 必做

- 所有用户可见文本用 `__('原文')` 或 `<lang>原文</lang>`
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

## 禁止

- 硬编码用户可见文本
- 使用 `%1`、`%2` 格式（必须 `%{1}`、`%{name}`）
