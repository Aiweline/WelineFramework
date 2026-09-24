# Spec — DaoCharms 店面全站审图与 Filters 上线阻断

## 背景

用户要求：工程团队接入，对 DaoCharms 店面**所有关键页**做审图（UI + 原型 + 主题），不合适的必须调整；产品列表页可见「Filters 部件未接入」类占位，无法上线。

## 范围

- Website：`158`（路径前缀 `/daocharms/`）
- 主题：`daocharms`（`work_mode=design_theme`）
- 阻断：`/daocharms/products` 侧栏 `list-filters` 不得再显示「筛选器区域 - 由 Filters 部件默认注入」占位；须渲染 `Weline_Filters::category-filters`
- 审图页（最低集）：`/`、`/products`、`/shop`、`/sanctuary`、`/lounge`、`/journal`、`/about`、`/contact`、`/faq`、政策页抽样、PDP 抽样

## 非目标

- 不改生产；本机验收
- 不混汉服站内容/affiliate

## UC（草案，对齐冻结会钉死）

### UC-filters-1

Given Website 158 与 daocharms 主题已发布  
When 打开 `/daocharms/products`  
Then 侧栏可见可交互 Filters（部门/价格/属性视数据），HTML 含 `data-widget-code="category-filters"`，**不含** `data-placeholder="list-filters"` 开发占位文案

### UC-shentu-1

Given 关键页已打开（禁缓存 Browser）  
When UI ∥ 原型对照 frontend-design / prototype / 主题 Token 审图  
Then E/F/G fail 项已改到 pass；禁止只点评

## 框架映射

- Filters：`default_injections` → `products`/`list-filters`（跨模块 JSON；禁止布局内嵌外国 widget）
- 硬规则：`required_default_always_present_without_user_deleted`（无 `user_deleted@*` 时必装须固化进布局）
- 权威：`app/code/Weline/Theme/doc/布局固化与默认注入.md`
