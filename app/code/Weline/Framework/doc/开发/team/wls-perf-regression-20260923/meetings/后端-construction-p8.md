# 后端 — construction（P8-FIX · O1 post_locale 短路）

- seat: Team:后端:
- agent_id: `dd655ec1-e51a-4010-9b3f-d0b3c2c7bb16`
- client_session_id: `backend-p8-post-locale-20260923`
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: construction · **P8-FIX（O1）**
- against: channel **msg-29 / msg-30** · `surfaces.md` §O1 · `contracts.md` UC-post-locale · `meetings/架构-p8.md`
- evidence: pid 74859 `locale_idle_skipped` 后仍 `post_locale` ≈3875ms → done=10261.6
- result: **closed**
- notify_pm: **true**
- reload: **未执行**（禁私自 reload · NEED_PM）

---

## 对照冻结

| 项 | 冻结要求 | 本席落地 |
|----|----------|----------|
| skip / 未跑 locale SSR | **禁止**默认同成本全量 `primeDeferredStorefrontHotCacheBags('post_locale')` | `$localeSsrRan` 门控；未跑 → 不调用全量 prime |
| stage | `post_locale_skipped`（reason=`locale_idle_skipped` / `locale_ssr_not_run`） | 已打；`bag_prime_final.skipped=true` · `elapsed_ms=0` |
| 有界 retouch | 仅本轮实际 `runStorefrontFpcWarmupInternal($localeList…)` 后 | `$localeSsrRan === true` 才跑 `post_locale` |
| B′ | `locale_idle_budget` 默认 0 · `locale_idle_skipped` **保留** | **未改** B′ 路径 |
| 否决 | 关整个 deferred / 假 HIT / 私自 reload | **未做** |

---

## 落地摘要

```text
critical `/`+`/products` seal
  → idle_gate(post_critical_heavy)
  → post_critical_heavy
  → [default] locale_idle_skipped（Provider extras 仅记账）
  → [env budget>0] idle_gate(locale_idle) → locale_idle_begin → locale FPC（有界）
  → [localeSsrRan] post_locale retouch
  → [!localeSsrRan] post_locale_skipped（reason=locale_idle_skipped|locale_ssr_not_run）
  → done
```

必跑墙钟口径（默认，更新）：`pre_critical` + critical seal + `post_critical_heavy`（+idle-gate）；**不含**多语全量 HTML SSR；**亦不含** locale skip 后同成本 `post_locale` 全量 bag。

---

## 改动路径

| 路径 | 变更 |
|------|------|
| `Framework/Runtime/WlsRuntime.php` | O1：`$localeSsrRan` 门控；skip → `post_locale_skipped`；保留 B′ |
| `Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php` | 钉 UC-post-locale（gate + skipped stage） |
| `Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php` | 钉 skipped / gated prime |
| `Framework/etc/module.php` | **2.5.170 → 2.5.171** |

---

## 自检

- 源码：`locale_idle_skipped` 后无条件 `prime…('post_locale')` **已切除**
- UT：DeferredBagPrime **3/3**（71 assert）+ AdoptHomepage **4/4**（42 assert）→ **7 tests / 113 assertions OK**
- 证据文件：`meetings/backend-ut-p8-20260923-024050.txt`（副本 `backend-ut-p8-post-locale.txt`；`app/code` 内禁止 symlink，否则 `framework:compile` 失败）
- **未** `server:reload` / restart

---

## 下一步（PM）

受控 reload → Team:性能检查工程师: P8 绝对值复审（确认 `locale_idle_skipped` **后**见 `post_locale_skipped` 而非秒级 `hot_cache_bags_primed` stage=`post_locale`；`done≤5000` 推荐 ≤3000）。

@项目经理：本席已交付/上报，请检查并更新 SESSION。
