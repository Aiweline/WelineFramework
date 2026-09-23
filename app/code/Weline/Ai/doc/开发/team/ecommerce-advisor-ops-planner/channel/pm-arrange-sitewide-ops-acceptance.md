# channel — PM 排期：全站汉风商城运营验收波

日期：2026-09-23  
来源：`channel/ops-acceptance-charter-hanfu-mall.md`（用户定调 · escalate → PM）  
权威：`dev/ai-command/ai/电商顾问.md`「运营验收与驳回」  
验收面：`https://p05113ef3.test.weline.com:9555/` · `/zh_Hans_CN/`  
角色：`Team:项目经理:`（只排期/派工/DoD，**禁止**改业务码）

## 决策摘要

- P0 热修首页塌陷已汇审 **PASS**（`meetings/汇审-hotfix-broken-home.md`）；**仍须全站运营验收**，技术绿 ≠ 运营过。
- 目标：打造**汉服古风可逛可买商城**；运营必须验收监控；**不验收、不驳回不行**。
- 本波强制：电商顾问全站抽检 + 图片资产审查 → `channel/sitewide-ops-acceptance-report.md`（含 `ops_acceptance`）。
- 顾问任一 fail → PM **同回合**派主题/部件/前端/出图相关席；禁止只记账。
- 汇审门禁：缺 `ops_acceptance=pass` → 禁止宣称完成。

## 验收面清单（顾问必检 · 每面 pass/fail）

| 面 ID | 路径/入口（基线 Host） | 抽检重点 | 图片审查 |
|-------|------------------------|----------|----------|
| HOME | `/` · `/zh_Hans_CN/` | 首屏商城感、Hero CTA、货架密度、无塌布局/占位标 | Banner/主图水墨气质、无框中框/脏边 |
| COLLECTION | 品类/集合/搜索结果 | 磁贴/筛选可读、卡面价+加购、列表不崩 | 类目图/货架图气质 |
| PDP | ≥1 条 `/product/...` | 主图/规格/加购可达、详情不半成品 | 主图 1:1、细节图规格 |
| CART | `/cart`（或等价） | 行项/小计/去结账；空车态可读 | 缩略图清晰 |
| CHECKOUT | `/checkout`（或等价） | 地址/运费/支付入口可见；无断链 | N/A 或信任图 |
| ACCOUNT | 登录/注册/账户入口 | 入口可达、无错版壳 | N/A |
| POLICY | 隐私/条款/退换/运费政策等 | 文案可读、链接可达 | N/A |
| ASSETS | 全站可见图资产抽样 | — | 对照长安汉服古风；不达标写清规格 |

顾问产出字段（硬）：每面 `verdict=pass|fail`；总 `ops_acceptance=pass|fail|pending`；fail 必含期望效果 + 图片规格 + `suggested_seats` + `notify_pm: true` + `@项目经理：请立刻组队解决`。

## 本波立刻开工

| 优先级 | 工单 ID | 做什么 | 席位 | 依赖 |
|--------|---------|--------|------|------|
| P0 | `WO-OPS-SW-01` | 全站运营抽检 + 图片效果审查；写 `sitewide-ops-acceptance-report.md` | **电商顾问**（禁写码） | 宪章 + 热修 PASS 基线 |
| P0 | `WO-OPS-SW-02` | 顾问 fail 项同回合施工（主题/部件/前端/出图） | 按 report `suggested_seats` | **等 report**；PM 同回合派 |
| P1 | `WO-OPS-SW-03` | 施工闭环后顾问复审 fail 面 | 电商顾问 | 施工席 closed + done |
| P1 | `WO-OPS-SW-04` | PM DoD + 汇审（须 `ops_acceptance=pass`） | 项目经理 | 复审 PASS |

## 禁写码席（本任务）

| 席位 | 原因 |
|------|------|
| `Team:项目经理:` | 只排期/派工/DoD |
| `Team:电商顾问:` | 运营验收官；禁写码；有否决权 |

## 唤醒顺序（本席执行）

1. **立刻**唤醒 `Team:电商顾问:` → 禁缓存 Browser 全站抽检 → `channel/sitewide-ops-acceptance-report.md`。
2. report 到位后：凡 fail → **同回合**按 `suggested_seats` 派主题/部件/前端等；图片缺口写清规格后组出图/资产席。
3. 施工 closed → 顾问复审 → PM 汇审（无 `ops_acceptance=pass` 不得宣称完成）。

## 状态

- [x] PM 已写本排期
- [ ] 顾问全站 report（`ops_acceptance`）
- [ ] fail 项施工席派工
- [ ] 顾问复审 / PM 汇审
