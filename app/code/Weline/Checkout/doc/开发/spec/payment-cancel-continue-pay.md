# 规格：支付取消/失败后继续支付与尝试历史

## 分类

- work_kind: feature
- FE/BE: FE+BE（Checkout 取消落地 / 完整结账续付；Order 账户 CTA；Payment 后台记录合并；CheckoutType SPI）
- ui_skill_decision: participate（账户订单卡 CTA；取消页主 CTA；结账续付 chrome / 多桶切换器）
- plan_complexity: complex

## 范围（已确认）

| 编号 | 内容 |
|------|------|
| P0 | 支付取消/失败后不得引导至空 `/checkout`；主 CTA「继续支付」落同一 `/checkout`，**adopt 续付绑定 + 完整结账表单**，经 `resumePaymentV2` 续付；禁止孤立 recovery 双卡作主路径 |
| P0′ | 历次支付尝试记全：库层 Transaction 不删；会话追加历史；后台订单支付记录 Transaction∪Attempt 合并展示 |
| P1 | 账户未付订单卡显示「继续支付」；已付+待发货保持「查看详情」 |
| P2 | 结账 **Type** 可模块注册（本迭代 `standard`/`tob`）；**继续支付不是 Type**，是挂在订单原 Type session 桶上的支付绑定；切换器仅在 ≥2 个 session 桶时出现 |

## 关键区分（硬）

| 概念 | 定义 |
|------|------|
| Checkout Type | 结账流程形态（零售 `standard`↔`toc`、批发 `tob`…），模块 Provider 注册 |
| 继续支付 | 支付域绑定：`payment_mode=continue_pay` + `order_uuid`；Type 仍为订单原 Type |

禁止将 `continue_pay` 注册为与 `standard`/`tob` 并列的 CheckoutType。

## EARS

- When 买家在网关取消或支付失败且订单仍未付，系统 shall 落地取消确认页，主 CTA 为「继续支付」并指向可恢复支付链（含 `quote_token` / `idempotency_key` / `order_uuid` / `payment_method`），禁止裸链 `/checkout`。
- When 买家点击「继续支付」或账户侧同名 CTA，系统 shall 打开同一套店面 `/checkout` 完整结账体验，将订单对应 `CheckoutSession` **临时挂载**为续付绑定（按订单原 Type），并经 `resumePaymentV2` 新建支付尝试，不得重新 `submitV2` 建单。
- When 续付已挂载，系统 shall **not** 以「购物车为空」空态或孤立 recovery 双卡作为主路径主视觉；shall 基于订单/session 快照渲染完整结账所需内容。
- When 续付中买家修改收货地址，系统 shall 写回未付订单后再允许 `resumePaymentV2`。
- When 店面结账页存在 **≥2 个** session 桶，系统 shall 展示「当前命中 + ▾」切换器；仅 1 个桶时 shall **not** 展示切换 tab。
- When 列出切换候选项，系统 shall 仅展示**已有 session 桶**（∩ Type Provider 可用），shall **not** 因已注册 Type 常驻塞满下拉。
- When 续付桶激活，系统 shall 展示醒目「继续支付」chrome；主 CTA 走 `resumePaymentV2`。
- When 该笔订单支付成功或续付绑定被释放，系统 shall 清除续付桶并恢复活跃指针到剩余桶（若仅剩单桶则隐藏切换器）。
- When 某次支付进入失败/取消终态后再次发起支付，系统 shall 保留上一笔 `PaymentTransaction` 终态行，并追加会话侧 `payment_attempt_history`；不得覆盖/删除历史尝试。
- When 后台打开订单支付记录，系统 shall 合并展示 Attempt 与 Transaction（含 failed），禁止「有成功 Attempt 则隐藏失败 Transaction」。
- When 账户订单组状态为待支付（`pending`），系统 shall 展示「继续支付」链；已付/取消不下发该 CTA。
- When Express 取消已 `abandon` 订单，系统 shall 引导回购物车/商品，不得展示「继续支付」假闭环。
- When 结账 Type 由模块注册，系统 shall 经 `CheckoutTypeProviderRegistry` 聚合；Checkout 壳 shall **not** 硬编码业务 Type 枚举。虚拟/下载 Type 本迭代不实现。

## 用例（可执行 · 冻结）

### UC1 网关取消 → 继续支付（完整结账）

1. 店面结账 `submitV2` 建单并跳转支付。
2. 买家在网关取消，回跳 `/checkout/success?outcome=cancel&order_uuid=…&checkout_token=…`。
3. 主 CTA 文案为「继续支付」，href 含 `/checkout` 与 `#payment-recovery?`（含 quote/idempotency/order_uuid/payment_method）——URL 形态兼容；语义为 adopt 入口。
4. 点击后结账页 **adopt 续付绑定**，呈现完整结账主结构（地址可交互）+ 续付 chrome；可触发改址写回与 `resumePaymentV2`。
5. 断言：**不得**以空车横幅 + 孤立 recovery 双卡为主视觉；购物车可空。

### UC2 续付新建尝试且历史保留

1. 同订单完成 UC1 或模拟一次 failed Transaction。
2. 再 `resumePaymentV2` 成功创建第二笔 Transaction。
3. 按 `order_id` 查询 `weline_payment_transaction`：至少 2 行；首行仍为 failed（或终态），次行 pending/success。
4. 后台订单支付记录列表含两行（或等价合并后 ≥2）。

### UC3 账户未付继续支付

1. 登录顾客打开 `/customer/account/index#orders`，存在 `pending` 组。
2. 订单卡可见 `data-continue-pay`「继续支付」链接；已付组无此链接。
3. 点击进入可恢复支付链（同 P0 URL 契约 → 完整结账 adopt）。

### UC4 Express 已取消订单

1. Express 取消触发 abandon → 订单 cancelled。
2. 取消页不展示「继续支付」主 CTA（或降级为继续购物/回车）。

### UC5 多桶切换器显隐

1. 仅传统结账单桶：无 Type 切换器。
2. Adopt 续付后若另有 normal 桶：出现「继续支付」↔「零售/批发」切换；切桶不丢另一桶。
3. 付完/release 后仅剩单桶：切换器消失。

### UC6 tob 未付单续付

1. 批发订单未付，点继续支付。
2. 挂载桶 `type_code=tob` + `payment_mode=continue_pay`；流程能力按 tob Provider；提交仍 `resumePaymentV2`。

## 非目标

- 不开启 PaymentFacadeV2 Intent 编排 cutover。
- 不延迟「建单后清车」（订单已存在；用续付绑定续付）。
- 不在账户列表展示完整支付时间线（审计看后台；前台仅 CTA）。
- 本迭代不实现虚拟/下载 CheckoutType Provider。
- 不把继续支付注册为独立 CheckoutType。

## 契约摘要

- Continue pay URL（兼容）：`/checkout#payment-recovery?quote_token=&idempotency_key=&payment_method=&order_uuid=&outcome=failed&recoverable=1`（可选 `checkout_group_uuid`）。落地 = adopt + 完整结账，非 recovery 岛主 UI。
- Query：`adoptContinuePaySession` / `releaseContinuePaySession` / `amendUnpaidCheckoutAddress`；支付重试仍 `resumePaymentV2`。
- Session 桶：`bucket_id` + `type_code` + `payment_mode`；同会话最多一个续付桶；`standard`↔`toc`，`tob`↔`tob`。
- 权威历史：`weline_payment_transaction`；会话 `payment_attempt_history` 为门闩旁路审计；`payment_result` 仍为最新态指针。
- 账户 CTA：仅 `status=pending`（Presenter 有效状态）。
