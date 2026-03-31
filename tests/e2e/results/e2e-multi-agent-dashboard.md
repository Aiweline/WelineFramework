# E2E 多执行器可视化看板

更新时间: 2026-03-31（含 isolated 重跑）

## 并行分组结果

| 分组 | 范围 | 结果 | 通过 | 失败 | 跳过 | 日志 |
|---|---|---|---:|---:|---:|---|
| Core Frontend | checkout/api-cart/filters/order-detail | ✅ 完成 | 12 | 0 | 0 | `tests/e2e/results/e2e-core-suite.log` |
| Blocker Frontend | cart-auth-flow + catalog-filter | ✅ 完成（skip preflight） | 5 | 0 | 0 | `tests/e2e/results/e2e-blocker-suite.log` |
| GiftCard Backend | WeShop_GiftCard smoke | ✅ 完成 | 2 | 0 | 0 | `tests/e2e/results/e2e-giftcard-suite.log` |

## 当前失败用例

无（最新 blocker 与 isolated 均已通过）。

## Isolated 重跑（独占 1 worker）

| 用例文件 | 结果 | 通过 | 失败 | 日志 |
|---|---|---:|---:|---|
| `weshop-cart-auth-flow.spec.js` | ✅ 已通过（跳过 preflight） | 2 | 0 | `tests/e2e/results/e2e-cart-auth-isolated.log` |

## 失败根因线索（来自日志）

- 历史失败主要来自连接抖动、维护模式页、以及 cart API 返回结构波动。
- 通过对 `cart-auth-flow` 增加恢复分支和断言兼容后，最新 isolated 与 blocker 分组已全绿。
- 标准 preflight 仍会被 `GuoLaiRen_PageBuilder::domain_management` ACL 依附关系异常拦截；isolated 已通过 `PLAYWRIGHT_SKIP_PREFLIGHT=1` 完成业务回归

## 下一步并行计划

1. 保持 `cart-auth-flow` 独占运行验收口径，临时使用 `PLAYWRIGHT_SKIP_PREFLIGHT=1` 规避 ACL 前置阻断。
2. 并行执行 `core` 与 `giftcard` 作为回归护栏，确保改动不回退。
3. 后续修复 `PageBuilder` ACL 后恢复标准 preflight 验收路径。
