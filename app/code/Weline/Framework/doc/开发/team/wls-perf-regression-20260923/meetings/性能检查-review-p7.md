# 性能检查 — P7 specialty_review（绝对值 · B′ · 分段）

| 字段 | 值 |
|------|-----|
| slug | wls-perf-regression-20260923 |
| seat | Team:性能检查工程师: |
| agent_id | 7dc5b437-514c-4c7f-9250-beed07752b2d |
| date | 2026-09-23 |
| wave | specialty_review · **P7**（对照 channel **msg-21/22/23/24/25/26**；本席 **msg-27**） |
| against | surfaces §B′ · `meetings/架构-p7.md` · `meetings/性能检查-p7-design.md` · `meetings/后端-construction-p7.md` |
| architect_joint | **true**（沿用 msg-20 B′） |
| disk_module | Framework **2.5.170** |
| reload | **本席未执行**（禁私自 reload/restart；依赖 PM 运维稳态） |
| mcp | prepare_project ok（readiness `ready-1790102022151-4924b22ca0935a05`）；`get_skill(performance_check)` DISABLED → 宿主 `dev/ai-command/ai/性能检查.md` |
| client_session_id | perf-p7-review-abs-20260923 |
| 权威样本 | owner **pid 74859**（`ts` 2026-09-22T18:32:49Z → 18:32:59Z） |
| **verdict** | **fail**（**分段**：B′ 机制 **pass** · 绝对值 `done≤5000` **fail**） |
| **result** | **escalate** |
| notify_pm | **true** |

---

## 0. 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 公网匿名 `/`、`/products`；Host `https://p05113ef3.test.weline.com:9555` |
| 读/写 | 读；FPC + HotCache；deferred owner 预热墙钟 |
| 本波目标 | 默认 **禁止** 多语全量 HTML SSR 进必跑墙钟；`done.elapsed_ms` **≤5000**（推荐 ≤3000） |
| 对照失败基线 | pid **11294** `done≈19768ms`（locale_idle 多语 SSR ≈15s）——**仅作形态对照，不作相对加速比** |
| 禁 | 伪加速比（相对 P4/P6/11294）；假 HIT；本席私自 reload；拆壳药方 |

---

## 1. Workers / 代次确认

| 检查 | 观察（本席只读 · ≈02:33–02:36+08） |
|------|-------------------------------------|
| 磁盘 Framework | **2.5.170** |
| 运行面 | 稳态已恢复（PM msg-26）；权威 owner **74859** 完整链已落盘 |
| begin B′ | `locale_idle_budget=**0**` · `locale_paths=[]` · `locale_deferred_paths` 仍记账 **4** 条 |
| 本席 reload | **未执行** |

---

## 2. warmup 全阶段核读（权威 · pid **74859**）

日志：`var/log/wls-storefront-warmup.log`（owner 行约 741–755；中间穿插 peer 74861/74862，不计入 owner 墙钟）。

### 2.1 事件表（同 pid 完整链）

| ts (UTC) | stage | elapsed_ms | 要点 |
|----------|-------|------------|------|
| 18:32:49 | `begin` | — | paths=`/`,`/products`；`locale_idle_budget=**0**`；`locale_deferred_paths`=[`/ar_SA/`,`/zh_Hans_CN/`,`/ar_SA/products`,`/zh_Hans_CN/products`]（**4**）；`locale_paths=[]` |
| 18:32:52 | `hot_cache_bags_primed` · **pre_critical** | **3482.99** | seeded=6；**chrome_rendered:miss**（`scope=default.__store__.__channel__`） |
| 18:32:52 | `adopted` | — | `homepage-fpc:deferred-warmup:adopted` |
| 18:32:54 | `hot_cache_bags_primed` · **critical** | **377.26** | seeded=6；**chrome_rendered:miss** 仍在 |
| 18:32:54 | `critical_sealed` | **1371.48** | warmed=2 failed=0；`/` **HIT** 40.12；`/products` 首刷 **MISS** 1240.37 → probe **HIT** 52.13；嵌 `bag_prime_pre=3482.99` / `bag_prime=377.26` |
| 18:32:54 | `idle_gate` · post_critical_heavy | **27.07** | quiet_window · rounds=2 · max_wait=750 |
| 18:32:55 | `hot_cache_bags_primed` · **post_critical_heavy** | **1016.95** | seeded=8 peeked=1；**chrome_rendered:miss** 仍在 |
| 18:32:55 | `post_critical_heavy` | **1016.95** | 与 bag_prime 同位；含 idle_gate 摘要 |
| 18:32:55 | **`locale_idle_skipped`** | — | **deferred_count=4** · budget=0 · reason=`budget_zero` → **B′ 命中** |
| 18:32:59 | `hot_cache_bags_primed` · **post_locale** | **3875.41** | locale 已 skip **仍跑**；seeded=6；**chrome_rendered:miss** 仍在 |
| 18:32:59 | **`done`** | **10261.6** | paths=**仅** `/`,`/products`；warmed=2；**无** ar/bn/es/fr 等全量 SSR samples |

### 2.2 硬门禁核对（分段）

| 门禁 | 要求 | 本席结果 |
|------|------|----------|
| `locale_idle_skipped` | 必须出现 | **pass**（deferred=4 · budget_zero） |
| done paths **不得**含多语全量 HTML SSR | 无 ar/bn/es/fr 秒级 MISS | **pass**（paths 仅 `/`+`/products`；samples 无多语） |
| begin 仍记账 Provider deferred | `locale_deferred_paths` 保留 | **pass**（4 条） |
| `done.elapsed_ms` ≤5000（推荐 ≤3000） | 绝对值 | **fail**（**10261.6** ≫ 5000；亦 ≫ 3000） |

### 2.3 阶段加总 / 剩余大头（绝对值 fail 拆解）

| 阶段 | elapsed_ms | 占 done≈10262 | 定性 |
|------|------------|---------------|------|
| bag **pre_critical** | **3483** | ~34% | 大头①；chrome miss |
| bag **critical**（嵌于 seal） | 377 | ~4% | 次要 |
| **critical_sealed**（含 `/products` 首 MISS 1240） | **1371** | ~13% | 大头③ |
| **idle_gate** | 27 | <1% | 可忽略 |
| **post_critical_heavy** | **1017** | ~10% | 大头④；chrome miss |
| **locale_idle SSR** | **0**（skipped） | 0% | B′ 已切除原 ~15s |
| bag **post_locale** | **3875** | ~38% | **大头⓪**；skip 后仍重跑 bags |
| **done** | **10262** | 100% | 绝对值 **fail** |

> 注：bag 阶段 wall 与 seal/heavy 有嵌套记账，上表按日志独立 `elapsed_ms` 列大头，**不以**简单相加替代 `done`；`done=10261.6` 为权威绝对值。

对照基线（形态，**禁止**写成加速比 pass 依据）：

| 样本 | done | 备注 |
|------|------|------|
| **11294** | **19767.56** | locale_idle 多语 SSR ≈15s（失败基线） |
| **74859** | **10261.6** | B′ skip 后仍超绝对墙钟；剩余为 bag/chrome/products seal |

---

## 3. 可选轻探针（暖稳态 · 本席只读）

| 路径 | 结果 | 口径 |
|------|------|------|
| `/` | **200** · ttfb≈**4.6ms** | cookieless；与 PM 报告同向（暖 HIT 级） |
| `/products` | **200** · ttfb≈**6.0ms** | 同上 |

→ UC-warm 公网面 **可用**；**不**因此把 owner deferred `done` 判为绝对值 pass。

---

## 4. verdict（分段）

| 项 | 裁定 |
|----|------|
| B′ 机制 `locale_idle_skipped` + 无多语全量 SSR | **pass** |
| 绝对值 `done≤5000`（推荐 ≤3000） | **fail**（10261.6） |
| 相对 P4/P6/11294 | **禁用**；本纪要不写加速比 |
| **综合 verdict** | **fail**（机制达标 ≠ 绝对墙钟达标） |
| **result** | **escalate**（请 PM 开 **P8** 拆剩余大头） |

---

## 5. options（机制 pass · 绝对值 fail → 下一波）

| ID | 选项 | suggested_seats | 建议 |
|----|------|-----------------|------|
| **O1** | **post_locale** 在 `locale_idle_skipped` 后应变廉价/短路：禁止再烧 ~3.9s 同袋重 seed（当前最大头） | **架构师, 后端** | **推荐**（P8 主线） |
| **O2** | 根治 `chrome_rendered:miss` 贯穿 pre/critical/heavy/post_locale（**禁拆壳**；修 bag 命中/代次/scope） | **主题, 后端, 架构师** | 推荐并行；预估拉动 pre≈3.5s + 连带 post_locale |
| **O3** | `critical_sealed` 内 `/products` 首刷 MISS≈1240：提前 seal / 协调目录 FPC 暖路径 | **后端**（Product 协调） | 次优；单独不够到 ≤5s |
| **O4** | 本席私自 reload / 用相对基线伪 pass | — | **否决** |

**recommendation**：PM 开 **P8**，冻结 O1+O2（与架构师联合）；施工后受控 reload → 本席再绝对值复审。目标仍：`done≤5000`（推荐 ≤3000）；禁伪加速比。

---

## 6. 交付指针

- 本纪要：`meetings/性能检查-review-p7.md`（本版覆盖等待态稿）
- channel：性能席 **msg-27**
- 禁私自 reload/restart

related_web_urls：

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

@项目经理：请立刻组队解决（suggested_seats: **架构师, 后端**；并行 **主题** 查 chrome miss；性能席只读 + 施工后再审）。  
@项目经理：本席已交付/上报，请检查并更新 SESSION。
