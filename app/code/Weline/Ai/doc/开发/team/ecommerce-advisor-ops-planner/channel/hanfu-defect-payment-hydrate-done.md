# channel — 汉服缺陷波 · 支付水合

日期：2026-09-23  
席位：`Team:支付开发工程师:`  
验收 Host：`https://p05113ef3.test.weline.com:9555/`

## 根因

| 项 | 结论 |
|----|------|
| SystemConfig `payment/method/fake_card/enabled` | **已是 `1`**（`config_test_status=passed`，website_id=0 / default scope）— **不是** enabled=false 根因 |
| `include_unavailable` 见 fake_card/paypal 且 `enabled=false` | Provider `checkAvailability`：`amount_minor<=0` → `amount_required` |
| 结账空列表 | Checkout 传车金额；空车 `amount=0` → 全部不可用 → `CheckoutPaymentMethodsProvider` 过滤掉 → 壳空 |
| PayPal | 本机有 sandbox OAuth 凭证；有金额时可开；无金额仍 `amount_required`（保持） |
| `$49` 包邮 | **未改** |

## 改动（最小）

- `FakeProvider::checkAvailability`：**去掉**本地演示的 `amount_required` 门禁  
  - 空车 / 裸 `getCheckoutPaymentMethods` 也能列出 `fake_card`（`enabled=true`）  
  - `createPayment` / 提交仍走正金额应付；PayPal 行为不变  

## 验证

```text
w_query payment getCheckoutPaymentMethods []           → [fake_card] enabled=true
w_query payment getCheckoutPaymentMethods include_unav → fake_card=true; paypal=amount_required
CheckoutPaymentMethodsProvider::listMethods([])        → [fake_card]
w_query checkout getData []                            → payment_methods=[fake_card]
w_query payment getCheckoutPaymentMethods {amount:49}  → fake_card + paypal
php -l FakeProvider.php                                → OK
```

`notify_pm: true`  
**@项目经理：根因=空车金额门禁误伤列表，非 SystemConfig 关停。已放宽 fake_card 列表可用性；有货结账仍可连 PayPal。**

## 父跟进（同波合流）

- 父席另改 `CheckoutQueryProvider`：仅当支付列表仍空且车金额为 0 时，空态正文用「请先加入商品后再选择支付方式。」（现因 fake_card 可列，空车主路径已直接出 radio，该文案为兜底）。
- 本回合复验：`getCheckoutPaymentMethods []` → `fake_card`；`checkout getData` → `payment_methods=[fake_card]` 且 HTML 含「本地测试支付」。
- Worker 已 `server:restart -r` 载入 FakeProvider 改动。
