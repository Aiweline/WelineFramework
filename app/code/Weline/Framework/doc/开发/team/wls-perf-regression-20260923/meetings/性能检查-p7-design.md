# 性能检查 — P7 design（绝对墙钟缺陷）

| 字段 | 值 |
|------|-----|
| slug | wls-perf-regression-20260923 |
| seat | Team:性能检查工程师: |
| agent_id | ad486b17-5243-402e-a309-249468d1a902 |
| date | 2026-09-23 |
| wave | design · **P7**（对照 channel **msg-19**） |
| against | 用户否决 deferred done≈20s；P6 仅相对 P4 pass |
| architect_joint | **true**（架构 msg-20 **B′** 已冻；PM msg-21 已唤醒后端） |
| stance | **异议 / 绝对墙钟 fail**（非相对加速比） |
| reload | **未执行**（禁私自 reload） |
| mcp | prepare_project ok；`get_skill(performance_check)` 本会话 DISABLED → 宿主权威 `dev/ai-command/ai/性能检查.md` + `统一缓存范围与性能优化.md` |
| client_session_id | perf-p7-storefront-warmup-20260923 |
| readiness_id | ready-1790100464282-bf73d8a95f6bb9b3 |
| result | **escalate** |
| notify_pm | **true** |

---

## 0. 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 公网匿名店面 `/`、`/products`；Host `https://p05113ef3.test.weline.com:9555` |
| 读/写 | 读；FPC + HotCache bags；本波无写侧 |
| 运行时 | WLS Worker deferred owner 预热；事件循环内同步 CPU |
| 热路径 | edge → FPC → `pre_critical` → critical seal → `idle_gate` → `post_critical_heavy` → `idle_gate` → **`locale_idle` 全量 HTML SSR** → `post_locale` → done |
| 用户否决点 | **done≈20s 绝对值**占住 Worker 事件循环 ⇒ 卡顿/502 风险；**不是**「比 P4 快一点就合格」 |
| 非目标 | 暖稳态公网 TTFB（P6 UC-warm 已 pass，百毫秒内 HIT） |

---

## 1. 权威代次与证据源

| 项 | 值 |
|----|-----|
| 权威 owner 样本（P6 / 用户否决） | **pid 11294** · `done.elapsed_ms=19767.56` · ts `2026-09-22T17:48:06Z`→`17:48:26Z` |
| 日志最新完整 done（同缺陷形态） | **pid 37374** · `done=17948.52`（仍 ≫ 绝对目标） |
| 日志 | `var/log/wls-storefront-warmup.log` |
| 半代次 | 7542/7633 等仍**作废**；本表只用 11294（+ 37374 作「最新仍不合格」旁证） |

禁伪加速比：本设计**不**写「相对 P4 快 X%」作为通过条件。

---

## 2. done 阶段拆解（pid 11294 · 可加总）

### 2.1 仪器阶段（日志 `elapsed_ms`）

| # | 阶段 | elapsed_ms | 备注 |
|---|------|------------|------|
| 1 | `pre_critical` bag_prime | **2084.40** | peeked=1；仍 `chrome_rendered:miss` |
| 2 | `critical` bag_prime | **315.74** | 轻触 |
| 3 | `critical_sealed`（路径暖+samples） | **531.70** | `/` HIT 60ms · `/products` HIT 154ms |
| 4 | `idle_gate`(post_critical_heavy) | **165.72** | quiet_window |
| 5 | `post_critical_heavy` bag_prime | **1434.36** | full/slug2/candidates |
| 6 | `idle_gate`(locale_idle) | **26.25** | — |
| 7 | **`locale_idle` SSR（见 2.2）** | **≈15000 墙钟** | 大头；见下 |
| 8 | `post_locale` bag_prime | **64.22** | — |
| Σ | **`done`** | **19767.56** | 墙钟总账 |

可加总核对（不含 locale 窗）：

`2084.40 + 531.70 + 165.72 + 1434.36 + 26.25 + 64.22 ≈ **4243ms**`  
→ 与 `locale_idle_begin`→`done` 墙钟窗 **≈15s** 相加 ≈ **19.2s**，对齐 `done≈19768ms`（秒级 ts 量化误差可接受）。

### 2.2 locale samples（串行冷 SSR）

`locale_idle_begin`：

- `locale_paths` = `/ar_SA/` `/bn_BD/` `/es_ES/` `/fr_FR/`
- `locale_idle_budget` = **4**
- `deferred_count` = 7（含 `*/products` 等未进本轮 slice）

| path | fpc_status | elapsed_ms | body_length | 入 samples |
|------|------------|------------|-------------|------------|
| `/ar_SA/` | **MISS** | **3176.16** | 974584 | 是 |
| `/bn_BD/` | **MISS** | **3209.94** | 1011828 | 是 |
| `/es_ES/` | （未入 samples；`done.paths`/`locale_paths` 含） | **≈3–4.5s（由墙钟差推）** | — | 否（samples 合并后仅见 4 条） |
| `/fr_FR/` | 同上 | **≈3–4.5s** | — | 否 |

- 已落盘 locale SSR 和：`3176+3210=6386ms`
- locale 墙钟窗 − 已落盘 ≈ **8614ms** ⇒ 与 msg-19「es/fr 各约 2–4.5s」同量级
- **critical 暖 HIT 仅百毫秒**；20s **不是**用户打开首页的正常冷启动，而是 **Worker 被多语全量 HTML SSR 长时间占住**

### 2.3 最新代次旁证（pid 37374 · 只读）

| 阶段 | elapsed_ms | 要点 |
|------|------------|------|
| pre_critical | 761.43 | — |
| critical_sealed | **5038.53** | `/products` 首封 **MISS 4831ms**（非本波主诉，但说明 critical 偶发冷仍贵） |
| post_critical_heavy | 1582.64 | — |
| locale | ar **HIT 69** · bn **MISS 3549** | idle 仍跑多语 |
| **done** | **17948.52** | 绝对值仍 fail |

---

## 3. 根因（埋点 → 代码）

`WlsRuntime` deferred 近处女窗：

1. `needsCriticalPrime` 时把 **`localeBudget=0`**（critical 窗不跑多语）——P6 UC-locale **结构** pass。
2. 但 **`localeIdleBudget` 在清零前已取 `max_paths−critical=4`**，critical seal + heavy 之后仍：

```text
localeList = slice(localeDeferred, 0, localeIdleBudget=4)
→ runStorefrontFpcWarmupInternal(localeList, …)  // 全量 HTML SSR，串行
```

3. 结果：B「后移」≠「取消」；**done 墙钟仍被 4 路冷 SSR 吃掉 ≈15s**。
4. 残余：全程 `chrome_rendered:miss`（诚实短路径；P6 已记；**非**本波 20s 主因）。

→ **缺陷定性**：默认 deferred 在 owner 事件循环内做**多语全量 HTML SSR**；绝对墙钟不合格。禁拆壳药方。

---

## 4. 瓶颈排序（绝对墙钟）

| 优先级 | 瓶颈 | 量级（11294） | 归属 |
|--------|------|---------------|------|
| **P0** | `locale_idle` 多语串行冷 SSR | **≈15s / done 的 ~76%** | Framework `WlsRuntime` + 架构冻结 |
| P1 | `pre_critical` + `post_critical_heavy` bag | ≈2.1s + ≈1.4s | 已 peek/后移；可再压但非 20s 主因 |
| P2 | critical 偶发 MISS（见 37374 `/products`） | 可达数秒 | 另案；非本否决主诉 |
| P3 | `chrome_rendered:miss` 日志 | 墙钟短路径已达标 | 主题盘 bake（可选） |

---

## 5. 绝对目标建议（验收口径）

**通过条件（绝对值，禁伪加速比）：**

1. 受控 reload 后 owner 最新 `done.elapsed_ms` **≤ 5000ms**（推荐目标 **≤ 3000ms**）。
2. 默认 deferred **不得**在 owner 事件循环内对 ≥2 个非默认 locale 做全量 HTML SSR；等价冻结任选其一并写进 contracts：
   - `locale_idle_budget=0`（含 idle；extras 永不进本轮 SSR），或
   - idle 仅廉价探针（禁假 HIT / 禁用 bypass 冒充公共 FPC），或
   - 显式 env 才开启多语全量 SSR（默认关）。
3. critical `/`+`/products` 暖稳态仍须 FPC HIT（保持 P6 UC-warm）。
4. 首访非默认 locale 允许 on-demand MISS（用户路径真实 SSR），**不得**用「后台默默烤 20s」伪装成框架冷启动合格。

对照基线（仅记账，不作相对 pass）：P4 done≈27109 · P6 done≈19768 · **P7 必须砍到 ≤5s**。

---

## 6. options（≥2）

| ID | 方案 | 利 | 弊 | 合规 |
|----|------|----|----|------|
| **O1** | 近处女 / 默认 deferred：**`localeIdleBudget` 强制 0**（与 `localeBudget` 同清零）；`locale_deferred_paths` 仅记账，本轮不 SSR | 直接砍掉 ≈15s；改动面小；对齐 msg-19「禁止多语全量 HTML SSR」 | 非默认语种首访可能冷；需产品接受 | 推荐默认 |
| **O2** | idle 改为**廉价探针**（HEAD/轻 key touch / Shared 元数据），禁止全页 SSR；全量 HTML 仅 critical 两路径 | 保留「locale 键存在感」；墙钟可控 | 须架构定义探针语义；禁假 HIT | 可与 O1 组合为显式 opt-in |
| **O3** | 保留多语 SSR，但硬墙钟预算（例合计 ≤1s）+ 超时跳过 | 仍尝试暖多语 | 预算内几乎烤不完；实现复杂；仍可能抖 | **不推荐**作主方案 |
| **O4** | 拆 chrome / 减部件冒充加速 | — | 违 `theme_seat_integrity` | **否决** |

---

## 7. recommendation

**推荐 O1（主）+ 可选 O2（显式 env 开启廉价/全量 locale warm）。**

与架构师联合冻结文案建议：

> **默认 deferred：禁止多语全量 HTML SSR**（`localeBudget=0` **且** `localeIdleBudget=0`）；critical 仅 `/`+`/products`；locale extras 不得进入 `runStorefrontFpcWarmupInternal` 除非非默认显式开关。

本席**不**私排施工、**不**私自 reload；只读探针 + escalate 请 PM 组 **后端**按冻结改 `WlsRuntime`（+ UT：idle budget 近处女为 0）。

---

## 8. suggested_seats / escalate

| seat | 动作 |
|------|------|
| **架构师** | 同回合冻结 O1/O2 surfaces + contracts 绝对值 UC |
| **后端** | 按冻结改 `WlsRuntime` locale idle；补契约 UT；禁私自 reload |
| 性能检查工程师 | 施工后 P7-review：绝对值 `done≤5s` → pass/fail |
| 主题（可选） | chrome bake 消 miss 串（非 P0） |

`@项目经理：请立刻组队解决` — result=**escalate** · notify_pm=**true**。

---

## 9. related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

（本席未开 Browser；未 reload。）
