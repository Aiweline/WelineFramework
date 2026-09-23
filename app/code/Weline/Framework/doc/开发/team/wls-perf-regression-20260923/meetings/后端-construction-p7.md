# 后端 — construction（P7-FIX · B′ locale idle）

- seat: Team:后端:
- agent_id: `31b2ff57-a6ff-48ed-a21e-45f195a9af0d`
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: construction · **P7-FIX（B′）**
- against: channel **msg-20 / msg-21** · `surfaces.md` §B′ · `meetings/架构-p7.md` · contracts UC-locale · `meetings/性能检查-p7-design.md`
- result: **closed**
- notify_pm: **true**
- reload: **未执行**（禁私自 reload · NEED_PM）

---

## 对照冻结

| 项 | 冻结要求 | 本席落地 |
|----|----------|----------|
| `localeIdleBudget` | **不得**继承 `max_paths−critical`；env `wls.worker.storefront_locale_idle_budget` 默认 **0** | 已脱钩；兼容别名 `storefront_locale_idle_budget` |
| 默认 budget=0 | 跳过全量 locale HTML SSR；打 `locale_idle_skipped` | 已打 stage（`deferred_count` / budget / reason=`budget_zero`） |
| Provider | 声明仍进 `locale_deferred_paths` | begin 日志保留；非 near-virgin 亦写入 deferred 账本 |
| budget>0 | idle-gate + `locale_idle_begin` + yield 有界 SSR | 既有路径保留 |
| 近处女 | critical `localeBudget=0` **保留** | 保留 |
| 否决 | 关整个 deferred / 假 HIT / 平行袋 | **未做** |

---

## 落地摘要

```text
critical `/`+`/products` seal
  → idle_gate(post_critical_heavy)
  → post_critical_heavy
  → [default] locale_idle_skipped（Provider extras 仅记账）
  → [env budget>0] idle_gate(locale_idle) → locale_idle_begin → locale FPC（有界）
  → post_locale → done
```

必跑墙钟口径（默认）：`pre_critical` + critical seal + `post_critical_heavy`（+idle-gate）；**不含**多语全量 HTML SSR。

---

## 改动路径

| 路径 | 变更 |
|------|------|
| `Framework/Runtime/WlsRuntime.php` | B′：`localeIdleBudget` env 默认 0；`locale_idle_skipped`；非 near-virgin 亦禁默认 locale SSR |
| `Framework/Test/Unit/Runtime/WlsRuntimeDeferredHotCacheBagPrimeContractTest.php` | 钉 skipped / 禁 inherit |
| `Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php` | budget=0→skipped；budget>0 仍可 begin |
| `Framework/etc/module.php` | **2.5.169 → 2.5.170** |

---

## 自检

- 源码：无 `$localeIdleBudget = $localeBudget`
- UT：`OK (7 tests, 99 assertions)` → `meetings/backend-ut-p7-20260923-021434.txt`（镜像 `backend-ut-p7-locale-idle.txt`）
- **未** `server:reload` / restart

---

## 下一步（PM）

受控 reload → Team:性能检查工程师: P7-review（绝对墙钟 ≤5s，推荐 ≤3s；确认 `locale_idle_skipped` + done 无多语全量 SSR）。

@项目经理：本席已交付/上报，请检查并更新 SESSION。
