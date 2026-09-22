# channel — rework-param-ga4

## msg-1 | 2026-09-22T13:21:00+08:00 | from:项目经理 | to:数据分析 | thread:rework-param-ga4 | kind:escalate
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**rework_owner = Team:数据分析:**（R2-param-shell）

父会话对照字典 required_params + GA4 推荐电商参查库审计 FAIL：

1. **view_cart**（50205/50214）：有 currency=USD，**缺 items**，value=0 → 禁空壳入库/桥 GA4
2. **begin_checkout**：大量（含 #payment-recovery）currency=USD、value=0、**items=[]** → 空车/恢复页禁发或硬校验丢弃
3. **page_view**：additional 近空，缺 **page_location / page_title**（字典 required）
4. **search**（50063）：缺 **search_term**

允许：主表已有字段映射进 additional/GA4 payload；禁止假造 INSERT；禁止旁路 dataLayer。

审计表：`doc/开发/team/event-lifecycle-closeout/ga4-param-audit.md`
修完 channel handoff + 代表 pixel_id（合规新行）→ PM 汇审后测试复测。
---

## msg-2 | 2026-09-22T13:21:00+08:00 | from:项目经理 | to:测试 | thread:rework-param-ga4 | kind:handoff
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**R2-purchase-pathway**：本波窗口内 checkout_success / payment_success / purchase **未新测到**。
请真实 Browser（抹 webdriver + 禁缓存）走：商品→加购→购物车→结账→结账成功→支付成功（沙盒/本机支付通路可）。
每段查 `w_pixel`/`w_pixel_additional`；purchase 族须 transaction_id+value+currency+items。
若支付壳阻断 → channel escalate `@支付开发工程师`。
账号：e2e.customer@weline.local / E2eTest!234；Host https://p05113ef3.test.weline.com:9555/
---

## msg-3 | 2026-09-22T13:21:00+08:00 | from:项目经理 | to:支付开发工程师 | thread:rework-param-ga4 | kind:handoff
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
待命：测试席若卡在支付/沙盒 return，本席保驾本机支付通路，使 payment_success / purchase 可真测。勿代写 Visitor 像素 runtime。
---

## msg-4 | 2026-09-22T13:25:08+08:00 | from:支付开发工程师 | to:测试 | thread:rework-param-ga4 | kind:reply
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**待命探查完成 · 当前无支付 escalate 阻断**（result=waiting_peer）

### 本机 Host（交付）
- 店面根：`https://p05113ef3.test.weline.com:9555/`
- 结账：`https://p05113ef3.test.weline.com:9555/checkout`（探活 HTTP 200）
- 购物车：`https://p05113ef3.test.weline.com:9555/cart`（200）
- 推荐 PDP（续付 runner 同源）：`https://p05113ef3.test.weline.com:9555/product/yue-ya-ni-shang-yue-ya-ni-shang-zhang-an-yi-yuan-chuang-zheng-pin-tang-zhi-4d375d6d/`（200）
- 壳结果页：`/payment/success` · `/payment/handoff` · `/payment/handoff/status`（均 200）；业务成功页：`/checkout/success`（302 无参属正常）

### 支付方式（本机可读状态）
| method_code | SystemConfig enabled | method.is_active | 建议 |
|---|---|---|---|
| **fake_card**（本地测试支付） | 1 · env=sandbox | **1** | **R2 purchase 首选**：结账选「本地测试支付」→ Fake result=**Paid** → 可达 payment_success/purchase |
| paypal | 1（sandbox 凭据已配） | 0（表） | 今日已有 success 证据 `PAY20260922041344412870`；若结账列表不可选再 escalate 本席启活 |
| stripe | — | 0 | 本波勿用 |

**fake_card 约束**：`supported_currencies=["CNY"]`、`supported_countries=["CN","XZ"]`。若车币种/国家不在范围导致方法不可见 → 切 PayPal 沙盒或 escalate 本席扩币种（勿问用户密码）。

### 继续支付入口
- 形态：`/checkout#payment-recovery?quote_token=…&idempotency_key=…&payment_method=…&order_uuid=…&recoverable=1`（或成功页 `data-continue-pay` CTA）
- 现成未付样例（勿当像素 DB 假造；仅通路参考）：`order_uuid=7ed73082-4bd9-4599-98ab-067e8a583b18`（fake_card / pending）；`a6308fdd-1675-4181-b4a5-67c58289c782`（fake_card / pending）
- 真造续付链（可选）：`node app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js` → 输出 `continue_pay_url`

### 推荐真测路径（支付席保驾）
1. 登录 `e2e.customer@weline.local`（PM 已给）
2. PDP 加购 → `/cart` → `/checkout`
3. 选 **fake_card** → Fake result=Paid → 提交
4. 落 `/checkout/success` 或 `/payment/success` 后查 `w_pixel` purchase 族（transaction_id+value+currency+items）
5. 若卡在网关 return / 方法不可见 / resumePaymentV2 → **channel escalate @支付开发工程师**（本席立刻修）

paths_changed: 本消息仅 channel；未改支付代码。  
verdict: pathway_ready_standby
---

## msg-5 | 2026-09-22T13:26:00+08:00 | from:测试 | to:项目经理 | thread:rework-param-ga4 | kind:handoff
agent_id: 705a98cd-…
body:
**R2 复测纪要（并行席）** → 全文：`acceptance-sitewide-funnel.md`

| 事件 | 判 | pixel_id |
|------|----|----------|
| view_item | PASS | 50176 / 50343 |
| add_to_cart | PASS | 50171 / 50203 |
| view_cart | **FAIL 空壳** items=[] value=0 | 50214 |
| begin_checkout | **FAIL 空壳** items=[] value=0 | 50228 |
| checkout_success / purchase / payment_success | **FAIL 本波未新测到** | 仅历史 checkout_success=50027 |

阻断：
1. 空壳 → 继续 **数据分析** R2-param-shell
2. `/cart` 加载失败 / 结账空车 → **项目经理** 协调
3. Buy now（US/USD）→`/q/...` Pay now 后 tab 消失、无 success 跳转；支付席指出 **fake_card 仅 CNY+CN**——本波未走到 fake_card Paid。建议 **支付开发工程师 afe691fe** 保驾：结账可见本地测试支付（或扩币种）使 success 可真测。

Browser 已关。
---

## msg-6 | 2026-09-22T13:27:30+08:00 | from:项目经理 | to:* | thread:rework-param-ga4 | kind:escalate
agent_id: 5118b63e-7b86-43df-b88a-6295b2876cc2
body:
**PM 汇审：主链未 PASS（采纳 705a98cd 纪要）。立刻并行 rework：**

| id | owner | 动作 |
|----|-------|------|
| R2a | **数据分析 a55f4e78** | 禁空壳 `view_cart`/`begin_checkout`（items=[]/value=0 不得入库或桥 GA4）；查是否像素在 cart 读失败时仍误发 |
| R2b | **支付 afe691fe + 测试 281e1a4f** | **强制 CNY+CN**（或切换站店渠/币种）使 fake_card 可见→Fake **Paid**→`/checkout/success` 或 `/payment/success`；查库写新 pixel_id |
| R2c | **后端（Cart）** | `/cart` 加载失败：`Weline.Api.resource('cart')` → `capability_denied` / Frontend worker not allowed → 空车页；修可读车后再供真 begin_checkout |

证据：`acceptance-sitewide-funnel.md`；空壳 50214/50228；成功页本波无新行。
---

## msg-7 | 2026-09-22T13:35:00+08:00 | from:数据分析 | to:项目经理 | thread:rework-param-ga4 | kind:handoff
agent_id: a55f4e78-938c-4214-802e-dc52b8f28671
body:
**R2a result=closed · verdict=pass**

### 根因
1. 路径级 `view_cart`/`begin_checkout` 在 cart DOM 未就绪（含 cart API 失败空页）时仍 track → items=[]/value=0 空壳入库。
2. `__getCartItems` 不认 `weline-cart-shell__line`。
3. `page_view` 被标被动事件 → **不写** `w_pixel_additional`，缺 page_location/title。
4. `search` 未映射 `search_term`；`#payment-recovery` 仍发 begin_checkout。

### 修复
- **客户端**：`__evaluateRequiredParamGate` — 缺 items/search_term 或 payment-recovery → `track` 返回 null（不入库、不桥 GA4）；cart 选择器扩写；空车短重试后放弃。
- **服务端**：`PixelEventService::skipReasonForIncompleteRequiredParams` 双闸；`PixelEventPersistenceService` 在有 url/page_* 时仍写 page_view additional。
- 版本：`pixel.js` `2026.09.22-param-shell1` / bootstrap `20260922-param-shell1`；模块 **1.1.39**。
- 契约：`PixelRequiredParamGateContractTest`。

### 自测证据（本机库，基线>50356）
| 项 | 结果 | pixel_id |
|----|------|----------|
| 打开空 `/cart` | **无**空壳 `view_cart` 新行 | — |
| `#payment-recovery` | **无** `begin_checkout` 新行 | — |
| `page_view` additional 含 page_location/title | **PASS** | **50359**（url=/cart） |
| 空壳计数 `view_cart`/`begin_checkout`（>50356 且 items 空+value=0） | **0** | — |

有货合规 `view_cart` 待 cart 可读（R2c）后由测试复测；本席闸已禁空壳。勿 resume 测试。
---
