# 对齐冻结会纪要 — payment-shell-compliance-fix

日期: 2026-09-22  
席位: Team:架构师:  
结论: **同意** 定稿（草稿 MUST 合理；补全机制选型与 Express API）

## 同意项

1. 本波范围：只修实现偏航与边界侵蚀；**禁止 V2 全量 cutover**。
2. FIX-P0-SECRET：配置密钥 `value-type=encrypted|secret_ref`。
3. FIX-P1-SOFT：去掉生产 soft-accept（无签仅凭 event id）。
4. FIX-P1-EXPRESS：Checkout 禁写 `PaymentTransaction`；经 Payment Express Facade。
5. FIX-P1-REFUND：对外主路径 `amount_minor: int`。
6. MUST NOT：无渠道专用 Controller、不大迁 ApiClient、不批量改 payment-methods 全文。

## 定稿补强（原草稿含糊 → 已写入 contracts.md）

| 议题 | 决议 |
|------|------|
| FIX-P0-VERIFY 机制 | **选定 A**：Provider 本地验签 only；`verifyCallback` 零出站；无本地材料 → 生产 fail-closed |
| 选型 B | **否决（本波）**：不做壳 Inbox 前远程核验阶段；另波再议 |
| FIX-P1-EXPRESS API | **新增** `PaymentExpressFacadeInterface::abandonExpressPayment(string $transactionNo, string $reason = 'abandoned'): array`；实现于 `ExpressCheckoutOrchestrator` |

## 否决项

- 本波引入壳侧远程验签阶段（B）  
- 本波开启 `PaymentFacadeV2` entry 全量 cutover  
- Checkout 继续直写 Payment Model 作为「临时」方案  

## 交给支付席

见 contracts.md MUST 表 + 开工清单（项目经理转发）。
