## Footer 组件框架 — 返回 JSON 格式

框架已包含：品牌 Logo/描述、两列链接、社交图标、版权信息、Grid 布局。
你负责用 css_extra 增强视觉，用 html_extra_column 添加第三列链接，用 html_extra 添加附加内容（如订阅表单）。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！）",
    "html_extra_column": "额外链接列 HTML（可选）",
    "html_extra": "附加内容（可选 — 如订阅表单）",
    "footer_extra_text": "底部额外文字（可选）",
    "js_content": "交互逻辑（可选）"
}
```
