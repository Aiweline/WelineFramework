# Team:主题开发工程师: 返工交付 — required-default-always-present（attr-wrapper）

> work_mode：`theme_module_runtime` · area=`frontend`  
> PM DoD FAIL → rework  
> notify_pm: **true**  
> @项目经理：本席已交付/上报，请检查并更新 SESSION

---

## 结论

第一波（skip_fill 调 overlay）不够：出站剥掉 `data-wslot` 后无 `data-slot-id`，`enumerateRegions` 无 marker → `[]`，overlay 无落点。

本波已绿：
1. **listSlotRegions** / **enumerateRegions**：无 marker 时用 `findSlotWrapperBounds` / `enumerateRegionsByAttributes` 认 `data-slot-id` / `data-wslot`。
2. **stripReactiveSlotAttributes**：去 `data-wslot` 前 promote → `data-slot-id` + `theme-published-slot`。
3. **server:stop/start** 后 HTTPS curl 登录页含 `data-widget-code="account-social-login"`（含 Google/Facebook）。

---

## 改文件

| 文件 | 变更 |
|---|---|
| `Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php` | `listSlotRegions` attr-wrapper；`replaceRegionInner` 认 via |
| `Service/SlotBoundaryScanner.php` | `enumerateRegionsByAttributes` |
| `Service/SlotBoundaryMarkers.php` | strip 前 promote data-slot-id + theme-published-slot |
| UT ZeroDataWslot / GhostSlot | strip 保身份；data-slot-id regions |
| `etc/module.php` | `2.2.592` → `2.2.593` |
| `doc/开发日志.md` | 本轮 |
| 删除 | `var/tmp-probe-required-overlay.php` |

**未改**：Customer login.phtml；未 git restore。

---

## UT

```
48 tests / 327 assertions OK
```

（ZeroDataWslot / GhostSlot / ForcedZeroFill / SolidifiedShell / RequiredDefaultInjection / SlotBoundary*）

## curl 复验（PASS）

```
https://p05113ef3.test.weline.com:9555/customer/account/login → 200
```

出站片段：

```html
<div class="w-auth-login__social-slot theme-published-slot"
     weline-code="customer.account.login.social_providers"
     data-slot-id="account-login-social-providers">
  <div data-widget-code="account-social-login" data-testid="account-social-login" …>
    … account-social-login … Google / Facebook …
  </div>
</div>
```

运维：`cache:flush` + `server:stop`/`server:start`（`server:reload` 曾被 min-ready 挡，全量重启后生效）。

---

## 上报

- plan_id：`theme-runtime`
- result：`delivered`（rework PASS）
- notify_pm：**true**
- @项目经理：本席已交付/上报，请检查并更新 SESSION
