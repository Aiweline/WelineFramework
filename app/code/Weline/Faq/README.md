# Weline_Faq

万能 FAQ：`/faq` 命名空间、Hub、实体问答、CMS `PageKind=faq`、商品 FAQ 部件、搜索与 SEO。

## 边界

| 层 | Owner |
|---|---|
| Hub 文案（topics/FAQ/quick links） | `FaqHubContent`（种子同步到 `type_code=site`） |
| 实体问答 CRUD | `FaqItem` + `FaqService`；后台 `faq/backend/item/listing` |
| 帮助文章 CRUD | CMS Page + `path_group=faq`；后台 `faq/backend/page/listing` |
| 可视化编辑 | Theme Editor，`layout_type=faq` |
| 布局壳 | Theme `layouts/faq`；商品页仅空槽 `product-faq` |
| SEO | Hub `FaqSeoProfileProvider`；商品 `ProductFaqSeoProfileProvider` |
| 搜索 | `FaqSearchProvider`（site + product 条目 + CMS faq；跳过 template） |
| PDP 解析 | `FaqPdpResolveService` + SystemConfig `faq/pdp/*` + 模板 pack 种子 |

## CMS PageKind

`extends/module/Weline_Cms/PageKind/FaqPageKindProvider.php`：

- code / path_group: `faq`
- layout_types: `faq`
- public namespace: `/faq`

## 版本

`1.0.0` — 万能 FAQ（SPI / 存储 / 后台 / 部件 / 搜索 / 商品 SEO）
