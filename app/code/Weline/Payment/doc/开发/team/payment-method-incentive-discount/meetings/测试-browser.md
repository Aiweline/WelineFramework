# Team:测试: · Browser 验收 — payment-method-incentive-discount

| 项 | 值 |
| --- | --- |
| 席 | Team:测试: |
| 日期 | 2026-09-22 |
| Host | `https://p05113ef3.test.weline.com:9555` |
| **波次** | **retest round 4 · 金额恒等**（Payment 1.9.106 / Checkout 1.5.43 / Order 2.13.44） |
| **result** | **pass** |
| notify_pm | true |
| 禁改 SESSION | 是（请 PM 更新） |

## HARD 执行记录

| 门禁 | 结果 |
| --- | --- |
| 新下一单（禁用旧单 `2f8d298e…`） | **pass** |
| Browser 禁缓存 + 抹 webdriver | 做到（CDP） |
| 列表「减 X」/徽章 | **pass** |
| 摘要优惠与 grand 同步 | **pass**（freeze totals 含激励） |
| 订单/交易金额恒等 + `discount_lines` | **pass** |
| PayPal sandbox | blocker（本波未跑；不挡 fake pass） |

## UC / 恒等结果（round 4）

| 项 | 结果 | 证据 |
| --- | --- | --- |
| 1 列表见激励 | **pass** | fake_card `Save USD 5.00` / `data-incentive-savings-minor=500` |
| 2 选 fake→摘要优惠 | **pass** | `Payment method discount -$5.00`（row 可见） |
| 3 金额恒等 | **pass** | 见下表 |
| 4 `discount_lines` | **pass** | `source_type=payment_method_incentive` amount_minor=-500 |
| UC-3 paypal 无误导徽章 | **pass** | 无徽章 |

### 新单恒等表

| 字段 | 值 |
| --- | --- |
| order_uuid | `058c5c3b-6524-4d72-8e29-1c3bf98cb2ae` |
| transaction_no | `PAY20260922114634664353` |
| display_number | `0438465043` |
| subtotal_minor | 1013 |
| shipping_amount_minor | 879（SEED_LANE_AMERICAS） |
| discount_amount_minor | **500** |
| payment_method_incentive_amount_minor | **-500** |
| grand_total_minor（订单） | **1392**（=1013+879−500） |
| transaction amount_minor | **1392**（=订单 grand） |
| method / status | fake_card / success / paid |

freeze `discount_lines`：

```json
{
  "key": "pmi:fake_card:browser-uc-20260922",
  "label": "Payment method discount",
  "amount_minor": -500,
  "source_type": "payment_method_incentive",
  "funding_source": "merchant",
  "method_code": "fake_card",
  "rule_version": "browser-uc-20260922"
}
```

订单 `type_payload.discount_lines` 同上。

## 证据 id

| id | 路径/值 |
| --- | --- |
| `EV-JSON-R4` | `meetings/evidence/browser-uc-retest4-20260922.json` |
| `EV-SHOT-R4` | `retest4-uc1.png` / `retest4-uc2-selected.png` / `retest4-success.png` |
| order_uuid | `058c5c3b-6524-4d72-8e29-1c3bf98cb2ae` |
| transaction_no | `PAY20260922114634664353` |

## related_web_urls

- [结账验收](https://p05113ef3.test.weline.com:9555/checkout)
- [前台首页](https://p05113ef3.test.weline.com:9555/)
- [订单成功页](https://p05113ef3.test.weline.com:9555/USD/checkout/success?order_uuid=058c5c3b-6524-4d72-8e29-1c3bf98cb2ae)

## 波次简史

1. R1：registry 无 enrich → fail  
2. R2：amount major→baseMinor=0 → fail  
3. R3：徽章+提交 pass，但扣款未含激励（旧单）  
4. **R4：金额恒等 pass（新单）**

@项目经理：本席已交付/上报，请检查并更新 SESSION。
