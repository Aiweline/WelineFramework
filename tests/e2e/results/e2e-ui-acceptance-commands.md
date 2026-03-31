# UI 验收命令（老板可直接执行）

更新时间: 2026-03-31（与 `e2e-multi-agent-dashboard.md` 一致）

## 当前状态（与看板一致）

- Blocker 独占：✅ `weshop-cart-auth-flow.spec.js`（2 通过 / 0 失败，使用 skip preflight）
- Blocker 分组：✅ 已通过（5 通过 / 0 失败，使用 skip preflight）
- Core 分组：✅ 完成（12 通过 / 0 失败）
- GiftCard 分组：✅ 完成（2 通过 / 0 失败）

## 1) Blocker 独占验收（优先）

```powershell
cd E:\WelineFramework\DEV-workspace\tests\e2e
$env:CI='1'; $env:PLAYWRIGHT_SKIP_PREFLIGHT='1'; node start.js specs/frontend/weshop-cart-auth-flow.spec.js --workers=1
```

## 2) Blocker 分组验收

```powershell
cd E:\WelineFramework\DEV-workspace\tests\e2e
$env:CI='1'; node start.js specs/frontend/weshop-cart-auth-flow.spec.js specs/frontend/weshop-catalog-filter.spec.js --workers=1
```

## 3) Core 回归护栏

```powershell
cd E:\WelineFramework\DEV-workspace\tests\e2e
$env:CI='1'; node start.js specs/frontend/weshop-checkout-core-actions.spec.js specs/frontend/weshop-api-cart.spec.js specs/frontend/weshop-filters.spec.js specs/frontend/weshop-order-detail-fallback.spec.js --workers=1
```

## 4) GiftCard 后台回归

```powershell
cd E:\WelineFramework\DEV-workspace\tests\e2e
$env:CI='1'; node start.js specs/backend/WeShop_GiftCard-smoke-backend.spec.js --workers=1
```

## 5) 结果日志建议

- 独占 blocker：`tests/e2e/results/e2e-cart-auth-isolated.log`
- blocker 分组：`tests/e2e/results/e2e-blocker-suite.log`
- core 分组：`tests/e2e/results/e2e-core-suite.log`
- giftcard 分组：`tests/e2e/results/e2e-giftcard-suite.log`
