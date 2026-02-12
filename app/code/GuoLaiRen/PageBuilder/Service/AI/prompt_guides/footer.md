## Footer 组件框架 — 返回 JSON 格式

框架已包含：品牌 Logo/描述、两列链接、社交图标、版权信息、Grid 布局。框架类名为 **ai-footer-***（如 ai-footer-brand、ai-footer-social、ai-footer-links、ai-footer-column、ai-footer-bottom）。
你负责用 css_extra 增强视觉，用 html_extra_column 添加第三列链接，用 html_extra 添加附加内容（如订阅表单）。**HTML、CSS、JS 中必须使用与框架相同的类名**（ai-footer-*），禁止 invent 与现有 HTML 不一致的类（如 .pb-footer-social-icons 当框架只有 .ai-footer-social）。

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
