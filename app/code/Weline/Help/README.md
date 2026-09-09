# Weline_Help

帮助中心模块：拥有 `/help`（及 `/faq` 别名）命名空间、Hub FAQ/topics 内容、CMS `PageKind=help`、自有 SEO/Sitemap。

## 边界

| 层 | Owner |
|---|---|
| Hub 文案（topics/FAQ/quick links） | `HelpHubContent` |
| 帮助文章 CRUD | CMS Page + `path_group=help`；后台按网站分组 `help/backend/page/listing` |
| 可视化编辑 | Theme Editor，`layout_type=help` |
| 布局壳 | Theme `layouts/help`（数据驱动，读 HelpHubContent） |
| SEO | `HelpSeoFactsBuilder` + `HelpSeoProfileProvider` |
| 配送/退换/支付指南正文 | Shipping / Payment（仅外链） |

## CMS PageKind

`extends/module/Weline_Cms/PageKind/HelpPageKindProvider.php`：

- code / path_group: `help`
- layout_types: `help`
- public namespace: `/help`
