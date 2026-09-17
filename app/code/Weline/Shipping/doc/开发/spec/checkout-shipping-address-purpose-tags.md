---
status: done
work_kind: feature
feature_slug: checkout-shipping-address-purpose-tags
module: Weline_Shipping
updated: 2026-09-16
---

# 结账地址 / 收货地址用途标签隔离

## 澄清记录

### 用户原话（摘要）

- 地址做标签；运输（收货）不要和结账地址混在一起。
- 收货侧新建不应立刻变成结账地址（否则一加地址结账簿马上多一条）。
- 「账单与收货相同」只管本单镜像，不负责同步地址簿。
- **真下单成功**后，本单用到的收货地址再转化为结账地址。
- **结账页手填新建并保存**（不用已有运输/收货簿）→ 保存后自动打结账标。
- 从收货簿点选已有地址 → 不打结账标（等下单成功再转化）。

### 澄清答复（2026-09-16，含二次纠偏）

1. 「运输地址」= **收货地址**（`DeliveryAddress`），**不是**店铺「发货地址」`ShippingAddress`。
2. 结账页手填新建：「同时用作收货地址」**默认勾选**；保存必打结账标。
3. 收货侧（账户 / Header / quick-add）「同时用作结账地址」**默认不勾选**（二次纠偏：禁止收货新建立刻进结账簿）。
4. **不提供取消标签 UI**；无摘标/去标入口（清空结账簿等运维路径除外）。
5. 文案用 **「收货地址」「结账地址」**。

### 需求纠偏

| 用户概念 | 落点 | 说明 |
|---|---|---|
| 结账地址 | `DeliveryAddress` + `purpose_checkout=1` | 结账页手填保存；或下单成功转化 |
| 收货地址 | `DeliveryAddress` + `purpose_receiving=1` | 账户「收货地址」、Header「配送至」 |
| 发货地址 | `ShippingAddress` | **本需求不改** |

---

## 目标 / 非目标

### 目标

- 结账列表/账单簿只含 `purpose_checkout=1`。
- 收货列表（账户/Header）只含 `purpose_receiving=1`。
- 结账页手填新建：必打结账标；勾选（默认开）则同时打收货标。
- 收货保存：必打收货标；仅当勾选「同时用作结账」才打结账标（默认不勾）。
- 点选已有地址：只改本单选中，不写结账标。
- 下单成功：对本单配送地址 `ensureCheckoutPurpose`（只加标不摘标）。
- 历史数据迁移后双标，行为不回退。
- 普通更新只加标不摘标。

### 非目标

- 不改 `ShippingAddress` / 航线 origin。
- 不做取消标签、不做标签编辑器。
- 不合并收货表与发货表。

---

## EARS

- WHEN 用户在结账页手填新建并保存且勾选「同时用作收货地址」 THEN 系统 SHALL 标记结账+收货。
- WHEN 用户在结账页手填新建并保存且未勾选「同时用作收货地址」 THEN 系统 SHALL 仅标记结账。
- WHEN 用户从地址簿点选已有地址用于本单 THEN 系统 SHALL NOT 仅因点选而新增 `purpose_checkout`。
- WHEN 用户在账户/Header 收货新建且未勾选「同时用作结账地址」 THEN 系统 SHALL 仅标记收货，结账/账单选择器 SHALL NOT 展示该地址。
- WHEN 用户在账户/Header 收货新建且勾选「同时用作结账地址」 THEN 系统 SHALL 双标。
- WHEN 订单提交成功且本单配送地址尚无结账标 THEN 系统 SHALL 为其加上 `purpose_checkout=1`。
- WHEN 用户在结账打开已存地址列表 / 账单地址簿 THEN 系统 SHALL 只展示 `purpose_checkout=1`。
- WHEN 用户在账户收货或 Header 配送列表打开地址簿 THEN 系统 SHALL 只展示 `purpose_receiving=1`。
- IF 历史地址无用途字段 THEN 迁移 SHALL 将既有行设为结账+收货双标。
- WHEN 用户更新地址且取消勾选次用途 THEN 系统 SHALL NOT 摘除已有用途标。

---

## 架构选型

- **机制**：`w_delivery_addresses.purpose_checkout` / `purpose_receiving`。
- **扩展点**：`DeliveryAddressService`（含 `ensureCheckoutPurpose*`）；`CheckoutDeliveryContextService`；结账/账户/Header UI；`submitV2` 成功后转化。
- **反模式**：禁止收货新建默认打结账标；禁止点选即写结账标；禁止账单与收货「相同」勾选同步地址簿。

---

## 实现落点

| 面 | 路径 |
|---|---|
| 模型/迁移 | `Model/DeliveryAddress.php`；`Setup/Upgrade.php` |
| 写标/过滤/转化 | `Service/DeliveryAddressService.php` |
| 结账上下文 | `Checkout/Service/CheckoutDeliveryContextService.php` |
| 下单成功转化 | `CheckoutQueryProvider::submitV2` |
| 结账 UI | `checkout-shipping-address.phtml` + `.js` |
| 账户 UI | `account.sidebar.content.phtml` |
| Header | `checkout-delivery-context` + `quick-add.phtml` |

---

## 验收映射

| 验收 | 类型 |
|---|---|
| 字段迁移 + 写标/转化规则 | UT |
| 收货默认不打结账；手填/下单转化 | UT + WB-OP |

## 状态

`status=done`
