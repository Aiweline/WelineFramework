# Weline_Points

万能积分与会员成长/VIP 等级规则模块。

## 边界

| 能力 | 归属 |
|---|---|
| 可花积分余额、预占、扣减、退回 | `Weline_CustomerAsset`，`asset_code=points` |
| 积分作为支付/折扣资产 | `Weline_Payment` 的 `points` 策略 + 本模块策略贡献 |
| 赚分比例、成长值、累计积分、VIP 等级、结账抵扣、退款扣回 | `Weline_Points` |

- 可花余额只有一本：`weline_customer_asset_account` 的 `points` 账户。本模块不建第二本余额。
- 成长值与累计赚取积分只增不减，不能花，不写进 `points` 余额。
- 退款分两笔：花掉的积分由 Payment/CustomerAsset `returnCommitted` 原路退回；本单赚到的积分由本模块另记扣回，禁止调用 `returnCommitted` 冲销赚分。
- 结账抵扣默认单笔最多 30%，后台可配；实际扣减走 `reserve → commit → returnCommitted`。
- 所有业务开关默认关闭；结账抵扣还需 Payment 积分资产、正的兑换比例和 CustomerAsset mode 同时打开。

## 组件

| 路径 | 职责 |
|---|---|
| `Model/VipLevel` | VIP 等级定义：门槛、折扣率、积分倍率、包邮 |
| `Model/CustomerState` | 成长值、累计赚取积分、当前等级 |
| `Model/EarnRecord` | 赚分事实、结算币/基准币金额、逐行快照、幂等事件号 |
| `Model/ClawbackRecord` | 退款扣回事实与未扣回欠账 |
| `Model/DiscountApplyRecord` | 结账积分抵扣的预占/提交/退回状态 |
| `Service/PointsService` | 订单支付成功赚分、订单退款扣回 |
| `Service/PointsItemEarnCalculator` | 逐商品行积分计算 |
| `Service/PointsProductEarnPolicy` | 读取商品 EAV 积分属性 |
| `Service/PointsBaseCurrencyResolver` | 站点基准货币与汇率换算 |
| `Service/PointsCheckoutDiscountQuote` | 结账积分抵扣报价与钳制 |
| `Service/PointsDiscountSettlementService` | 抵扣预占/提交/退回 |
| `Service/PointsPaymentAssetPolicyProvider` | 向 Payment 贡献积分折扣策略 |
| `Api/PointsVipFacadeInterface` | 读取成长、累计积分、等级、权益 |
| `Api/PointsDiscountSettlementInterface` | 结账抵扣结算契约 |
| `Observer/OrderPaidObserver` | 唯一赚分入口 |
| `Observer/OrderRefundedObserver` | 退款：退回抵扣积分 + 扣回赚分 |
| `Observer/OrderCancelledObserver` | 取消：释放抵扣预占 |
| `Observer/CheckoutPointsDiscountApplyObserver` | 结账会话写入积分抵扣计划 |
| `extends/.../Query/PointsQueryProvider` | `discount.quote/apply/clear`、`vip.state/benefits/levels` |
| `extends/.../backend/points.phtml` | 后台开关、比例、商品默认值、抵扣上限 |
| `view/hooks/Weline_Theme/.../body-end.phtml` | 购物车/迷你购物车/结账页挂载积分抵扣面板 |
| `view/statics/js/points-discount.js` | 报价、应用、清除，写 `points_discount_apply_minor` |
| `view/statics/frontend/weline.modules.js` | 注册 `pointsCheckout` 模块 |

## 赚分规则

1. 只监听 `Weline_Order::order_paid`。不监听 `order_completed` 或 Checkout payment success，避免双计。
2. 基数 = 订单总额 − 已提交资产抵扣额 − 积分抵扣额，即实付现金。
3. 实付现金先按结账币精度换算为**站点基准货币**，再向下取整到整数主币单位；缺汇率时整单跳过，禁止无汇率 1:1 直算。
4. 有订单行且有商品 EAV 属性时按商品逐行计算；否则回退订单级计算。
5. 游客、找不到顾客、HelpPay 代付单不赚分。
6. 幂等键：`points:earn:{website_id}:{namespace}:{order_id}:{payment_key}`。
7. 积分入库走 `CustomerAsset::credit()`；mode off 时整单不入账、不记赚分事实，等开关打开后重放仍可入账。

## 商品积分属性

Product EAV 属性（由 Install/Upgrade 自动声明，set=`storefront`，group=`specs`）：

| code | 类型 | 含义 |
|---|---|---|
| `points_earn_ratio` | `input_decimal_2` | 该商品行积分倍率；空则用后台默认倍率 |
| `points_earn_fixed` | `input_int` | 每件固定积分；大于 0 时覆盖比例 |

后台默认：`points/product/default_ratio`（默认 1）、`points/product/default_fixed_points`（默认 0）。

## VIP 等级规则

- 默认按**累计赚取积分**升级（`points/vip/basis=lifetime_points`）；可切换为成长值。
- 累计积分只在赚分时增加，抵扣、退款、扣回都不减少，因此不会因花积分掉级。
- 成长值同样只增不减。
- 权益字段：`discount_rate`（0.05 = 5%）、`points_multiplier`、`free_shipping`。
- 积分倍率只影响新赚积分；已有积分和累计积分不被改写。
- 默认等级：普通、白银（1000）、黄金（5000）、铂金（20000）、钻石（50000）。
- `points/vip/enabled` 关闭时成长值/累计积分照常记录，但所有顾客按普通会员权益计算。

## 结账抵扣规则

- 报价：`PointsCheckoutDiscountQuote` 读取可花积分，按 `payment/asset/points/exchange_ratio` 折算为基准币，再换算结账币，最后按 `points/discount/max_ratio`（默认 0.3）与余额取小。
- 应用：`asset_discount::apply` 观察者把计划写入会话与订单 `type_payload`。
- 结算：`CheckoutOrderPaymentService` 在发起支付前 `reserveForOrder()`，支付成功 `commitForOrder()`，失败/取消 `releaseForOrder()`，退款 `returnForRefund()`。
- 单笔抵扣上限默认 30%，因此积分不会单独把应付降到 0；现金仍需走 PSP。
- 同一应付不能同时把积分当支付和折扣；本模块策略默认只开折扣角色。

## 退款扣回规则

1. 花掉的抵扣积分：按 `DiscountApplyRecord` 与退款金额比例 `returnCommitted` 退回。
2. 赚到的积分：应扣 = 原赚分 ×（本次退回现金 / 原实付现金），向下取整；全额退款扣完剩余。
3. 多次部分退款累计不超过原赚分。
4. 实扣不超过当前可用积分；不足部分写入 `ClawbackRecord.unrecovered_points`，是审计欠账，不是负余额。
5. 扣回用独立幂等键：`points:clawback:{website_id}:{namespace}:{order_id}:{refund_key}`。
6. 现金退款不因扣回失败被拦截。

## 配置

后台「系统配置 → 积分与会员等级」：

| 键 | 默认 | 说明 |
|---|---|---|
| `points/earn/enabled` | 0 | 赚分开关 |
| `points/earn/rate_per_currency_unit` | 1 | 每 1 基准币主单位赚多少点 |
| `points/growth/rate_per_currency_unit` | 1 | 每 1 基准币主单位增加多少成长值 |
| `points/product/default_ratio` | 1 | 商品未配置时的默认倍率 |
| `points/product/default_fixed_points` | 0 | 商品未配置时的默认每件固定积分 |
| `points/discount/enabled` | 1 | 积分抵扣开关 |
| `points/discount/max_ratio` | 0.3 | 单笔最大抵扣比例 |
| `points/vip/enabled` | 0 | VIP 等级权益开关 |
| `points/vip/basis` | lifetime_points | 等级依据：累计积分/成长值 |
| `points/vip/multiplier_enabled` | 1 | 等级积分倍率开关 |

花积分还需要同时打开：`customer_asset` mode、`payment/asset/points/enabled`、正的 `payment/asset/points/exchange_ratio`。

## 后台入口

菜单「电商 → 积分与会员」：

| 菜单 | 路径 |
|---|---|
| 积分概览 | `points/backend/points/index` |
| VIP 等级 | `points/backend/points/levels` |
| 会员成长 | `points/backend/points/customers` |
| 积分账本 | `points/backend/points/ledger` |
| 抵扣记录 | `points/backend/points/discounts` |
| 积分设置 | `points/backend/points/settings` |

后台菜单搜索「积分」可命中「积分与会员」。菜单与 ACL 由 `etc/backend/menu.xml` 提供；新增/修改后执行 `php bin/w setup:upgrade --force-menu -m Weline_Points`。

## 前端查询

`w_query('points', 'discount.quote', { website_id, currency, amount_minor })`
`w_query('points', 'discount.apply', { website_id, currency, amount_minor, apply_minor })`
`w_query('points', 'discount.clear')`
`w_query('points', 'vip.state')` / `vip.benefits` / `vip.levels`

暴露面（`PointsQueryProvider::getDescriptor()`）：

| operation | mode | frontend | external | 说明 |
|---|---|---|---|---|
| `discount.quote` | read | 是 | 是 | 购物车/结账报价 |
| `discount.apply` | write | 是 | 是 | 钳制并保存抵扣选择 |
| `discount.clear` | write | 是 | 是 | 清除抵扣选择 |
| `vip.levels` | read | 是 | 是 | 等级门槛与权益 |
| `vip.state` / `vip.benefits` | read | 否 | 否 | 含 `customer_id` 参数，仅服务端读取，防 IDOR |

`discount.apply` / `discount.clear` 的 write 模式经 Frontend `QueryBin` 入口，先 `assertProtocolHeaders()` + `assertSameOrigin()`（`app/code/Weline/Frontend/Controller/Api/QueryBin.php:87-91`、`843-856`）；Origin 不匹配返回 401。会话 Cookie 默认 `SameSite=Lax`（`QueryBin.php` `attachPendingCookies()`），跨站 POST 不携带会话 Cookie。**没有独立 CSRF token**，不要把 same-origin 校验写成 CSRF token。

结账提交可带 `points_discount_apply_minor`；若未带，CheckoutQueryProvider 会读取 `PointsCheckoutApplySession` 中 `discount.apply` 保存的金额，再与 `b2b_credit_apply_minor` 一起送入资产折扣 apply 事件。

## E2E 验收（2026-10-27，隔离沙箱）

- 环境：独立克隆库 `mig_clone_pointse2e_20261026181838_4613`（`mig:foundation clone-create --mode=full`），独立 `php -S 127.0.0.1:8787` 副本 `/tmp/weline-points-e2e`，独立 Chrome profile（CDP 9333）。
- 共享站 `p05113ef3.test.weline.com` 未再改配置；未发真实积分；未用裸 SQL 改业务表。
- 流程（真实浏览器）：登录（local_image 验证码）→ 商品页选 M → 加购 → `/cart` 面板 quote（可用 101998，最多 96.10）→ apply 9610 → `/checkout` 填地址（payable 31773）→ 面板最多 95.31 → apply 9531 → 提交 `fake_card` → success。
- 订单：`b63f4fc5-287e-401b-a246-aea4c8cdf15d` / `#1404195708` / paid / grand_total 317.73。
- 支付：`PAY20261026160406941379` / `fake_card` / success / 222.42（= 317.73 − 95.31）。
- 积分：`type_payload.points_discount_points=9531`；`discount_apply` committed 9531；ledger `reserve` / `commit` / `earn` 齐全；余额 101998 → 92467，预占 0；VIP lifetime 2283 → 2518（silver，未下降）。
- 30% 上限：`floor(31773 × 0.30) = 9531`，与 apply 一致。
- 截图：`/tmp/weline-points-e2e/evidence-cart-panel.png`、`evidence-checkout-applied.png`、`evidence-order-success.png`。

## 验证

```bash
vendor/bin/phpunit --bootstrap app/code/Weline/Points/Test/Unit/bootstrap.php \
  app/code/Weline/Points/Test/Unit
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php \
  app/code/Weline/Order/test/Unit/Service/OrderAssetAllocationSnapshotServiceTest.php
php bin/w setup:schema:check -m Weline_Points --json
php bin/w architecture:check --allow-legacy --json
php bin/w setup:upgrade --model -m Weline_Points
```

最近结果：Points 单测 `OK (35 tests, 109 assertions)`；Order 快照 `OK (6 tests, 28 assertions)`；Checkout 支付/提交/零现金 4 个文件 `OK (27 tests, 137 assertions)`；schema `{"ok": true, "exit": 0, "checked_modules": 1, "drifts": []}`。全量 Checkout Unit 仍有既有失败（`Tests: 103, Assertions: 1746, Errors: 1, Failures: 13, Warnings: 1`，含 `CheckoutSessionAccessServiceTest.php:89` 致命错误），与 Points 改动无关，不能记为全量通过。

## 已知问题

1. 订单金额投影未减积分：E2E 订单 `money_snapshot` / 成功页仍显示 317.73，实际 PSP 扣款 222.42。`ensureOrderMoneyConserved` 只按 `subtotal+shipping+tax+cod−discount` 重算，积分抵扣不在 `discount_amount` 内；属产品口径待定。
2. `commitPointsDiscount` / `releasePointsDiscount` 失败现在写 `w_log_error('points')`，但无自动重试/outbox；PSP 成功而 commit 失败时积分会保持预占。
3. 支付阶段直接信任 `type_payload` 的积分数，不重复校验 30% 上限；依赖 apply 时服务端 clamp。
4. `architecture:check` 对 Points 仍有 6 条 findings：2 条 `dependency.internal_api`（`CurrencyRateService`、`WebsiteData`）和 4 条 `Weline_Checkout -> Weline_Points -> Weline_Customer` 循环引用，未修复。

## 未覆盖

- 后台等级增删改查页面。
- Shipping 免运费权益、VIP 折扣率接入结账报价链路（本模块只提供读取契约）。
- 积分过期、提现、转让、跨站共用、现金购买积分。
- 共享站不再做 E2E 配置改写；后续 E2E 只在一次性沙箱跑。
