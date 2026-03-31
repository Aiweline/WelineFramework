# E2E 收口结果

更新时间: 2026-03-31

## Requirement 覆盖与验收结论

| RequirementID | AC | 状态 | 证据命令 | 证据日志 |
|---|---|---|---|---|
| E2E-CORE-001 | Core Frontend 分组（checkout/api-cart/filters/order-detail）全部通过 | PASS | `cd E:\WelineFramework\DEV-workspace\tests\e2e; $env:CI='1'; node start.js specs/frontend/weshop-checkout-core-actions.spec.js specs/frontend/weshop-api-cart.spec.js specs/frontend/weshop-filters.spec.js specs/frontend/weshop-order-detail-fallback.spec.js --workers=1` | `tests/e2e/results/e2e-core-suite.log` |
| E2E-BLOCKER-001 | Blocker 分组（cart-auth-flow + catalog-filter）应稳定通过 | PASS | `cd E:\WelineFramework\DEV-workspace\tests\e2e; $env:CI='1'; $env:PLAYWRIGHT_SKIP_PREFLIGHT='1'; node start.js specs/frontend/weshop-cart-auth-flow.spec.js specs/frontend/weshop-catalog-filter.spec.js --workers=1` | `tests/e2e/results/e2e-blocker-suite.log` |
| E2E-BLOCKER-ISO-001 | cart-auth-flow 独占重跑应全通过 | PASS | `cd E:\WelineFramework\DEV-workspace\tests\e2e; $env:CI='1'; $env:PLAYWRIGHT_SKIP_PREFLIGHT='1'; node start.js specs/frontend/weshop-cart-auth-flow.spec.js --workers=1` | `tests/e2e/results/e2e-cart-auth-isolated.log` |
| E2E-GIFTCARD-001 | GiftCard Backend smoke 全部通过 | PASS | `cd E:\WelineFramework\DEV-workspace\tests\e2e; $env:CI='1'; node start.js specs/backend/WeShop_GiftCard-smoke-backend.spec.js --workers=1` | `tests/e2e/results/e2e-giftcard-suite.log` |

## 结论

- 当前可作为回归护栏的稳定分组：Core、GiftCard。
- `cart-auth-flow` 独占与 Blocker 分组均已全绿；当前唯一遗留为标准 preflight 受 `PageBuilder` ACL 校验阻断。
