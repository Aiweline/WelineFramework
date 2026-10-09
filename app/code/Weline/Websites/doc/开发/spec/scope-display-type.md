# 范围展示类型（Scope Display Type）

## 背景

网站 / 店铺 / 渠道可标注展示身份（如 `b2b`）。类型只驱动店面产品可见性过滤，与拆单、Cart/Order `toc/tob` 正交。配置继承：channel ← store ← website（空=继承）。

## EARS

- WHEN 模块实现 `ScopeDisplayTypeProviderInterface` 并放入 `extends/module/Weline_Websites/ScopeDisplayType/`，系统 SHALL 在后台「展示类型」下拉中收集其 code/label。
- WHEN Website/Store/Channel 的 `display_type` 为空，系统 SHALL 继承上级；网站层空 SHALL 视为无特殊类型。
- WHEN 生效类型未知（未注册），系统 SHALL fail-soft 为无特殊类型。
- WHEN 请求 Scope 冻结完成，系统 SHALL 将生效 `display_type` 写入 RequestContext 私有键；SHALL NOT 扩展 `scopeMetadata()` 公开投影。
- WHEN SalesChannel 配置了完整入口 URL，系统 SHALL 在 Store 锁定后对该 URL 做最长路径匹配；无命中 SHALL 回落 default 渠。
- WHEN L2 路径缓存命中非 default 渠，系统 SHALL 在渠仍 enabled/effective 且父店 active 时复用；SHALL NOT 要求 isDefault。
- WHEN 站内仅有唯一 default 店 + 唯一 default 渠，前台 `scope-switcher` SHALL 不渲染。
- WHEN 生效类型为 `b2b` 且 `catalog_b2b_products_only` 为真（**默认开启**；仅显式关闭可退出），Product 店面公开出口 SHALL 仅保留批发资格商品；PDP 不合格 SHALL 为空/404。
- WHEN `__channel` 与解析结果冲突，系统 SHALL 继续 409（断言不变）。

## 用例

| ID | 场景 | 期望 |
|----|------|------|
| UC-1 | 仅 default 店渠 | 切换器隐藏 |
| UC-2 | 切店/渠入口 URL | 冻结身份变为目标店渠 |
| UC-3 | b2b + only | 货架过滤；PDP 无资格为空 |
| UC-4 | 未知 display_type | fail-soft 无过滤 |
| UC-5 | `__channel` 冲突 | 409 |

## 归属

- owning_module=`Weline_Websites`（类型 SPI、字段、Resolver、切换器、渠 URL）
- owning_module=`Weline_Product`（可见性过滤 SPI + ViewService 挂点）
- owning_module=`Weline_B2B`（注册 `b2b`、配置、Eligibility 过滤实现）

## not_to_do

- 不改 Cart/Order Registry / tob cookie / OfferRouting
- 不污染 StoreSummary / SalesChannelSummary v1
- 不扩展 `scopeMetadata()`（本需求）
- 不复用 Website.`scope` 列名
