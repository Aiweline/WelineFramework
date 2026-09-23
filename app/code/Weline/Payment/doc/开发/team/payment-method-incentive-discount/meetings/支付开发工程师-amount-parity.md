# Team:支付开发工程师: · 金额恒等核对 / amount_parity

| 字段 | 值 |
|------|-----|
| 席位 | Team:支付开发工程师: |
| 波次 | amount_parity（Browser pass 后观察） |
| 版本 | Payment **1.9.106** · Checkout **1.5.43** · Order **2.13.44** |
| result | closed |
| notify_pm | true |
| amount_parity | **fail → fixed** |
| ready_for_retest | **true** |

## 1. 实证（订单 / 交易）

| 对象 | 值 |
|------|-----|
| order_uuid | `2f8d298e-37d3-47f1-978e-6677f05dd5dd` |
| transaction_no | `PAY20260922113109144913` |
| Order money | `grand_total_minor=1892` · `discount_amount_minor=0` |
| Txn amount | **13.92 USD**（`amount_minor=1392`） |
| Txn `discount_lines` | **含** `source_type=payment_method_incentive` · `amount_minor=-500` |
| Txn totals | `grand_total_minor=1392` · `discount_amount_minor=500` |

结论：

- **PaymentService create intent**：已减 500（Provider 实收 1392）✓  
- **站内订单应付**：仍 1892、无激励行 ✗ → **amount_parity=fail**（站内 ≠ 网关）  
- UI「Save USD 5.00 / -$5.00」为前端扁字段展示，**未写入 freeze quote / Order money**

## 2. 缺口根因

1. freezeQuote 只加 COD，**未**调用激励 SPI → session `totals.grand_total_minor` / `discount_lines` 无 PMI  
2. submit 只把券 `discount.amount_minor` 传给 Order；OrderFacade **忽略** `options.discount_amount_minor` 写 MoneySnapshot  
3. CheckoutOrderPaymentService 用订单 1892 起付；PaymentService 在 create 时才减 → 交易对、订单错

## 3. 修复

1. `CheckoutPaymentIncentiveApplier`：选中 method → SPI `applyToOrderData` → `discount_lines` + 下调 grand  
2. `CheckoutGroupSubmitService::freezeQuote`：COD 后并入激励；session 写 `discount_lines` / `payment_method_incentive_amount_minor`  
3. submit：`discount_amount_minor` = 券 + 激励；`type_payload.discount_lines` 落盘  
4. `OrderFacade::plan/create`：兑现 `options.discount_amount_minor` → MoneySnapshot  
5. `CheckoutOrderPaymentService`：支付前再 apply（幂等）+ `syncOrderPaymentIncentive` 对齐订单 money

契约测：

- `PaymentMethodIncentiveQuoteContractTest`（含幂等）  
- `CheckoutPaymentIncentiveApplierContractTest`  
- `OrderFacadeTest::testPlanAndCreateHonorDiscountAmountMinor`  

`server:reload` 已执行。

## 4. 回报

- **amount_parity=fail**（历史单）→ **已修**（新结账路径）  
- **ready_for_retest=true**  
- 禁改 SESSION

@项目经理：本席已交付/上报，请检查并更新 SESSION
