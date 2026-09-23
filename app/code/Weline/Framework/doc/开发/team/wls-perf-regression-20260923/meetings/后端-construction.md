# 后端 — construction（A+B+D）

- seat: Team:后端:
- agent_id: e838a775-218b-4d09-9ad0-1d903b6ddba3
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: construction · **A+B 主** + **D 埋点**
- against: contracts.md · deps.md · channel/perf-architect.md **msg-5**（architect_joint=true）· 性能检查-design.md §3–6
- result: **closed**
- reload: **未执行**（禁私自 reload · NEED_PM）

---

## 冻结对齐（msg-5）

| plan | 裁定 | 本席落点 |
|------|------|----------|
| **A** 禁 pre_critical 冷 `publishedOffers(1000)` | 同意（硬） | Product Seeder：`pre_critical`→`peek_only`；`post_critical_heavy`→peek-miss 才冷种 + 袋间 `SchedulerSystem::yield` |
| **B** fail-open localeBudget 2→0 | 同意（有界） | WlsRuntime：近处女窗 `$localeBudget=0`；Provider 声明保留；extras→`locale_deferred_paths`→`locale_idle_begin`（critical seal 后） |
| **D** bag DB/WLS 计数 | 可选验收 | `hot_cache_bags_primed` 载荷含 `db_span_count` / `db_duration_ms` / `wls_span_count` / `wls_duration_ms`（请求内 delta） |

**禁区遵守**：假 HIT；平行袋；跨模块直调；私自 reload；关 deferred 种袋保 fail-open 首 HIT。

---

## 改动路径

| 路径 | 变更 |
|------|------|
| `app/code/Weline/Product/Service/StorefrontHotCacheBagSeeder.php` | peek_only / seed_sharded / light_only；禁 pre_critical 冷 1000 |
| `app/code/Weline/Product/Test/Unit/Api/Runtime/StorefrontHotCacheBagWarmupProviderContractTest.php` | 契约对齐 A |
| `app/code/Weline/Product/etc/module.php` | **1.0.299 → 1.0.300** |
| `app/code/Weline/Framework/Runtime/WlsRuntime.php` | localeBudget=0 + deferred idle；`post_critical_heavy` stage；D 埋点 |
| `app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php` | heavy after seal + D |
| `app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php` | localeBudget=0 + idle |
| `app/code/Weline/Framework/etc/module.php` | **2.5.164 → 2.5.165** |

编排顺序（owner deferred）：

```text
pre_critical (peek/light)
  → critical FPC `/`+`/products`
  → critical bag retouch
  → critical_sealed
  → post_critical_heavy (yield + 分片冷种若 miss)
  → locale_idle_begin（仅 needsCriticalPrime 且有 deferred）
  → locale FPC（若有）
  → post_locale
```

---

## UT evidence

```bash
php vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php --colors=never --do-not-cache-result \
  app/code/Weline/Product/Test/Unit/Api/Runtime/StorefrontHotCacheBagWarmupProviderContractTest.php \
  app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php \
  app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php
```

- **OK (9 tests, 87 assertions)** · 2026-09-23
- 副本：`/tmp/wls-perf-backend-ut-20260923.txt`

---

## 禁假 HIT / 合规抽检

- `peekPolicy` miss → `null`/deferred；**不** `remember` 伪 HIT
- empty stage / peer_hydrate → `light_only`（防 thrash catalog.full 回潮）
- locale Provider 契约未删；仅近处女窗编排层丢弃 extras 至 idle
- 无平行 static 袋；无跨模块 Model 直调

---

## NEED_PM

1. 受控 `server:reload -n`（本席**未**执行）后由性能席 review UC-warm / UC-deferred / UC-locale
2. 可选：`setup:upgrade -m Weline_Framework,Weline_Product`（无 Schema；可与 reload 同批，禁叠窗）

---

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

---

@项目经理：本席已交付/上报，请检查并更新 SESSION（P3-fix-A/B → closed；批 reload → 性能复审）。
