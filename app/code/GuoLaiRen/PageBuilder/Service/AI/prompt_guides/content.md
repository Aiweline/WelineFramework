## Content 组件框架 — 返回 JSON 格式

框架已包含：标题/副标题/描述头部、背景色、容器布局。
你负责用 html_content 实现核心内容（卡片、FAQ、画廊等），用 css_extra 写样式。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "CSS 样式（必填）",
    "css_responsive": "移动端样式（可选）",
    "html_content": "核心内容 HTML（必填！— 放在 .ai-content-body 内）",
    "js_content": "交互逻辑（可选）"
}
```
