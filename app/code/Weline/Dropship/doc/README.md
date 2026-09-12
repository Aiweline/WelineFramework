# Weline_Dropship

万能货源代发壳：供应商经 Extends 自注入；CJ 为默认可选 Provider。与 `Weline_Affiliate`（推广佣金）分模块。

## 入口

`prepare_project` → `resolve_task_context`。菜单：**货源代发**。

## 定位

- 模块：`Weline_Dropship`
- 目录：`app/code/Weline/Dropship`
- 扩展点：`extends/module/Weline_Dropship/DropshipProvider`
- 默认供应商：`Weline_CjDropshipping`（optional）

## 文档

| 文档 | 说明 |
|------|------|
| [需求.md](需求.md) | 目标与边界 |
| [功能现状.md](功能现状.md) | 已实现能力 |
| [开发日志.md](开发日志.md) | 变更记录 |
| [dropship-shell.md](dropship-shell.md) | 壳边界与 Provider 同构硬规定 |
| [provider-development.md](provider-development.md) | 如何新增供应商 |
| [extends.md](extends.md) | Extends 规约 |

## 壳表

`dropship_listing` / `dropship_push_outbox` / `dropship_fulfillment` / `dropship_scope_warehouse_map` / `dropship_webhook_inbox` / `dropship_order_line` / `dropship_channel`
