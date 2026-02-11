## Header 组件框架 — 返回 JSON 格式

框架已包含：Logo 区域、导航链接循环、CTA 按钮、汉堡菜单、Flex 布局、基础颜色。
你负责用 css_extra 增强视觉（渐变背景、hover 动画、阴影、滚动效果），用 js_content 实现交互（滚动固定、菜单展开动画）。

```json
{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！— 让 header 看起来专业美观）",
    "html_extra": "额外装饰 HTML（可选 — 禁止输出导航或 Logo）",
    "js_content": "交互逻辑（可选 — 滚动固定、移动端菜单等）"
}
```
