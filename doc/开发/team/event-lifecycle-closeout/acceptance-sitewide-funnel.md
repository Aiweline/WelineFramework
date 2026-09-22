# 全站电商像素主链验收（sitewide funnel）

**席位**：Team:测试:（agent `705a98cd`）  
**日期**：2026-09-22  
**Host**：https://p05113ef3.test.weline.com:9555/  
**账号**：e2e.customer@weline.local（已登录，未向用户索密）  
**Browser**：禁缓存 `Network.setCacheDisabled` + 抹 `navigator.webdriver`  
**基线**：pixel_id≥50066；marker `sitewide_1790051471`  
**查库**：`w_pixel` + `w_pixel_additional.total_event_data`

## 总判

**主链未完全 PASS。** PDP 入库 OK；`view_cart`/`begin_checkout` **仍空壳**（`items=[]`/`value=0`）；本波窗口内 **未新测到** `checkout_success` / `purchase` / `payment_success`（Buy now→`/q/...`→Pay now 后支付页关闭且无成功跳转）。

## 事件表

| 事件 | 结果 | pixel_id（代表） | URL / 证据 | 备注 |
|------|------|------------------|------------|------|
| view_item | **PASS** | **50176**（另 50201/50343） | `/product/...99e62ff2` | items+value+currency 齐；数据分析 PDP rework closed |
| add_to_cart | **PASS** | **50171**（另 50203） | 同上 PDP | 真实点击加购；payload 含 item_id=196、value=17.88 |
| view_cart | **FAIL（空壳）** | **50214**（另 50205） | `/cart` | 事件入库，但 `items=[]`、`value=0`；UI 常「Failed to load cart / empty」 |
| begin_checkout | **FAIL（空壳）** | **50228**（另 50330 等 recovery） | `/checkout` 或 `#payment-recovery` | `items=[]`、`value=0`；空车页仍发事件 |
| checkout_success | **FAIL（本波未复现）** | 历史 **50027** only | `/checkout/success?source=payment_return...` @12:15 | payload 曾有 `transaction_id`+items+value；**13:20+ 无新行** |
| purchase | **FAIL / 未观测** | — | — | 本机当日无 `purchase` 行 |
| payment_success | **FAIL / 未观测** | — | — | 本机当日无 `payment_success` 行 |

## 空壳抽检（additional）

| pixel_id | event | items | value | transaction_id |
|----------|-------|-------|-------|----------------|
| 50203 | add_to_cart | len=1 | 17.88 | null（可接受） |
| 50214 | view_cart | **[]** | **0** | null |
| 50228 | begin_checkout | **[]** | **0** | null |
| 50027 | checkout_success（历史） | len=1 | 50.22 | PAY20260922041344412870 |

## 通路阻断（技术证据）

1. **Cart UI**：登录态进 `/cart` 仍见 “Unable to load cart / Your cart is empty”；CDP 调 `Weline.Api.resource('cart')` 曾得 `capability_denied` / Frontend worker not allowed（页面点击加购像素可入库，但购物车读失败）。
2. **Checkout**：`/checkout` 常空车文案；`begin_checkout` 仍入库 → 空壳。
3. **支付**：PDP Buy now 填地址→Americas→Confirm payment→打开 `/q/a5145bc03a8873c221e04e65c8892415`（USD 26.67）→点 **Pay now** 后按钮 disabled，支付 tab 随后消失，**无** `/checkout/success` 或 `/payment/success` 跳转，库无新成功事件。

## rework_owner 建议

| 缺口 | rework_owner | 说明 |
|------|--------------|------|
| view_cart / begin_checkout 空壳 | **Team:数据分析:**（已 R2 `rework-param-ga4`） | 禁空壳入库/桥 GA4；有货时须 items+value |
| 购物车加载失败 / 结账空车 | **Team:项目经理:** 协调 Cart/Checkout | 影响有货 begin_checkout 真测 |
| Buy now / `/q/` Pay now 无成功回跳 | **Team:支付开发工程师:** `afe691fe` | 沙盒/假卡通路需可完成至 success |

## 交付地址

- [店面 Host](https://p05113ef3.test.weline.com:9555/)
- [PDP 196](https://p05113ef3.test.weline.com:9555/product/196)
- [Cart](https://p05113ef3.test.weline.com:9555/cart)
- [Checkout](https://p05113ef3.test.weline.com:9555/checkout)

本回合验收 Browser 于交付后关闭。
