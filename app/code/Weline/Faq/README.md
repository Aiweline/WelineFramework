# Weline_Faq

帮助中心模块：拥有 `/faq`（及 `/faq` 别名）命名空间、Hub FAQ/topics 内容、CMS `PageKind=faq`、自有 SEO/Sitemap。

## 边界

| 层 | Owner |
|---|---|
| Hub 文案（topics/FAQ/quick links） | `FaqHubContent` |
| 帮助文章 CRUD | CMS Page + `path_group=faq`；后台按网站分组 `faq/backend/page/listing` |
| 可视化编辑 | Theme Editor，`layout_type=faq` |
| 布局壳 | Theme `layouts/faq`（数据驱动，读 FaqHubContent） |
| SEO | `FaqSeoFactsBuilder` + `FaqSeoProfileProvider` |
| 配送/退换/支付指南正文 | Shipping / Payment（仅外链） |

## CMS PageKind

`extends/module/Weline_Cms/PageKind/FaqPageKindProvider.php`：

- code / path_group: `faq`
- layout_types: `faq`
- public namespace: `/faq`
