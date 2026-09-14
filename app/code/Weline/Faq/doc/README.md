# Weline_Faq

## 模块定位

万能 FAQ 模块：拥有 `/faq` 命名空间、站点 Hub 文案、实体问答表（`weline_faq_item`）、CMS `PageKind=faq`、商品详情 FAQ 部件（站点/店铺/渠道默认 + 商品专属）、搜索索引与 SEO FAQPage 事实。

## 能力一览

| 能力 | 入口 |
|------|------|
| 类型 SPI | `FaqTypeProviderInterface` / `FaqTypeRegistry`（内置 product + site + template） |
| 存储 | `Model/FaqItem`（含 `store_code`/`channel_code`/`faq_key`）→ `weline_faq_item` |
| 解析 | `FaqPdpResolveService`（PDP/SEO 唯一读路径） |
| 配置 | SystemConfig `faq-pdp`：`merge_enabled` / `active_pack` |
| 模板种子 | `FaqTemplatePacks` + `FaqTemplateSeedService`（retail/cross_border/virtual/b2b） |
| 服务 | `FaqService`（list/save/delete/seoFaqs/seoFaqsForPdp） |
| 后台 | `faq/backend/item/*`；`faq/backend/page/*` |
| 店面部件 | `product-faq` → Theme 空槽 + 来源标记 |
| 搜索 | `FaqSearchProvider`；**不索引** template 行 |
| SEO | Hub：`FaqSeoProfileProvider`；商品：`ProductFaqSeoProfileProvider`→`seoFaqsForPdp` |

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。
