# Cart（P2E-001 / P2E-002 / REQ-009）

## 契约

- SPI：`CartItemSnapshotProviderInterface::getProviderCode()` + `resolveCartItemSnapshot(OfferIdentity, ScopeIdentity, selection)`
- Registry：`CartItemSnapshotProviderRegistry` — provider code O(1)；重复 code → `cart_provider_code_duplicate`
- 正式 SPI：`CartItemSnapshotProviderInterface`；无独立 V1 Registry
- selection hash（仅服务端权威）：
  `sha256(global_offer_uuid + "\\n" + selection_schema_version + "\\n" + canonical_sorted_json)`
- 客户端伪造 hash → `cart_selection_hash_mismatch`；非法 selection → `cart_selection_invalid`
- 跨模块统一使用 `Api/CartSelectionHash`；`Service/CartSelectionHash` 保留为
  Cart 内部实现，Product 不直接依赖 Cart Service
- 跨 Scope / 跨币种不合并；同 Scope guest→customer 合车并按可售上限截断
- 前台 `customer_id` 不是身份凭据：`add/add/mergeGuest/getCart` 只使用
  `CartCurrentCustomerResolver` 从公开
  `CustomerAccountFacadeInterface::current()` 得到的服务端登录身份
- Query 与登录合车 Observer 的 flat
  `website_code/store_code/channel_code/store_mode`，以及 Query 的完整
  `scope`，统一由公开 `CartScopeResolverInterface` 解析，默认实现仍为
  `CartScopeResolver`；`channel_code` 缺少
  `store_code` 时 fail-fast
- 未显式传 Scope 的站内调用继承 `RequestContext::scopeIdentity()`；
  Checkout 因而与当前 Channel Cart 使用同一可信 Scope。显式 Scope
  只有在当前请求尚无可信 Scope，或与已冻结 Scope 完全一致时才接受；
  Website/Store/Channel 任一不一致都返回 `cart_scope_request_conflict`，
  不能用客户端参数跨 Host 串车

## 售卖类型 `cart_type`（ToC/ToB 一期合同）

- **CommerceCartType SPI + Registry**：Cart 内置 `toc`；**不**依赖 `Weline_B2B`。B2B 启用后向本 Registry 注册 `tob`（须同时向 Order Registry 注册；单边禁止）。
- **权威**：写入前 code ∈ Registry；会话/`selling_mode` 只是偏好，须经 `SellingTypeResolver`；禁止请求体直写未解析 `cart_type`；与行级 `business_code` 正交。**默认 `toc`（零售，无需登录）**。**未注册偏好**（含 Provider 已卸载的残留 `tob`）解析时 **fail-soft 到 `toc`**，不抛热路径 500。guest+tob **加购/读指定类型** fail-closed；**访客 HTML/购物车页** 对残留 tob 偏好静默 `toc`，不把「该售卖类型需要登录」贴到零售车体。
- **可选 membership**：`Api/CommerceTypeMembershipCheckerInterface` + `CommerceTypeMembershipGate`（RuntimeProviderResolver）；B2B provides 实现。未配置时非 toc fail closed；Cart **不** import B2B。
- **分车键**：由 **Scope + owner** 升级为 **Scope + owner + registered_cart_type**。UI 切换类型只切换当前车视图，**不合并**异型车；同 Scope 可并存 toc 车与 tob 车。
- **旧键迁移**：无 `cart_type` 的历史键视为 **`toc`**，读路径一次迁移写入新键；新 tob 车只用新键。
- **mismatch fail closed**：加购/改删/合车时目标类型与车头类型不一致、或行类型混合 → 拒绝；跨类型 `mergeGuest` 禁止。未注册目标 code 在解析层已回落 toc。
- **无游客 tob**：`cart_type=tob` 强制已登录客户；未登录切批发 → 登录回跳后再申请/进 tob 车。
- **摘要**：`getCart` / Query 摘要始终含 `cart_type` + `type_payload`（由 Type Provider / B2B builder 填充；toc 可带 deal/券摘要，tob 标明 `discounts_applied=false` 等）。
- **`presentLine` 必须携带 `cart_type`**：snapshot resolve / Assembler context 带当前车类型；tob 走 B2B 候选价（或跳过零售 deal 重算），禁止无类型重解析把批发价盖回零售价。
- Checkout 冻结透传同一 `cart_type`；须在 Cart **与** Order Registry 均存在方可下单。
- **后台 Inspection**：仅按完整 `scope_key` 查询该 Scope 下真实持久缓存结果，**不是**全局购物车列表；类型列/筛选走 `CommerceCartTypeRegistry`（仅已注册 code）。
- **类型化事件**：`cart_item_added` / `cart_cleared` / `cart_merged` 经 `CartTypeEventEnvelope` 非破坏追加 `cart_type` + `type_payload`。

## Checkout 可信冻结（TEST-P2E-04）

- Checkout 只能调用公开 `CheckoutCartSnapshotInterface`，不得读取 Cart
  内部 Store 或接受浏览器提供的行、价格、数量、拆单键、配送属性
- `CheckoutCartSnapshotService` 每次冻结都重新解析 Offer 快照并重新执行
  可售/价格 Gate；快照缺失、币种漂移、不可售或空车均 fail closed
- 冻结结果包含服务端 `cart_hash`、Scope、币种和完整履约字段；Checkout
  只允许浏览器补充地址、`service_code`、quote token 与幂等键
- `clear` 用于受控清理当前可信 Cart；它与其它 V2 操作使用相同的
  服务端身份和 Scope 规则

## 持久化与登录合车（TEST-P2E-02）

### 三平面（Identity / Persistence / Presentation）

| 平面 | 权威 | 规则 |
|---|---|---|
| Identity | HttpOnly Cookie `weline_cart_guest_token` | JS `guest_session` 只镜像；`getCart` 参数与 Cookie 不一致时以 Cookie 为准；`issueGuestToken` 复用 Cookie，禁止无故轮转 |
| Persistence | `CartDbStore` → `weline_cart` | 游客 15 天 TTL；客户永久；写失败 `cart_persist_failed`；toc/tob 分车键 |
| Presentation | `summary_cache.{toc\|tob}` | 仅 soft paint；有货须 token 匹配 + 同 TTL；**空桶不得短路 return**（须回源）；paint 有货后可先画再回源；**禁止**无 token 冒充有货；sibling 有货时须失效对侧空桶 |

### 空车交叉推荐 `sibling_carts`

- 当前 `cart_type` 为空（或读路径闸门空壳）时，`getCart` / `storefrontSummary` 附带只读 `sibling_carts[]`：`cart_type` / `label` / `item_count` / `cart_count` / `switchable` / `gate`
- 同 Scope + 同 owner，枚举 `CommerceCartTypeRegistry::codes()` 中其它类型；`item_count=0` 不返回
- 访客读 `tob`：软空壳 + `gate_reason=login_required` + 可推荐有货 `toc`（突变仍 fail-closed）
- 店面空态须渲染 sibling CTA；禁止 sibling 有货时只提示「空的」
- 默认不自动跳转页签，只推荐 + 一键切换
- 店面切换 Event（Cart 拥有，解耦 B2B）：`weline:cart-type-changed`；`WelineCart.requestCartType(type)`；可选 chrome `[data-cart-type-option]`。Theme/购物车页禁止直点 B2B `data-b2b-*`；B2B `setMode` 适配派发/监听该 Event
- `requestCartType({ forceNetwork:true })` **必须**派 Event（跳过 chrome 软点）；B2B 适配 `setMode(..., { emit:false })`，避免无 FN 的二次 Event 被 `preferCache` 盖住有货 sibling
- 切类型 `preferCache` **只**可短路有货摘要；空摘要必须 `getCart` 回源。渲染 `sibling_carts` 有货时清除对侧空桶，禁止「批发说零售有 N 件 / 零售页空车」串态

- Store：`CartDbStore`（表 `weline_cart`，跨 Worker / 跨进程权威持久化）；单测用 `CartMemoryStore`；`CartCacheStore` 仅为遗留非默认
- 过期策略（`CartPersistencePolicy`）：
  - **游客**：`expires_at = now + 15 天`；Cookie `weline_cart_guest_token`、前端 `weline.cart.guest_session` 同 TTL；临近过期 `renewGuestSession` 再续 15 天；读路径过期行删除
  - **登录客户**：`expires_at = NULL`（永久）；加购/改删/合车写路径刷新 `updated_at`
- 写失败抛 `cart_persist_failed`（禁止假成功加购）
- Cookie：`weline_cart_guest_token`（`issueGuestToken` 写入）
- 只读 `getCart`：游客尚未持有 `guest_token`（无 Cookie/参数）时返回空车成功摘要，不抛 `cart_guest_token_required`；加购/改删/合车仍必须有 token
- **后台 Inspection**：`scope_key` 与/或 `guest_token`（完整或末 4+ 位）追查；展示 `expires_at` / `cart_type`
- Observer：`Weline_Customer_Account_Login::login_after` → `LoginMergeGuestCart`
- Query：`w_query('cart','add'|'mergeGuest'|'getCart'|'issueGuestToken'|…)`
- Query 的 `mergeGuest` 仅允许当前已登录客户；浏览器传入的
  `customer_id` 会被忽略且不再出现在前台 descriptor
- 合车先校验两车 Scope 和全部行币种；校验失败时不写客户车、不删除游客车
- Product 正式快照读取 Website shard 的 Offer/Product、Store 选品、
  EAV 名称、Price 和 Media；`CartHarnessCatalog` 只用于 E2E harness
- 旧购物车/Checkout 的价格可售校验通过
  `CartPriceSellabilityProviderInterface` 扩展；Cart 只拥有公共契约和
  `Api/CartPriceSellabilityGate`，Product 在自己的模块中注册实现。
  Provider 已声明但不可构造、执行异常或返回无效结果时 fail-closed；
  未安装任何 Provider 时兼容放行

## 入口

| 类 | 路径 |
|---|---|
| DTOs | `Api/Data/OfferIdentity.php`、`CartItemSnapshot.php` |
| Public boundary | `Api/CartSelectionHash.php`、`Api/CartPriceSellabilityGate.php`、`Api/CartPriceSellabilityProviderInterface.php`、`Api/CheckoutCartSnapshotInterface.php`、`Api/CartScopeResolverInterface.php` |
| Service | `Service/CartService.php` |
| Scope / identity boundary | `Service/CartScopeResolver.php`、`CartCurrentCustomerResolver.php` |
| Store | `Service/CartDbStore.php`（权威）/ `CartMemoryStore.php`（单测）/ `CartCacheStore.php`（遗留） |
| Product Provider | `Product/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCartItemSnapshotProvider.php`、`ProductCatalogCartItemSnapshotResolver.php` |
| Query | `w_query('cart','add'|'mergeGuest'|'getCart'|'clear'|'issueGuestToken'|…)` |

`CartService::add` 在同时带 `provider_code` + `global_offer_uuid` 时走 V2（默认注入 `CartService`）。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Cart/Test/Unit/bootstrap.php \
  app/code/Weline/Cart/Test/Unit

php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php \
  app/code/Weline/Product/Test/Unit/Service/ProductCopyDurableCatalogAdapterTest.php

PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN=http://127.0.0.1:{port} \
  php bin/w e2e:run app/code/Weline/Cart/Test/e2e/frontend/plan-p2e02-guest-login-merge.spec.js --project=chromium --headless

php bin/w e2e:run app/code/Weline/Checkout/test/e2e/frontend/plan-p2e002-current-source.spec.js \
  --project=chromium --headless
```

模块：`Weline_Cart` `1.2.1`；`Weline_Product` 需已发现 V2 extends。
