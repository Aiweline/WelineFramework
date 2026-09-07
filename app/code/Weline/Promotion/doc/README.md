# Weline Promotion

## Current Scope

- Backend `promotion/backend/promotion` connects marketing rules, products, orders, checkout sessions, customer service, and promotion storefront routes into one operational desk.
- Frontend `/promotion` and scoped `/promotion/{slug}` pages render current-scope, published and sellable Product offers through `StorefrontCatalogViewService`; when Product is unavailable they show an honest empty state and never fabricate acceptance products or prices.
- Activity product cards deep-link with `StorefrontOfferDetailQuery` (public axis codes or `offer` uuid) so PDP selects the same offer that priced the card.
- Active theme deals stay in force until the theme is cancelled. Storefront unit prices (cards / PDP / cart snapshot) go through Product `StorefrontOfferPriceAssembler`; Promotion registers `PromotionThemeDealPriceAdjustmentProvider` with campaign label/URL. `PromotionStorefrontActiveDealResolver` remains the theme-deal resolver used by that provider.
- Product cards on `/promotion/{slug}` show strikethrough compare-at plus `campaign_label` next to the deal price.
- Built-in promotion copy ships storefront dictionaries for `zh_Hans_CN`, `en_US`, and `ar_SA`（含活动首页 Hero lede / 匹配商品等可见串）；LocalModel resolution prefers the specific `lang_local`, while Theme supplies the document direction for RTL locales.
- The default storefront visual follows the Hanfu theme's warm-paper, ink-text and cinnabar-action system and reuses a real first-party Hanfu hero asset.
- Theme header「今日特价」必须指向路由 `promotion/deals` only（禁止顶级别名 `/deals`）；前台链接经 `Url::getFrontendUrl` / `@url{'promotion/deals'}` 生成，禁止硬编码 `/promotion…` 拼接。
- 活动页二级 Tab / 入口卡片 / campaign / 后台 storefront 预览统一经 `PromotionActivityThemeService::storefrontUrl()` → `getFrontendUrl('promotion'[/slug])`，以保留语言/货币前缀与站点挂载。
- `PromotionCampaignRun` persists campaign_key, status (`continue` / `pause` / `repair` / `review`), handoff_json, operator_id, updated_at.
- `PromotionAdminQueryProvider` exposes `deskSnapshot`, `listRuns`, and `saveRun` for backend browser APIs.
- Hook contract: `page-before` / `page-after` around the promotion product list.

## Boundaries

- Discount math and checkout totals are owned by `Weline_Marketing` (`DiscountQuote` / automatic rules).
- Activity themes configure deal discount and call Marketing `ExternalDealDiscountProviderInterface` to upsert an automatic rule (source=`promotion_activity_theme`); Promotion must not write `Marketing\Model\Rule\Rule` directly.
- Storefront shows deal price derived from the same theme discount config; add-to-cart / quote must use the synced Marketing rule (matched product lines), not invented fake list prices.

## 活动主题多语言（LocalModel + `<local>`）

- 模型：`PromotionActivityThemeLocal`（继承 `Weline\I18n\LocalModel`），字段：`nav_label`、`page_title`、`hero_lede`、`entry_title`、`entry_subtitle`、`entry_action_label`。
- 后台表单：`templates/backend/promotion/theme/form.phtml` 通过 partial `partials/local-field-label.phtml` 在可翻译字段旁渲染 I18n `<local>` 标签（与 EAV 同款抽屉 + AI 翻译，走 `i18n_admin` → `taglib-local-load` / `taglib-local-ai`）。
- 新建主题需先保存生成 `id` 后才会显示「多语言」入口；已保存记录点击即可打开抽屉，使用本地 AI 翻译模型补全其它语言。
- 样式：`view/statics/css/promotion-theme-form.css`（触发器固定显示「多语言」文案）。

## 活动主题范围（website / store / channel）

- **一站一活动**：`PromotionActivityTheme.website_id` 必须绑定具体站点；**`0` 表示「默认网站」**（真实站点，可有商品），**不是**「全站广播」。
- 店铺 / 渠道：空表示该 Website 下该层全部；配置渠道时必须同时选择店铺。
- 前台：`PromotionScopeResolver` 解析访客 scope；`PromotionActivityThemeScopeMatcher` 要求主题 Website 与访客 Website 精确匹配，再按 store/channel 收窄；同 slug 更具体范围优先。
- 选品（手动 / 单商品 / 条件筛选）均只在活动绑定的 Website 内搜索与预览。
- 后台列表：`promotion/backend/theme/index` 仍可用 Website 筛选查看；新建/编辑表单必须选定 Website。
- 无匹配主题的 `/promotion/{slug}` 返回 404，禁止硬编码 fallback 文案。

## 活动主题保存与 `w_changed`（硬规则）

- 提到「changed / 保存后立即生效 / 清缓存 / CDN / SEO」时，**必须**使用 `w_changed(ResourceChange v1)`，见 `doc/event/resource_changed.md` 与 Framework `doc/event/framework/resource_changed.md`。
- 生产者：`PromotionActivityThemeResourceChangePublisher`（`resource_type=promotion_activity_theme`），在 `saveTheme` 写事务内发布；`impact.urls` 含 `/promotion`、`/promotion/{slug}`。
- **本地**：`afterCommit` 调 `PromotionStorefrontCacheInvalidator` 清进程 FPC、`fpc`/`router` 池与 WLS 共享态，保证前台立刻生效。
- **CDN / SEO**：交给 `Weline_Framework::resource_changed` 接收方，禁止在控制器内直接 purge CDN。

```bash
php bin/w setup:upgrade
php vendor/bin/phpunit app/code/Weline/Promotion/Test/Unit/Query/PromotionAdminQueryProviderContractTest.php
php bin/w http:request /promotion
```
