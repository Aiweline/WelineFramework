# 后端 — construction（P5-fiber-yield · O2）

- seat: Team:后端:
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: construction · **P5-fiber-yield（O2）**
- against: channel **msg-10** · `meetings/性能检查-review.md` O2 · contracts UC-deferred · dirty-load 接替半成品
- result: **closed**
- notify_pm: **true**
- reload: **未执行**（禁私自 reload · NEED_PM）

---

## 对照复审

| 项 | 复审证据 | 本席动作 |
|----|----------|----------|
| A/B 结构 | pass（heavy 已出 pre；locale idle 后移） | 保持 |
| owner done≈27s | `post_critical_heavy`+`locale_idle` 长同步占事件循环 | idle-gate + localeIdleSlice yieldDelay + Product heavy yieldDelay |
| peer `capture_miss` | Framework 编排可修 | Fiber-local bag-prime latch + capture_retry |
| Theme `chrome_rendered:miss` | 主题席 O1 | **本席不改 chrome 内容** |

---

## 落地

### 1. idle-gate（critical_sealed 后尽快让出）

```text
critical_sealed
  → idle_gate(post_critical_heavy)   # quiet-window / peer_drain · 有界
  → post_critical_heavy
  → idle_gate(locale_idle)
  → locale_idle_begin + locale FPC（localeIdleSlice=true · path 间 yieldDelay）
  → post_locale
```

- `awaitDeferredStorefrontIdleGate`：先 `yield`，再 quiet slices / max_wait 有界 `yieldDelay`
- 默认：`max_ms=750` · `slice_ms=25` · `quiet_slices=2`（Env 可调）
- stage 日志：`idle_gate`
- **禁**假 HIT；**禁**关 deferred 种袋

### 2. capture_miss 短路径（Framework）

根因：进程级 `deferredHotCacheBagPrimePending` 在多 Fiber 下会被 live 请求的 `handle()` finally 误清 → bag-prime Fiber `capture_miss`。

- `fiberHotCacheBagPrimeStates` WeakMap（pending/stage/capture Fiber-local）
- Fiber 下 live 请求不得靠 `$_SERVER` 偷 latch
- miss 后一次 `capture_retry`（仍禁假 HIT；fallback 保留诚实 `capture_miss`）

### 3. Product heavy 分片（必要升版）

- `post_critical_heavy` 袋间 `SchedulerSystem::yieldDelay(15)`；首冷种前再 `yield`
- Product **1.0.301**

---

## 改动路径

| 路径 | 变更 |
|------|------|
| `Framework/Runtime/WlsRuntime.php` | idle-gate · Fiber latch · localeIdleSlice · capture_retry |
| `Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php` | O2 契约 |
| `Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php` | idle 序 |
| `Framework/etc/module.php` | **2.5.167 → 2.5.168** |
| `Product/Service/StorefrontHotCacheBagSeeder.php` | heavy yieldDelay(15) |
| `Product/Test/.../StorefrontHotCacheBagWarmupProviderContractTest.php` | yieldDelay(15) |
| `Product/etc/module.php` | **1.0.300 → 1.0.301** |

---

## UT evidence

```bash
php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --colors=never --do-not-cache-result \
  app/code/Weline/Product/Test/Unit/Api/Runtime/StorefrontHotCacheBagWarmupProviderContractTest.php \
  app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php \
  app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php
```

- **OK (9 tests, 103 assertions)** · 2026-09-23
- 副本：`meetings/backend-ut-p5-20260923.txt`

---

## NEED_PM

1. 与主题 P5-O1 一并批受控 `server:reload -n`（本席**未**执行）→ P6 性能再审 UC-deferred / UC-peer（绝对值 · 禁伪加速比）
2. 可选：`setup:upgrade -m Weline_Framework,Weline_Product`（无 Schema；可与 reload 同批）
3. chrome miss 墙钟仍归主题席；本席不宣称 peer CPU 已达标

---

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

---

@项目经理：本席已交付/上报，请检查并更新 SESSION（P5-fiber-yield → closed；O1+O2 齐后再批 reload → P6 性能再审）。
