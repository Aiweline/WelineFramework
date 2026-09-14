# Spec：后台支付记录方式 icon + 状态四色芯片

## 背景
订单「支付与沟通」支付记录表：支付方式曾为纯文本 code（如 `paypal`），状态为灰色 badge 裸 `paid`，一眼不可辨。

## 验收标准（EARS）
- When 支付记录行存在已知支付方式，the system shall 展示品牌 icon（固定 20×20）与可读 `method_label`。
- When 状态为 paid/succeeded/captured，the system shall 展示绿色 `data-tone=success` 芯片与「已支付」，禁止裸 `paid`/`succeeded` 作为唯一信号。
- When 状态为 pending/failed/refunded，the system shall 分别使用 warning/danger/info 四色程度标记。
- While 渲染支付记录，the system shall 由 `Weline_Payment` 部件提供（禁止 Order 直读 Payment 表拼表）。

## UC
1. 店员打开订单编辑 → 支付与沟通 → 见支付记录。
2. 支付方式列可见 PayPal 等 icon；状态列为四色芯片。
