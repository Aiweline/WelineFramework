# 后台生命周期阶段壳

Order 宿主按 `order_type` 扩展展示类型侧支付/履约阶段（与 `order_type` 徽章分离）。

## 边界

- 壳：`OrderBackendLifecycleStagePolicyInterface` + Registry + `OrderBackendLifecycleStageShell`。
- UI：`lifecycle_stage` assign；`status-flow` 次轨 `order-lifecycle-stage-flow`；编辑摘要 / 列表旁挂阶段徽章。
- `LifecycleStageView.actions`：可选阶段动作（path + params）；Order 模板通用 POST 渲染；业务归属模块填 actions。
- toc 无策略 → 不渲染。
- B2B tob：挂单 hang_status 轨（定金→商家确认→尾款→支付完成）；`awaiting_merchant_approval` 投影「通过审查 / 驳回」→ ControlCenter `approve-hang-order` / `reject-hang-order`（带 `order_id` 回跳编辑页）。

## 禁止

- 把 hang 码写入 `orders.status`。
- 把阶段文案塞进 `CommerceOrderTypeInterface` label。
- Order 壳直调 B2B Hang Service（动作只经 actions DTO + B2B 路由）。
