# 性能检查 — P8 specialty_review（绝对值 · B′ + O1）

| 字段 | 值 |
|------|-----|
| slug | wls-perf-regression-20260923 |
| seat | Team:性能检查工程师: |
| client_session_id | perf-p8-review-20260923 |
| date | 2026-09-23 |
| wave | specialty_review · **P8**（对照 channel **msg-32**；本席 **msg-34**） |
| against | surfaces §B′ · §O1 · `meetings/架构-p8.md` · `meetings/后端-construction-p8.md` · `meetings/性能检查-review-p7.md` |
| architect_joint | **true**（沿用 msg-29 P8 freeze） |
| disk_module | Framework **2.5.171** |
| reload | **本席未执行**（禁私自 reload；依赖 PM msg-32 已批运维稳态） |
| mcp | prepare_project ok（readiness `ready-1790102576927-527c1e4ee11dff7e`）；`get_skill(performance_check)` DISABLED → 宿主 `dev/ai-command/ai/性能检查.md` |
| 权威样本 | owner **pid 35517**（`ts` 2026-09-22T18:42:13Z → 18:42:14Z；日志行 ≈769–779） |
| **verdict** | **pass**（B′ + O1 机制 **pass** · 绝对值 `done≤5000` **pass**；亦达推荐 ≤3000） |
| **result** | **closed**（残余记建议 · **不挡**绝对值 pass） |
| notify_pm | **true** |

---

## 0. 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 公网匿名 `/`、`/products`；Host `https://p05113ef3.test.weline.com:9555` |
| 读/写 | 读；FPC + HotCache；owner deferred 预热墙钟 |
| 本波目标 | 保留 B′（禁多语全量 HTML SSR 进必跑墙钟）+ O1（`locale_idle_skipped` 后禁同成本 `post_locale`）；`done.elapsed_ms` **≤5000**（推荐 ≤3000） |
| 对照（形态，**禁**加速比口径） | 失败基线 pid **11294** `done≈19768`；P7 权威 pid **74859** `done=10261.6`（B′ pass · post_locale≈3875） |
| 禁 | 伪加速比；假 HIT；本席私自 reload；拆壳药方 |

---

## 1. Workers / 代次确认

| 检查 | 观察（本席只读） |
|------|------------------|
| 磁盘 Framework | **2.5.171**（与后端 P8-FIX 升版一致） |
| 运行面 | PM msg-32 已批受控 reload；权威 owner **35517** 完整链已落盘 |
| begin B′ | `locale_idle_budget=**0**` · `locale_paths=[]` · `locale_deferred_paths` 记账 **7** 条 · `locale_budget_near_virgin=0` |
| 本席 reload | **未执行** |

---

## 2. warmup 全阶段核读（权威 · pid **35517**）

日志：`var/log/wls-storefront-warmup.log`（owner 行约 **769–779**）。

### 2.1 事件表（同 pid 完整链）

| ts (UTC) | stage | elapsed_ms | 要点 |
|----------|-------|------------|------|
| 18:42:13 | `begin` | — | paths=`/`,`/products`；`locale_idle_budget=**0**`；`locale_deferred_paths` 7 条；`locale_paths=[]` |
| 18:42:13 | `hot_cache_bags_primed` · **pre_critical** | **296.58** | seeded=6 peeked=3；**chrome_rendered:miss**（`scope=default.__store__.__channel__`）；bags **无** `chrome_rendered` |
| 18:42:13 | `adopted` | — | `homepage-fpc:deferred-warmup:adopted` |
| 18:42:14 | `hot_cache_bags_primed` · **critical** | **29.07** | seeded=6；**chrome_rendered:miss** 仍在；bags 仍无 `chrome_rendered` |
| 18:42:14 | `critical_sealed` | **265.00** | warmed=2 failed=0；`/` **HIT** 40.22；`/products` **HIT** 99.28（probe HIT 80.75）；嵌 pre=296.58 / critical=29.07 |
| 18:42:14 | `idle_gate` · post_critical_heavy | **26.67** | quiet_window · rounds=2 · max_wait=750 |
| 18:42:14 | `hot_cache_bags_primed` · **post_critical_heavy** | **637.84** | seeded=7 peeked=3；**errors=[]**；bags **含** `theme.layout_entity.chrome_rendered` |
| 18:42:14 | `post_critical_heavy` | **637.84** | 与 bag_prime 同位 |
| 18:42:14 | **`locale_idle_skipped`** | — | deferred_count=7 · budget=0 · reason=`budget_zero` → **B′ 命中** |
| 18:42:14 | **`post_locale_skipped`** | **0** | reason=`locale_idle_skipped` · deferred=7 → **O1 命中**（**无**秒级 `post_locale` primed） |
| 18:42:14 | **`done`** | **1372.91** | paths=**仅** `/`,`/products`；warmed=2；`bag_prime_final.stage=post_locale_skipped` · elapsed=0 |

### 2.2 硬门禁核对

| 门禁 | 要求 | 本席结果 |
|------|------|----------|
| `locale_idle_skipped` | 必须出现 | **pass**（deferred=7 · budget_zero） |
| `post_locale_skipped` | O1：skip 后禁同成本全量 post_locale | **pass**（em=0 · reason=`locale_idle_skipped`；**无** `hot_cache_bags_primed` stage=`post_locale`） |
| done paths **不得**含多语全量 HTML SSR | 无 ar/bn/es/fr 秒级 MISS | **pass**（paths 仅 `/`+`/products`） |
| begin 仍记账 Provider deferred | `locale_deferred_paths` 保留 | **pass**（7 条） |
| `done.elapsed_ms` ≤5000（推荐 ≤3000） | **绝对值** | **pass**（**1372.91** ≤3000 ≤5000） |

### 2.3 阶段绝对值拆解（权威 done=1372.91）

| 阶段 | elapsed_ms | 占 done≈1373 | 定性 |
|------|------------|--------------|------|
| bag **pre_critical** | **297** | ~22% | 主残余①；仍见 chrome miss（诚实 []，未假 HIT） |
| bag **critical** | 29 | ~2% | 可忽略 |
| **critical_sealed** | **265** | ~19% | `/`+`/products` 双 HIT（本样本无 O3 级首刷 MISS） |
| **idle_gate** | 27 | ~2% | 可忽略 |
| **post_critical_heavy** | **638** | ~46% | 主残余②；本段 **已** seed `chrome_rendered`（errors=[]） |
| **locale_idle SSR** | **0**（skipped） | 0% | B′ |
| bag **post_locale** | **0**（`post_locale_skipped`） | 0% | **O1 已切除** P7 大头 ≈3875 |
| **done** | **1372.91** | 100% | 绝对值 **pass** |

> 注：bag 与 seal/heavy 有嵌套记账；上表按日志独立 `elapsed_ms` 列大头，**不以**简单相加替代 `done`。权威绝对值 = **`done.elapsed_ms=1372.91`**。

对照（形态，**禁止**写成加速比 pass 依据）：

| 样本 | done | 备注 |
|------|------|------|
| **11294** | **≈19768** | locale_idle 多语 SSR（失败基线） |
| **74859**（P7） | **10261.6** | B′ pass；仍烧 post_locale≈3875 |
| **35517**（P8） | **1372.91** | B′+O1；绝对值 **pass** |

---

## 3. 可选轻探针（暖稳态 · 本席只读 · 禁 reload）

| 路径 | 结果 | 口径 |
|------|------|------|
| `/` | **200** · ttfb≈**23.6ms** | cookieless；与 PM「约 17ms」同向暖 HIT 级 |
| `/products` | **200** · ttfb≈**4.8ms** | 暖 HIT 级（本窗优于 PM 旁注 36ms；样本差不挡 owner 绝对值） |

→ UC-warm 公网面 **可用**；**不**用暖 TTFB 替代 owner deferred `done` 口径（本波 `done` 已绝对值合格）。

---

## 4. chrome miss / 残余（不挡 pass）

| 项 | 本样本观察 | 是否挡绝对值 pass |
|----|------------|-------------------|
| **chrome_rendered:miss** | **pre_critical + critical** 仍 miss；**post_critical_heavy** 起 bags 含 `chrome_rendered` 且 errors=[] | **否**（主题 P8-O2 设计：pre/critical disk-only；heavy 起可 bake；墙钟已 ≤推荐） |
| **/products** seal | 本样本双 **HIT**（无 P7 级 1240 MISS） | **否**；O3 降为可选稳态观察 |
| **O2 全链无 miss** | 未达到「pre 起全程无 miss」 | **否**；记建议，交主题/PM 择机再 reload 验 Theme **2.2.604** 是否已入 worker（msg-33 曾提示） |
| 假 HIT | 未见空串冒充 HIT | 合规 |

**反证检查**：无证据表明本样本 `done=1372.91` 由假 HIT、关 deferred、或私自裁路径伪造成；O1 stage 链完整可证。

---

## 5. verdict

| 项 | 裁定 |
|----|------|
| B′ 机制 `locale_idle_skipped` + 无多语全量 SSR | **pass** |
| O1 机制 `post_locale_skipped`（em=0 · 禁秒级 post_locale） | **pass** |
| 绝对值 `done≤5000`（推荐 ≤3000） | **pass**（**1372.91**） |
| 相对 11294/P7 加速比 | **禁用**；本纪要不写加速比 pass |
| **综合 verdict** | **pass** |
| **result** | **closed** |

---

## 6. options（残余建议 · 不挡 closed）

| ID | 选项 | suggested_seats | 建议 |
|----|------|-----------------|------|
| **R1** | 择机受控 reload 后再看 pre/critical 是否仍 chrome miss（验 Theme 2.2.604 入 worker；禁假 HIT） | **项目经理, 主题** | 建议；**不**挡本波绝对值 pass |
| **R2** | 继续压 `post_critical_heavy≈638`（若后续目标更严于 1.5s） | **架构师, 后端, 主题** | 可选；当前已 ≤推荐 3000 |
| **R3** | O3 `/products` 首刷 MISS 稳态回归观察 | **后端** | 本样本已双 HIT；降优先级 |
| **R4** | 本席私自 reload / 伪加速比口径 | — | **否决** |

**recommendation**：本波 **P8 绝对值 pass · closed**。残余 chrome/O2 交 PM 按 msg-33 择机运维窗，**非**本席再开失败波次的前置条件。

---

## 7. 交付指针

- 本纪要：`meetings/性能检查-review-p8.md`
- channel：性能席 **msg-34**（接 msg-32）
- 禁私自 reload/restart

related_web_urls：

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

@项目经理：本席已交付/上报，请检查并更新 SESSION。
