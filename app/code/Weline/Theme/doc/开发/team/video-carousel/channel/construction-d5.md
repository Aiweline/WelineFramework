# channel: construction-d5

## msg-1 | 2026-09-21T22:18:00+08:00 | from:后端·Product/查询 | to:* | thread:construction-d5 | kind:handoff
agent_id: construction-d5-backend-product
body:
D5 交付：`StorefrontProductWidgetCatalog::cardsByIds(array $productIds, int $limit = 12): array`。
- 内部复用 `publishedOffersForProductIds` + `mapOffer` + `withReviewAggregates`
- 保持调用方 ID 顺序；过滤无效/未发布；**不**强制 HF-* 丢品
- 未改 Theme 部件；未 silently 改 featured-products 历史忽略 `product_ids` 行为
- 合同测：`StorefrontProductWidgetCatalogCardsByIdsTest`
result=closed
---
