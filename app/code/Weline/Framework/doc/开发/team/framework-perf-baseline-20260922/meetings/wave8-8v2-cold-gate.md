# wave8-8v2 — 固化整壳口径复测（性能 · msg-100）

- date: 2026-09-22 ~19:46+08
- seat: Team:性能检查工程师:
- channel: `framework-unreasonable-audit.md` **msg-100**（re msg-99/99b；对照 msg-97 8s5 · msg-95 8a2 · msg-98 改口径）
- claim_sla: **false** · 禁自 reload · 禁自排 8c\* 种袋主波 · **禁**把种袋 HIT 当主门
- 对照：msg-93（旧 8v 种袋门 · 已归档）· msg-70（total=2152 辅证锚）· Theme **2.2.581**

## result

**pass**（主门 · 8a2/8s5 固化整壳口径）

辅证记数：total **4264.87** 劣于 2152；公网 `/`+`/products` **HIT**；残 B% **42.5%**。**不得**因种袋 absent 要求后端重开 8c\*。

## 采样纪律

| 项 | 值 |
|----|-----|
| Master | **37663** · Started `2026-09-22 09:15:56` |
| Workers（采样时） | **11867** / **11905**（相对 msg-93 的 90295/90350 已换代；**本席未自 reload**；对齐 msg-99b 后本机窗） |
| etime | ≈**02:00** |
| `setup_upgrade.lock` | **清** · 无并行 upgrade |
| 版本 | Theme **2.2.581** · F **2.5.164** · S **2.0.79** · Product **1.0.297** |
| 样本 | pid=**11905** · worker_id=**2** · `request_count=**300**`（**>≲80** · **非真近处女**；数字保留；**不得**用本窗冒充处女 total 关账） |
| 探针 | panel Cookie（BP）· identity · Worker `:19655` · Host `p05113ef3.test.weline.com:9555` · FPC **MISS** · 出站 `private, no-store` |
| request_id | `25cca4f82a8f553f-680024565731708` |
| HTTP | **200** · 明文 **649,115** ≈0.65MB · curl TTFB≈4.31s |
| App total | **4264.87ms** · DB/WLS **678.6** / **1370.03** · truncated=false · dropped_span=2366 |
| shell.phtml | 仓内 **83**（对齐 msg-99b shells_written=83） |

## 主门（新口径 · 8a2/8s5）

| 项 | 门 | 本窗 | 判定 |
|----|----|------|------|
| 响应 `data-wslot=` | **=0** | **0** | **pass** |
| LayoutSlot | ≪100ms 或无 span | observer **81.66ms**（phases 无 LayoutSlot 聚合；`zero_runtime_fill` 0ms skipped `ctx_was_false+skip_fill_solidified`） | **pass** |
| `theme.partials.fetch.header` 作布局再生 | absent / 近零 | **phases absent · top sum=0** | **pass** |
| `chrome_slot_projection` 作布局再生 | absent / 近零 | **builders/top absent** | **pass** |
| `theme.storefront_chrome` 作布局再生 | absent / 近零 | **builders/top absent**（本窗无该 resource） | **pass** |
| runtime injectChrome / fill 拼布局 | 无 | HTML 无 `injectChrome`；`zero_runtime_fill` **skipped_fill=true** + `skip_fill_solidified` | **pass** |

**结论**：未见 header / chrome_slot / storefront_chrome 布局再生主路径耗时 → **8s5 整壳直读已吃到**（非「未吃到 / 非 shell 路径」fail）。

## 辅证（记数 · 不关主账）

| 项 | 门/锚 | 本窗 | 记数 |
|----|-------|------|------|
| total | ≤2152（msg-70） | **4264.87** | **fail（辅）** |
| 残 B | %/绝对 | **≈1813.76 / 42.5%**（agg_builder head **1334** + dict **435** + mc **45**；**无** header/chrome 布局袋） | 记数 |
| A core | — | card **1288.97** + head **163.67** = **1452.64 / 34.1%** | 记数 |
| 公网 HIT | `/`+`/products` | `/` #1/#2 **HIT**（TTFB≈40ms/5ms）；`/products` #1/#2 **HIT**（≈5ms/14ms） | **pass（辅）** |
| 种袋 L1/L2 | — | head/card builders 多 **absent**（预期辅；**禁**据此排 8c\* 主波） | 记数 |

### top phases（辅）

| ms | phase |
|----|-------|
| 1334.18 | storefront.cache.builder（resource=`theme.storefront_head` · l1/l2 absent） |
| 1288.97 | product.card.render |
| 434.85 | view.hook.dictionary_prefetch |
| 163.67 | theme.partials.fetch.head |
| 140.40 | theme.header.category_nav |

## vs msg-93（旧种袋门 · 已归档）

| 项 | msg-93 | 本窗 8v2 |
|----|--------|----------|
| 关账口径 | 种袋非 absent + header 勿~846 | **固化整壳直读** |
| header 布局 | **2448ms** | **absent** |
| chrome/storefront_chrome | absent（当 fail） | absent（**主门合格**） |
| LayoutSlot | 475ms fail | **81.66** pass |
| marker | 0 | **0** |
| total | 10619 | **4265**（辅仍劣于 2152） |

## 明确不宣称

- claim_sla=false；禁暖 HIT / 公网 HIT 关冷 total 账
- 禁因种袋 absent 要求后端重开 8c\* 主波
- rc=300 **不得**宣称真近处女 total 关账
- 禁本席自 reload / 自排施工

## escalate

**@项目经理：请安排**（禁本席自排）

1. **主门 pass**：8s5 整壳直读复测过；header/chrome_slot/storefront_chrome **未**再作布局再生主路径。
2. **辅**：total **4265** 仍劣于 2152，主导为 **product.card.render** + **storefront_head builder** + dict——**非**布局壳再生；若继续压墙钟，请 PM 另开 Product/head 面（**勿**回 8c\* 种袋主波；**勿**再排同题 8s5 除非回归）。
3. 若要坚持真近处女 total 对照：NEED_PM 干净 reload 后更早唤醒复测（本席禁自 reload）。

`pass=true`（主门）· `claim_sla=false` · suggested_seats: 项目经理（收口） / （可选）产品·目录或主题 head（辅 total） / 性能复测
