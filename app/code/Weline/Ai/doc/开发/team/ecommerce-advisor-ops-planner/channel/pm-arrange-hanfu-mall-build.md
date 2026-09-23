# channel — PM 排期：汉服古风商城 · Wave 打造-1

日期：2026-09-23  
指令：用户 **「开始打造」**（禁止空等）  
角色：`Team:项目经理:`（只排期/派工/DoD，**禁止**改业务码）  
验收面：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`

## 权威（本波必遵）

| 文档 | 用途 |
|------|------|
| `channel/ops-acceptance-charter-hanfu-mall.md` | 运营验收宪章；无 `ops_acceptance` 不得汇审完成 |
| `channel/homepage-mall-feel-review.md` | 商城感诊断基线（气质服从可买） |
| `channel/homepage-wave3-mall-ops-brief.md` | Wave-3 布局/货架顺序拍板（MALL-01…05） |
| `channel/pm-arrange-sitewide-ops-acceptance.md` | 全站运营抽检面清单（HOME…ASSETS） |

## 决策摘要

- **Wave 打造-1 目标**：**汉风可逛可买**——水墨/唐宋气质 + 首屏见价见加购 + 全站可买路径不断。
- 全站运营验收报告（`sitewide-ops-acceptance-report.md`）若未落盘：**顾问并行出报告**；PM **同时**开可确定施工，禁止空等报告。
- 图片/Banner/主图：顾问有规格 → 立刻派主题/出图；顾问未到 → 先派顾问 brief（含期望气质+尺寸用途）。
- 结账 / 集合 / PDP：**抽检修复席位预留**（顾问 fail 或本波抽检出问题同回合派工）。
- 技术绿 ≠ 运营过；汇审须 `ops_acceptance=pass`。

## Wave 打造-1 · 工单（立刻开工）

| 优先级 | 工单 ID | 做什么 | 席位 | 依赖 / 边界 |
|--------|---------|--------|------|-------------|
| P0 | `WO-BUILD-OPS-01` | 全站运营抽检 + 图片审查 → `sitewide-ops-acceptance-report.md`（每面 pass/fail + 总 `ops_acceptance`） | **电商顾问**（禁写码） | 宪章 + 面清单；可与施工并行 |
| P0 | `WO-BUILD-THEME-01` | 加固首页汉风商城气质；声明 `work_mode`；**禁止**再对 `.wpc-media` 设 `max-height`；Hero/信任/货架不回归 Wave-3 过签意图 | **主题开发工程师** | 可确定立刻开；触 Theme Token/layout/首页壳；禁拆 chrome |
| P0 | `WO-BUILD-FE-01` | 集合页 + PDP 卡面与加购可达性抽检修复（价签可见、主 CTA 可点、无塌布局） | **部件开发工程师 + 前端** | 可确定立刻开；触商品卡/集合列表/PDP CTA；**禁止**改首页区块总顺序（归主题） |
| P1 | `WO-BUILD-OPS-02` | 顾问 fail 项同回合施工（主题/部件/前端/出图） | 按 report `suggested_seats` | **等 report**；有 fail 立即派，禁只记账 |
| P1 | `WO-BUILD-ASSET-01` | Banner/主图气质换图（按顾问规格） | 主题 / 出图相关 | 顾问规格到位立刻派；未到先 brief |
| P1 | `WO-BUILD-CHK-01` | **预留**：结账路径抽检修复（地址/运费/支付入口） | 前端 + 支付（按需） | 顾问 CHECKOUT fail 或抽检出问题再唤醒 |
| P1 | `WO-BUILD-COL-01` | **预留**：集合页深修（筛选/磁贴/列表密度） | 部件 + 前端 | 顾问 COLLECTION fail 或 FE-01 升级 |
| P1 | `WO-BUILD-PDP-01` | **预留**：PDP 深修（主图 1:1 / 规格 / 详情） | 部件 + 前端 + 内容运营（按需） | 顾问 PDP fail 或 FE-01 升级 |
| P2 | `WO-BUILD-OPS-03` | 施工闭环后顾问复审 fail 面 | 电商顾问 | 施工 closed + done |
| P2 | `WO-BUILD-PM-01` | PM DoD + 汇审（须 `ops_acceptance=pass`） | 项目经理 | 复审 PASS |

## 已知必做（纳入本波，不等空转）

1. **全站运营 fail 项**：顾问 report 未落盘则并行等顾问；确定的店面问题（卡面/加购/气质加固）立刻施工。  
2. **图片/Banner/主图**：审查后换图规格 → 有规格立刻派主题/出图；无规格先顾问 brief。  
3. **结账/集合/PDP**：席位已预留（CHK/COL/PDP）；抽检或顾问 fail 同回合唤醒。

## 文件冲突边界（硬）

| 席位 | 可改 | 禁止 |
|------|------|------|
| **主题**（THEME-01） | 首页 layout / Hero 高度气质 / Token / 信任条与货架相对壳；汉风氛围 | 对 `.wpc-media` 写 `max-height`；拆 chrome；改 Cart 业务数字（包邮 `$49`） |
| **部件+前端**（FE-01） | 集合/PDP/卡面 CSS·结构、加购可达性 | 改首页区块上下总顺序 / Hero 决策（归主题） |
| **电商顾问** | channel 报告 / brief / 规格描述 | **任何业务码** |
| **项目经理** | 排期 / roster / progress / 汇审 | **任何业务码** |

## 唤醒顺序（本席本回合）

1. **电商顾问**：若 `sitewide-ops-acceptance-report.md` 不存在或未完成 → resume/新拉立刻出报告。  
2. **主题开发工程师**：立刻 THEME-01（声明 work_mode）。  
3. **部件+前端**：立刻 FE-01（集合/PDP 抽检修复）。  
4. report 到位后：fail → 同回合派 OPS-02 / ASSET / CHK / COL / PDP。

## 成功标准（本席）

- 排期本文件 + `pm-hanfu-mall-build-progress.md` 落盘。  
- 施工席 **running 或已交付起步**（非只写 md）。  
- 顾问侧报告推进中或已交付。

## 状态

- [x] PM 已写本排期  
- [x] 顾问全站 report（`ops_acceptance=fail`）  
- [x] 主题 THEME-01 派工 → closed  
- [x] 部件+前端 FE-01 派工 → closed  
- [x] fail 续派：HOME-ZERO / ASSET-01 / COL·PDP Browser / CART·CHK Browser（running）  
- [ ] 顾问复审 / PM 汇审（须 `ops_acceptance=pass`）  
