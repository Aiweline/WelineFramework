# 支付壳合规修复 — 对齐冻结契约

slug: `payment-shell-compliance-fix`  
日期: 2026-09-22  
模式: Team（项目经理 + 架构师 + 支付开发工程师）  
架构师冻结: **同意**（机制选型与 Express API 已定稿，见下）

## 背景

汇审查出万能支付**部分符合**：壳设计成立，问题主要是实现偏航 + V1/V2 演进债。  
本波**只修实现偏航与边界侵蚀**，**不做 V1→V2 全量 cutover**。

## 方案（冻结）

### 本波 MUST（支付开发工程师施工）

| ID | 严重度 | 要求 | 验收 |
|----|--------|------|------|
| FIX-P0-VERIFY | P0 | **机制选型 = A（Provider 本地验签 only）**。`PayPalProvider::verifyCallback`（及同构 Provider）**禁止出站**（禁调 `PayPalApiClient::verifyWebhookSignature` / 任意 HTTP）。本地可验则本地验；不能本地验且无有效签名材料 → **生产 fail-closed**（`verified=false`）。**本波禁止选型 B**：不得新增壳侧「Inbox 入箱前远程核验」阶段。远端官方验签若未来需要，另开契约波次。 | 单测：有 transmission headers 的路径不调用远程；无签生产拒绝；DevRelay/显式测试开关可放宽另测；源码断言 `verifyCallback` 无 `verifyWebhookSignature` / HTTP client 调用 |
| FIX-P0-SECRET | P0 | `paypal.phtml` / `stripe.phtml` 中 client_secret / secret_key / webhook_secret 等：`type` 用 secret/password，**`value-type=encrypted`（或 secret_ref）**；禁止 `value-type=string` | 源码断言 + 对照 SystemConfig 加密字段约定 / `provider-development.md` |
| FIX-P1-SOFT | P1 | 去掉「无 webhook_id 且无 transmission headers 仅凭 event id 即 verified=true」的生产 soft-accept | 与 FIX-P0-VERIFY 同测 |
| FIX-P1-EXPRESS | P1 | `Checkout\Service\ExpressCheckoutFlowService::abandon`（及相关失败路径）**禁止**直接 `PaymentTransaction::setData/save`；必须经下方冻结公开 API 改支付终态。订单取消仍属 Checkout/Order 域，可留在 FlowService。 | Checkout 写路径无 `PaymentTransaction::{setData,save}`（读若暂留须标 TODO 或改 Query/Facade）；契约单测 |
| FIX-P1-REFUND | P1 | `PaymentService::refund` 对外主签名改为 `amount_minor: int`（或新增 `refundMinor` 并废弃 float 主入口）；调用方改传整数；禁止 `round($amount*100)` 作为唯一公开 API。内部可继续委托已有 `PaymentRefundService::refundByTransactionCode(..., int $requestedAmountMinor, ...)` | 签名/调用点 grep + 单测 |

### FIX-P0-VERIFY 机制选型（冻结，不可含糊）

| 选项 | 含义 | 本波 |
|------|------|------|
| **A. Provider 本地验签 only** | `verifyCallback` 纯函数：本地密码学校验 / 有缓存材料则用；否则生产 `verified=false`；**零出站** | **✅ 选定** |
| B. 壳侧远程核验阶段（Inbox 前） | 壳在入箱前调远端官方验签 API | ❌ 本波禁止；不实现、不预留半成品入口 |

权威对齐：`webhook.md`「`verifyCallback` / `parseCallback` 禁止调远端」；`payment-shell.md` §5。

### FIX-P1-EXPRESS 公开 API（冻结）

现有 `Weline\Payment\Api\PaymentExpressFacadeInterface` **无** abandon/cancel 写终态方法（仅有 list/supports/withExpressContext/evaluate/applyExpressProfile）。

**本波规定新增**（支付席实现；Checkout 只调 Interface，不 new Orchestrator）：

```php
namespace Weline\Payment\Api;

interface PaymentExpressFacadeInterface
{
    // ... existing methods ...

    /**
     * 将未支付 Express 交易标为失败终态（写 PaymentTransaction / request_data.metadata）。
     * 已成功支付则 skipped；禁止 Checkout 直写 Payment Model。
     *
     * @return array{
     *   transaction_no: string,
     *   abandoned: bool,
     *   skipped?: string,
     *   status?: string,
     *   error?: string
     * }
     */
    public function abandonExpressPayment(string $transactionNo, string $reason = 'abandoned'): array;
}
```

- 实现类：`Weline\Payment\Service\ExpressCheckoutOrchestrator`（已注册为该 Interface 的 provides）
- Checkout 调用：`$om->getInstance(PaymentExpressFacadeInterface::class)->abandonExpressPayment($transactionNo, $reason)`
- 行为最小集：未找到 / 已成功 → 不改写并返回 `skipped`；否则写 `express_abandoned` / `express_abandon_reason`、清除 `express_awaiting_confirm`、`STATUS_FAILED` 并 save（全部在 Payment 模块内）
- **禁止**另开渠道 Controller 或让 Checkout 调 `PaymentTransaction` 写路径

### 本波 MUST NOT

- 开启 `PaymentFacadeV2::$entryEnabled` 全量 cutover  
- 新建渠道专用 Controller（`*PayPal*` / `*Stripe*` 业务 Controller）  
- 大迁 `PayPalApiClient` 命名空间（P2，另波）  
- 批量改写 `doc/payment-methods/**` 全文（本波仅可在 README/壳文档加一句「仅 Fake/PayPal/Stripe 已实现 Provider」）  
- 实现选型 B（壳 Inbox 前远程核验阶段）  
- 在 `verifyCallback` 内保留或伪装出站验签  

### 协作门禁（架构冻结，全波有效）

1. 禁止再扩 V1 金额入口（调用方传金额喂支付）  
2. Checkout **禁写** Payment Model  
3. 新网关只交 Extends Provider + 壳 URL  

## 细节 — deps

```text
架构师 contracts 冻结 → 支付开发工程师 FIX-P0-* 并行 → FIX-P1-* → 测试/契约 → 汇审
```

## surfaces

- Payment Provider / SystemConfig templates  
- `PaymentExpressFacadeInterface` + `ExpressCheckoutOrchestrator`  
- Checkout Express abandon（仅改调用，不写 Payment Model）  
- PaymentService refund ABI（兼容：可保留 deprecated float 包装一层，内部转 minor，但新代码只走 minor）

## 不停工

架构师已判定：非停工级矛盾。MUST 集合合理；含糊项（验签机制 / Express API）已定稿。
