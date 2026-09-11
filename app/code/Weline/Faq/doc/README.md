# Weline_Faq

## 模块定位

万能 FAQ 模块：拥有 `/faq` 命名空间、站点 Hub 文案、实体问答表（`weline_faq_item`）、CMS `PageKind=faq`、商品详情 FAQ 部件、搜索索引与 SEO FAQPage 事实。

## 能力一览

| 能力 | 入口 |
|------|------|
| 类型 SPI | `FaqTypeProviderInterface` / `FaqTypeRegistry`（内置 product + site） |
| 存储 | `Model/FaqItem` → `weline_faq_item` |
| 服务 | `FaqService`（list/save/delete/seoFaqs）提供 `FaqSeoFactsInterface` |
| 后台 | `faq/backend/item/*` 条目 CRUD；`faq/backend/page/*` CMS 文章 |
| 店面部件 | `product-faq` → Theme `product-faq` 空槽 + `default_injections` |
| 搜索 | `FaqSearchProvider` code=`faq` + 保存增量索引 |
| SEO | Hub：`FaqSeoProfileProvider`；商品：`ProductFaqSeoProfileProvider` |

## 知识维护约定

- 长期事实写入本模块 `doc/`。
- 不在本文复制全局规则或客户端规则。
- 无法由当前证据确认的行为必须标记待确认。
