# Cart V2（P2E-001 / P2E-002 / REQ-009）

## 契约

- SPI：`CartItemSnapshotProviderV2Interface::getProviderCode()` + `resolveCartItemSnapshot(OfferIdentity, ScopeIdentity, selection)`
- Registry：`CartItemSnapshotProviderV2Registry` — provider code O(1)；重复 code → `cart_provider_code_duplicate`
- 旧 V1 Registry **不改**；仅当 Offer 含 `legacy_product_id` 时走 `LegacyCartItemSnapshotProviderV2Adapter`
- selection hash（仅服务端权威）：
  `sha256(global_offer_uuid + "\\n" + selection_schema_version + "\\n" + canonical_sorted_json)`
- 客户端伪造 hash → `cart_selection_hash_mismatch`；非法 selection → `cart_selection_invalid`
- 跨模块统一使用 `Api/CartSelectionHash`；`Service/CartSelectionHash` 保留为
  Cart 内部实现，Product 不直接依赖 Cart Service
- 跨 Scope / 跨币种不合并；同 Scope guest→customer 合车并按可售上限截断
- 前台 `customer_id` 不是身份凭据：`add/addV2/mergeGuest/getV2Cart` 只使用
  `CartCurrentCustomerResolver` 从公开
  `CustomerAccountFacadeInterface::current()` 得到的服务端登录身份
- Query 与登录合车 Observer 的 flat
  `website_code/store_code/channel_code/store_mode`，以及 Query 的完整
  `scope`，统一由 `CartScopeResolver` 解析；`channel_code` 缺少
  `store_code` 时 fail-fast
- 未显式传 Scope 的站内调用继承 `RequestContext::scopeIdentity()`；
  Checkout 因而与当前 Channel Cart 使用同一可信 Scope。显式 Scope
  只有在当前请求尚无可信 Scope，或与已冻结 Scope 完全一致时才接受；
  Website/Store/Channel 任一不一致都返回 `cart_scope_request_conflict`，
  不能用客户端参数跨 Host 串车

## Checkout 可信冻结（TEST-P2E-04）

- Checkout 只能调用公开 `CheckoutCartSnapshotInterface`，不得读取 Cart
  内部 Store 或接受浏览器提供的行、价格、数量、拆单键、配送属性
- `CheckoutCartSnapshotService` 每次冻结都重新解析 Offer 快照并重新执行
  可售/价格 Gate；快照缺失、币种漂移、不可售或空车均 fail closed
- 冻结结果包含服务端 `cart_hash`、Scope、币种和完整履约字段；Checkout
  只允许浏览器补充地址、`service_code`、quote token 与幂等键
- `clearV2` 用于受控清理当前可信 Cart；它与其它 V2 操作使用相同的
  服务端身份和 Scope 规则

## 持久化与登录合车（TEST-P2E-02）

- Store：`CartV2CacheStore`（`w_cache('cart_v2')` Custom 全逃逸，跨 Worker）；单测用 `CartV2MemoryStore`
- Cookie：`weline_cart_guest_token`（`issueGuestToken` 写入）
- Observer：`Weline_Customer_Account_Login::login_after` → `LoginMergeGuestCart`
- Query：`w_query('cart','addV2'|'mergeGuest'|'getV2Cart'|'issueGuestToken'|…)`
- Query 的 `mergeGuest` 仅允许当前已登录客户；浏览器传入的
  `customer_id` 会被忽略且不再出现在前台 descriptor
- 合车先校验两车 Scope 和全部行币种；校验失败时不写客户车、不删除游客车
- Product 正式快照读取 Website shard 的 Offer/Product、Store 选品、
  EAV 名称、Price 和 Media；`CartV2HarnessCatalog` 只用于 E2E harness
- 旧购物车/Checkout 的价格可售校验通过
  `CartPriceSellabilityProviderInterface` 扩展；Cart 只拥有公共契约和
  `Api/CartPriceSellabilityGate`，Product 在自己的模块中注册实现。
  Provider 已声明但不可构造、执行异常或返回无效结果时 fail-closed；
  未安装任何 Provider 时兼容放行

## 入口

| 类 | 路径 |
|---|---|
| DTOs | `Api/Data/OfferIdentity.php`、`CartItemSnapshot.php` |
| Public boundary | `Api/CartSelectionHash.php`、`Api/CartPriceSellabilityGate.php`、`Api/CartPriceSellabilityProviderInterface.php`、`Api/CheckoutCartSnapshotInterface.php` |
| Service | `Service/CartV2Service.php` |
| Scope / identity boundary | `Service/CartScopeResolver.php`、`CartCurrentCustomerResolver.php` |
| Store | `Service/CartV2CacheStore.php` / `CartV2MemoryStore.php` |
| Product Provider | `Product/extends/module/Weline_Cart/CartItemSnapshotProviderV2/ProductCartItemSnapshotProvider.php`、`ProductCatalogCartItemSnapshotResolver.php` |
| Query | `w_query('cart','addV2'|'mergeGuest'|'getV2Cart'|'clearV2'|'issueGuestToken'|…)` |

`CartService::add` 在同时带 `provider_code` + `global_offer_uuid` 时走 V2（默认注入 `CartV2Service`）。

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

模块：`Weline_Cart` `1.2.0`；`Weline_Product` 需已发现 V2 extends。
