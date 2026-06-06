## Footer 组件框架 — 返回 JSON 格式

框架已包含：品牌 Logo/描述、两列链接、社交图标、版权信息、Grid 布局。

**类名格式**：框架使用 `$componentId` 作为唯一前缀（如 `footer-abc12345`），所有类名格式为 `.footer-abc12345-brand`、`.footer-abc12345-links` 等。在你的输出中，使用 `$cls` 变量构建类名（如 `<?= $cls ?>-column`）。

**语言要求**：根据当前页面语言生成所有文本！中文页面用中文，英文页面用英文。

你负责用 css_extra 增强视觉，用 html_extra_column 添加第三列链接，用 html_extra 添加附加内容（如订阅表单）。

返回一个 JSON 对象，字段结构如下；不要输出 markdown 代码围栏：

{
    "extra_fields": "额外配置字段（可选）",
    "php_variables": "额外 PHP 变量（可选）",
    "css_extra": "增强样式（必填！）— 选择器格式：#<?= $componentId ?> .<?= $cls ?>-xxx",
    "html_extra_column": "额外链接列 HTML（可选）— class 格式：<?= $cls ?>-column",
    "html_extra": "附加内容（可选 — 如订阅表单）",
    "footer_extra_text": "底部额外文字（可选）— 必须使用当前页面语言",
    "js_content": "交互逻辑（可选）— 使用 component.querySelector('[class*=\"-xxx\"]') 选择元素"
}
