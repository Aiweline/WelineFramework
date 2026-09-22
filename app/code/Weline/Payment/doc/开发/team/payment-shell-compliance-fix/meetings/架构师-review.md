# 架构师 — 合规复审（对照冻结契约）

slug: `payment-shell-compliance-fix`  
席位: Team:架构师:  
日期: 2026-09-22  
对照: `contracts.md` + `meetings/align-freeze.md` + `meetings/支付开发工程师-review.md`  
范围: **只读**抽查；未改生产码。  
MCP: `prepare_project` 本回合未挂载（Not connected）；以契约与源码抽查为准。

## 总判

| 项 | 结果 |
|----|------|
| **本波汇审** | **pass** |
| 阻塞项 | **无** |
| 可下波 | `markConfirmCapture` 仍写 `PaymentTransaction`（协作门禁债，非本波 FIX MUST） |

契约单测复跑: **OK (20 tests, 152 assertions)**。

---

## 逐项（MUST / MUST NOT）

| ID | 契约要求 | 抽查证据 | 结果 |
|----|----------|----------|------|
| FIX-P0-VERIFY | 机制 A：`verifyCallback` 本地验签 only；禁 `verifyWebhookSignature` / HTTP；无材料生产 fail-closed | `PayPalProvider::verifyCallback`（~637–732）：仅 `openssl_*` 本地验签；缺 `webhook_cert_pem`+transmission → `verified=false`；仅 `allow_unsigned_webhook` 放宽。方法体内无 `verifyWebhookSignature` / `getApiClient` / HTTP。`PayPalApiClient` 仍用于下单/捕获/退款，**不进** verify 路径。 | **pass** |
| FIX-P1-SOFT | 去掉「无签仅凭 event id 即 verified」 | 同上：无 soft-accept 分支；与 VERIFY 同路径 fail-closed。 | **pass** |
| FIX-P0-SECRET | paypal/stripe 密钥 `type=secret` + `value-type=encrypted` | `Config/backend/paypal.phtml`：`sandbox_client_secret` / `live_client_secret` → encrypted。`stripe.phtml`：sandbox/live `*_secret_key` / `*_webhook_secret` → encrypted。 | **pass** |
| FIX-P1-EXPRESS | Facade 新增 `abandonExpressPayment`；`abandon` 只调 Facade，禁直写 Transaction | Interface 已声明；`ExpressCheckoutOrchestrator::abandonExpressPayment` 在 Payment 内写终态；`ExpressCheckoutFlowService::abandon` 仅 `PaymentExpressFacadeInterface::abandonExpressPayment` + Order 取消。`abandon` 路径无 `setData`/`save` Transaction。 | **pass** |
| FIX-P1-REFUND | `PaymentService::refund` 主签名 `amount_minor: int` | `refund(string $transactionNo, int $amountMinor, ...)`；`refundWithMajorAmount` 标 `@deprecated` 转 minor。 | **pass** |
| MUST NOT V2 cutover | 禁止 `PaymentFacadeV2::$entryEnabled` 全量开启 | 源码默认 `$entryEnabled = false`；无 `= true` / `setEntryEnabled(true)` 生产赋值。 | **pass** |
| MUST NOT 渠道 Controller | 禁止新建 `*PayPal*`/`*Stripe*` 业务 Controller | Payment 模块无匹配渠道专用 Controller。 | **pass** |
| MUST NOT 选型 B | 禁止壳 Inbox 前远程核验 | verifyCallback 零出站；未见壳侧远程验签半成品入口。 | **pass** |

---

## 残留（是否阻塞本波汇审）

| 残留 | 严重度 | 阻塞本波？ | 说明 |
|------|--------|------------|------|
| `ExpressCheckoutFlowService::markConfirmCapture` 仍 `setRequestData`→`save` `PaymentTransaction`（已标 TODO） | P1 协作门禁债 | **否** | 契约 FIX-P1-EXPRESS MUST 范围是 **abandon（及相关失败路径）**；confirm/capture 写路径未列入本波 MUST 表。与支付席「下波迁 Facade」一致。协作门禁「Checkout 禁写 Payment Model」**未清零**，须下波关闭。 |
| `PayPalApiClient` 仍挂在 Provider（下单等） | P2 | **否** | 契约明确「不大迁 ApiClient」另波。 |

---

## 与支付席自审对照

支付席清单与源码抽查一致；宣称的 MUST NOT 成立。残留披露准确，**不构成**本波汇审否决。

---

## 回报摘要

- **pass|fail**: **pass**
- **阻塞项**: 无
- **可下波项**:
  1. `markConfirmCapture`（及任何仍写 `PaymentTransaction` 的 Checkout 路径）迁入 `PaymentExpressFacadeInterface`
  2. （可选/另约）`PayPalApiClient` 命名空间迁址；V2 entry cutover 仍须独立契约波次
