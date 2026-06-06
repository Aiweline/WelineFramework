## Content 组件框架 — 返回 JSON 格式

框架已包含：标题/副标题/描述头部、背景色、容器布局。

**类名格式**：框架使用 `$componentId` 作为唯一前缀（如 `content-abc12345`），所有类名格式为 `.content-abc12345-header`、`.content-abc12345-body` 等。在你的输出中，使用 `$cls` 变量构建类名（如 `<?= $cls ?>-card`）。

**语言要求**：根据当前页面语言生成所有文本！中文页面用中文，英文页面用英文。

你负责用 html_content 实现核心内容（卡片、FAQ、画廊等），用 css_extra 写样式。

返回一个 JSON 对象，字段结构如下；不要输出 markdown 代码围栏：

{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "CSS 样式（必填）— 选择器格式：#<?= $componentId ?> .<?= $cls ?>-xxx",
    "css_responsive": "移动端样式（可选）",
    "html_content": "核心内容 HTML（必填！— 放在 .<?= $cls ?>-body 内）— class 格式：<?= $cls ?>-xxx",
    "js_content": "交互逻辑（可选）— 使用 component 变量"
}
