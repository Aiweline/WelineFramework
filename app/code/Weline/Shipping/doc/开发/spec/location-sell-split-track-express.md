---
status: done
work_kind: feature
feature_slug: location-sell-split-track-express
module: Weline_Shipping
updated: 2026-10-09
session_path: ../../../../../../dev/session/location-sell-split-track-express.md
clarify_status: assumed-defaults
---

# 地点售卖开启后：拆单 / 物流追踪 / 跨国闸 / 快捷结账回跳

## 澄清记录

### 用户原话（摘要）

- 发货拆单，拆单物流追踪实测。
- 开启地点售卖后，跨国产品在购物车和结账中的表现要验证。
- 快捷结账的返回页面提示等要验证。
- 要验收和需求用例闭环；改完后抽象规则：用户任何需求都要用例闭环验收。

### 澄清答复（本回合假设，未另开问答）

| # | 假设 | 依据 |
|---|------|------|
| A1 | 验收站 = 邻里杂货铺 `grocery`（website_id=544，Host=`https://grocery.test.weline.com`） | 既有地点售卖实测与站柜 |
| A2 | 地点售卖双开：`location_sell_filter=1` + `sell_only_fulfillment_countries=1` | `enable-location-sell.php` |
| A3 | 可售履约国以本站仓国贡献为准（当前 CN） | LocationSellGate + Dropship/Inventory contributor |
| A4 | 拆单键权威 = Inventory `FulfillmentSplitPlan` → `split_key=wh:{id}`；Dropship `local_warehouse_id` 须能偏好进仓 | CheckoutGroupSubmitService |
| A5 | 拆单物流追踪 = 订单追踪载荷含 `shipments[]`（多运单） | OrderTrackingService |
| A6 | 快捷结账回跳：未付改址/禁运国失败时店面提示「请修改收货地址」类文案 | ExpressUnpaidOrderAmend / CheckoutDeliveryContext |

### 隐形需求

- Dropship listing 的 `local_warehouse_id` 若未参与分仓，多仓商品无法真实拆单（仅落默认仓）。
- Cookie 配送国须经 `Cookie::get`（已修 DestinationCountryReader）。
- 用例闭环规则须从「仅 feature」收紧为「用户工程需求默认 UC 闭环」。

### 需求纠偏

| 用户概念 | 落点 | 说明 |
|---|---|---|
| 发货拆单 | Inventory 分仓 + Checkout `bucketBySplitKey` | 非另开拆单引擎 |
| 拆单物流追踪 | OrderShipment + OrderTrackingService.`shipments` | Dropship webhook 经 Bridge 幂等写入 |
| 跨国产品表现 | LocationSellGate 加购/合车/结账 | 提示改地址，禁硬失败无文案 |
| 快捷结账回跳提示 | Express 未付 amend / 店面 fault | 国不可履约 → 改地址文案 |

---

## 目标 / 非目标

### 目标

- 地点售卖开启后：配送国 ∉ 可履约国 → 加购/写国/快捷改址失败并提示改地址。
- 同履约国内多仓商品提交结账 → 按 `wh:{id}` 拆成多子单。
- 子单有运单后追踪接口返回 `shipments`（可多条）。
- 快捷结账回跳 / 未付改址遇地点闸失败时，店面可见「请修改收货地址」类提示。
- 规格全部 UC 经 `covers_use_cases` 闭环验收；规则抽象写入硬门禁。

### 非目标

- 不改生产机；不启 Ollama。
- 不发明第二套拆单键语义。
- 不做完整 PayPal 沙箱支付（可用 amend/fault 探针 + Browser 文案闭环）。

---

## EARS

- WHEN grocery 地点售卖双开且配送国为可履约国（CN） THEN 系统 SHALL 允许同源国 offer 加购并进入购物车/结账。
- WHEN 配送国为不可履约国（如 US） THEN 系统 SHALL 拒绝加购或写国，并提示修改收货/配送地址。
- WHEN 结账行经分仓得到 ≥2 个不同 `split_key` THEN 系统 SHALL 提交为多笔子单（各带自身 `split_key`）。
- WHEN Dropship listing 声明 `local_warehouse_id` 且该仓对本 Store 已授权 THEN 分仓 SHALL 优先该仓（在配额允许时），不得一律落到默认仓而抹掉拆单。
- WHEN 订单存在 ≥1 条 OrderShipment THEN 追踪载荷 SHALL 包含 `shipments` 数组（可含多运单）。
- WHEN 快捷结账未付改址的目标国不可履约 THEN 系统 SHALL 返回失败并带「请修改收货地址」类 message（店面可见）。

---

## 架构选型

- **mechanism**：LocationSellGate（交易闸）+ FulfillmentSplitPlan（分仓键）+ Dropship preferred warehouse  enrichment + OrderTrackingService.`shipments` + ExpressUnpaidOrderAmend 地点断言。
- **owning_module**：Shipping（闸/规格归属协调）；Inventory（分仓）；Dropship（仓偏好贡献）；Checkout（提交拆桶/快捷）；Order（追踪/运单）。
- **reuse**：既有 Gate、Bridge、CookieScope、enable-location-sell。
- **invent**：Dropship→行级 `preferred_warehouse_id`  enrichment（最小）；分仓优先读该字段。
- **not_to_do**：在 Checkout/Runtime 硬编码 warehouse_id/sku；用假内存数据冒充拆单 PASS；把站级种子写进 `app/code`。

---

## 用例

### UC-LS-CN-OK（主成功）

- **前置**：location-sell 双开；配送国=CN；CN 履约 offer。
- **步骤**：设配送国 CN → 加购 → 开购物车 → 进结账。
- **期望**：加购成功；车/结账无地点闸阻断；可报价。
- **acceptance（closing）**：`acc-wb-ls-cn`（真机）；辅助 `acc-rt-ls-cn`

### UC-LS-US-BLOCK（异常）

- **前置**：同上；配送国切 US（或写国 US）。
- **步骤**：尝试加购 CN-only offer / 或写国 US。
- **期望**：失败；文案含「请修改收货地址」或「可发货国家」语义。
- **acceptance（closing）**：`acc-wb-ls-us`（真机）；辅助 `acc-rt-ls-us`

### UC-SPLIT-MULTI-WH（主成功）

- **前置**：两 offer 偏好仓分别为 51 与 96（均授权）；CN 配送。
- **步骤**：两行加购 → freeze/submit（或等价提交）。
- **期望**：产生 ≥2 子单，`split_key` 分别为 `wh:51` 与 `wh:96`（或两行不同 wh）。
- **acceptance（closing）**：`acc-wb-split-cart`（真机车→结账）；辅助 `acc-rt-split` / `acc-unit-split-pref`

### UC-TRACK-SHIPMENTS（主成功）

- **前置**：子单存在；写入 ≥1 OrderShipment（Bridge 或履约）。
- **步骤**：调 OrderTrackingService / 店面追踪。
- **期望**：载荷含非空 `shipments`。
- **acceptance（closing）**：`acc-wb-track`（真机追踪页）；辅助 `acc-rt-track`

### UC-EXPRESS-RETURN-MSG（异常）

- **前置**：未付订单；地点闸开启。
- **步骤**：快捷回跳/amend 将地址改为不可履约国。
- **期望**：amend/fault 失败 message 含改地址语义；店面提示可见（若可达回跳页）。
- **acceptance（closing）**：`acc-wb-express`（真机回跳/故障页可见改地址文案）；辅助 `acc-rt-express`

---

## acceptance 映射（计划）

> **闭环一定是实际真机测试闭环**：每条 UC 的 **closing** 项必须是 `type=browser` WB-OP（真机点选/截图）和/或正式 Playwright e2e。  
> `runtime`/`unit` 仅辅助，**不得**单独把 UC 标为 passed。

| id | type | covers_use_cases | closing? | 说明 |
|----|------|------------------|----------|------|
| acc-wb-ls-cn | browser | UC-LS-CN-OK | **yes** | 真机：设国 CN→加购→车→结账 |
| acc-wb-ls-us | browser | UC-LS-US-BLOCK | **yes** | 真机：切 US 或写国可见改地址提示 |
| acc-wb-split-cart | browser | UC-SPLIT-MULTI-WH | **yes** | 真机：双仓两商品进车→结账入口可见 |
| acc-wb-track | browser | UC-TRACK-SHIPMENTS | **yes** | 真机：订单跟踪页可见运单/追踪信息 |
| acc-wb-express | browser | UC-EXPRESS-RETURN-MSG | **yes** | 真机：回跳/故障页可见改地址文案（可达则必测） |
| acc-rt-ls-cn | runtime | UC-LS-CN-OK | no | 辅助闸探针 |
| acc-rt-ls-us | runtime | UC-LS-US-BLOCK | no | 辅助 US 拦截探针 |
| acc-rt-split | runtime | UC-SPLIT-MULTI-WH | no | 辅助 split_key 探针 |
| acc-rt-track | runtime | UC-TRACK-SHIPMENTS | no | 辅助 shipments 探针 |
| acc-rt-express | runtime | UC-EXPRESS-RETURN-MSG | no | 辅助 amend 文案探针 |
| acc-unit-split-pref | unit | UC-SPLIT-MULTI-WH | no | 分仓偏好契约 |
