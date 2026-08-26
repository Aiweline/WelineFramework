# Hook: Weline_Theme::frontend::layouts::product-list::filters-sidebar

## 说明

在商品列表页（`product_list`）左侧渲染筛选侧栏。必须运行时 `getHook`，禁止在布局编译期写死 widget 输出。

## 位置

`product_list/default.phtml` 的 `list-filters` 槽内。

## 类型

Slot Hook — 允许业务模块替换筛选内容。

## 实现模块

由 `Weline_Product` 提供：根分类导航 + 价格筛选。

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `storefront_offers_unfiltered` | list | 未按价格过滤的报价（用于计数） |
| `storefront_offers` | list | 当前可见报价（回退） |
