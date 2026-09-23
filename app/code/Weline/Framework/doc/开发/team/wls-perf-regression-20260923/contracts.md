# contracts — wls-perf-regression-20260923（施工波 · PM 按 escalate A+B+C · **P8 O1**）

冻结依据：`meetings/性能检查-design.md` §6 + `surfaces.md` joint；架构师 msg-3 A–C · msg-20 B′ · **msg-29 O1/O2/O3**（`architect_joint=true`）。  
P8 纪要：`meetings/架构-p8.md` · 性能复审：`meetings/性能检查-review-p7.md`。

## UC（本波验收意图）

| id | 意图 | 证据 |
|----|------|------|
| UC-warm | 公网 cookieless `/` `/products` 暖路径 FPC HIT+edge HIT，TTFB 稳 &lt;50ms | curl 头+TTFB |
| UC-deferred | reload 后 deferred：pre_critical **禁止**冷 `publishedOffers(1000)` 同步；**必跑墙钟** `done≤5000`（推荐 ≤3000；禁伪加速比）；默认不含多语全量 SSR；**P8 O1**：`locale_idle_skipped`（或未跑 locale SSR）后 **不得**默认同成本 `post_locale` 全量 bag（允许 skip/peek/noop stage） | warmup.log stage elapsed |
| UC-locale | fail-open 近处女窗 `localeBudget=0`；**P7 B′** 默认 `locale_idle_budget=0`（Provider 仍可声明→`locale_deferred_paths`；禁默认全量 HTML SSR）；critical `/`+`/products` 先 seal | warmup paths/order · idle skipped |
| UC-post-locale | **P8**：仅当本轮实际跑过 locale FPC SSR 才允许有界 `post_locale` retouch；skip 路径须见 `post_locale_skipped`（或等价）而非秒级全量 primed | warmup.log · UT |
| UC-peer | peer hydrate：ScopeIdentity 齐；chrome miss 短路径合规（禁假 HIT）；delay 调整须 Shared 就绪证据 | warmup peer errors↓ |
| UC-chrome | **P8 O2（框）**：根治 `chrome_rendered:miss`（命中/代次/scope）；**禁拆壳**；主题归属 | bag stage bags/miss |
| UC-products-seal | **P8 O3（次优）**：降低 `/products` 首 seal MISS 墙钟（单独不够 ≤5s） | critical_sealed samples |
| UC-no-fake | 禁假 HIT / 平行袋 / 种袋代 8a2 | 代码抽检 |

## 席位交付物

| 席 | 交付 | 路径边界 |
|----|------|----------|
| 架构师 | channel reply 确认 A–C / B′ / **O1–O3**；更新 surfaces `architect_joint` | team doc only |
| 后端 | **P8 O1**：`WlsRuntime` post_locale 短路/peek/noop；继承 A/B/B′；O3 次优先 | `Weline_Framework` Runtime/WlsRuntime；升版；禁自 reload |
| 主题开发工程师 | **P8 O2（可选并行）**：chrome bag 命中/代次/scope；继承 C | `Weline_Theme` bag seeder/provider；work_mode=`theme_module_runtime` area=`frontend`；禁拆壳 |
| 性能检查工程师 | 施工后绝对值 review（PM 批准 reload 后）；`done≤5000` | meetings/性能检查-review*.md |

## 禁止

假 HIT；平行袋；跨模块直调；私自 reload；关整个 deferred；关 deferred 种袋同时宣称保 fail-open 首 HIT 却无证据；默认把多语全量 HTML SSR 计入 owner deferred 必跑墙钟；**locale 已 skip 后仍默认同成本全量 `post_locale` bag**；拆 chrome 壳当优化；伪加速比。
