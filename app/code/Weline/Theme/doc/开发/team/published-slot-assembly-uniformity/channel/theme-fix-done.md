# Team:主题开发工程师: 施工完成 — published zero-fill 窄安全网（DoD 返工）

> work_mode：`theme_module_runtime`  
> 通道：`pm-arrange-fix.md`  
> notify_pm: **true** @项目经理：本席已交付/上报，请检查并更新 SESSION

---

## 结论

DoD 未过根因：**wave8-8s5 把 published `fill` / `fillRequiredDefaultsOnShell` / LayoutSlot 一律 `+skip_fill_solidified`**，切断了本席上一波的占位安全网。  
`/products` 在 Host 走 reactive（无 `theme-published-slot`）时，占位被 strip 后原样出站；`/categories` 常走 published 投影故偶发 PASS。

本波在 **8s5 整壳固化口径上恢复窄例外**：仅当壳仍有 `slot-placeholder` 或 filters 的 `data-placeholder` 时才 `healPublishedPlaceholderShell`；已固化壳仍 skip fill。

本机 `server:reload` 后连续抽检：`/products` 与 `/categories` **同级 PASS**。

---

## Miss 路径（返工诊断）

| 步骤 | 8s5 后行为 | 结果 |
|---|---|---|
| Host `useReactiveMarkers` | products 瞬时/缺 bake → true | 壳留 `list-filters` 占位，无 `theme-published-slot` |
| LayoutSlot force zero-fill | **无条件** `+skip_fill_solidified` + strip | **跳过 fill** |
| `SlotFiller::fill` | published **硬 return** | 即使被调用也 no-op |
| `fillRequiredDefaultsOnShell` | published **硬 return** | overlay 不到 |
| 出站 | 占位仍在 | `/products` DoD FAIL |

（CLI 已证实体 `b5e7a8f02e3b88a9/r279` 可渲 `storefront-filters-panel`——不是 Filters 部件问题。）

---

## 改文件（本波）

| 文件 | 变更 |
|---|---|
| `Observer/LayoutSlotRenderer.php` | 占位 → `+safety_net_fill` + `healPublishedPlaceholderShell`；否则 `+skip_fill_solidified`；`resolveSafetyNetPageType` |
| `Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php` | published fill/overlay 占位例外；`healPublishedPlaceholderShell`；splice 安全网全区域替换 |
| `Service/LayoutEntity/ThemeLayoutEntityPublishedSlotHost.php` | `shellNeedsRuntimeSafetyNetFill` 收窄（filters / `slot-placeholder`） |
| `Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php` | 无 `<!--@weline-slot-->` 但有 `data-slot-id`/`data-wslot` 仍可 overlay |
| 契约 UT | ForcedZeroFill / SolidifiedShell / ZeroRuntime 对齐 |
| `etc/module.php` | `2.2.581` → `2.2.582` |
| `doc/开发日志.md` | 一行 |

**未改**：Weline_Filters 部件声明/模板；未逐布局打补丁。

---

## UT

```
php vendor/bin/phpunit \
  app/code/Weline/Theme/test/Unit/LayoutEntity/PublishedStorefrontForcedZeroFillContractTest.php \
  app/code/Weline/Theme/test/Unit/LayoutEntity/PublishedStorefrontSolidifiedShellContractTest.php \
  app/code/Weline/Theme/test/Unit/LayoutEntity/PublishedStorefrontZeroRuntimeSlotFillContractTest.php \
  app/code/Weline/Theme/test/Unit/LayoutEntity/PublishedStorefrontZeroDataWslotContractTest.php
```

结果：**19 tests / 124 assertions OK**。

---

## curl 验收（`server:reload` 后 · 连续 5 次）

Host：`https://p05113ef3.test.weline.com:9555`

| URL | 结果 |
|---|---|
| `/products` ×5 | `data-testid="storefront-filters-panel"`=1；`data-placeholder="list-filters"`=0；`slot-placeholder`=0；侧栏 `theme-published-slot`+`w-filters` |
| `/categories` | 同上（`category-filters`） |

---

## @项目经理

notify_pm: **true**  
请更新 SESSION `published-slot-assembly-uniformity`：主题席 DoD 返工完成（Theme `2.2.582`）；8s5 与占位安全网已并存。请再 curl `/products` 与 `/categories` 复检。
