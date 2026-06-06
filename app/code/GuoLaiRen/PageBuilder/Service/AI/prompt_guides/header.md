## Header 组件框架 — 返回 JSON 格式

框架已包含：Logo 区域、导航链接循环、CTA 按钮、汉堡菜单、Flex 布局、基础颜色。

**类名格式**：框架使用 `$componentId` 作为唯一前缀（如 `header-abc12345`），所有类名格式为 `.header-abc12345-logo`、`.header-abc12345-nav` 等。在你的输出中，使用 `$cls` 变量构建类名（如 `<?= $cls ?>-cta`）。

**语言要求**：根据当前页面语言生成所有文本！中文页面用中文，英文页面用英文。

你负责用 css_extra 增强视觉（渐变背景、hover 动画、阴影、滚动效果），用 js_content 实现交互（滚动固定、菜单展开动画）。

返回一个 JSON 对象，字段结构如下；不要输出 markdown 代码围栏：

{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！）— 选择器格式：#<?= $componentId ?> .<?= $cls ?>-xxx",
    "html_extra": "额外装饰 HTML（可选 — 禁止输出导航或 Logo）",
    "js_content": "交互逻辑（可选 — 滚动固定、移动端菜单等）— 使用 component 变量"
}
