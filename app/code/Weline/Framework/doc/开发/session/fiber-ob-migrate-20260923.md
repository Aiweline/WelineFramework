---
slug: fiber-ob-migrate-20260923
module: Weline_Framework
mode: team
wave: design
status: open
updated: 2026-09-23
last_checked_by: 项目经理
spec_path: N/A
team_path: ../team/fiber-ob-migrate-20260923/
---

## 当前阶段

- 交付流程阶段：`2 设计`（机制冻结）
- team 波次：`design`
- 一句话进度：用户要求**全部**迁移原生 `ob_*` → `FiberOutputBuffer`；生产盘点约 44 处 / 多模块；架构冻结分类中。

## 计划项表

| plan_id | 来源 | 负责人席 | 状态 | 验收指针 | 关闭条件 |
|---------|------|----------|------|----------|----------|
| main | 用户：全部迁移 FiberOutputBuffer | 项目经理汇总 | open | 盘点清零（允许冻结例外） | 汇审 + 契约 UT |
| P1-inventory | 盘点 | 项目经理/架构 | closed | meetings/inventory-raw.txt | 生产路径清单 |
| P1-architect | 机制 | 架构师 | assigned | surfaces + 架构纪要 | 分类 A/B/C + 例外清单冻结 |
| P2-fix-fw | Framework/Taglib 热路径 | 后端 | open | FiberOutputBuffer 调用 | A 类清零 + UT |
| P2-fix-theme | Theme Slot/Chrome/SlotFiller | 主题开发工程师 | open | Theme 升版 | A 类清零 + UT |
| P2-fix-peers | Search/I18n/其它 A 类 | 后端(+归属) | open | 各模块 | A 类清零 |
| P3-review | 复审 | 架构师+性能 | open | review | 无进程级抓模板 ob；店面抽检 |

## 未完成清单

- P1-architect / P2-* / P3-review

## 盘点摘要（生产 · 排除 Test 与 FiberOutputBuffer 自身）

热路径 HTML（优先）：Theme `Slot.php` / `ThemeLayoutEntityChrome` / `ThemeLayoutEntitySlotFiller`；Search `Taglib/Search`；I18n `LanguageSelect`；DevToolPanel。  
二进制 GD（imagepng 等）：Captcha / Admin Login / MediaManager AiDraw / Product script。  
其它：SSE、FpmEmitter、BinaryOutputGuard、Maintenance、Dictionary、PcController。

## 相关入口

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products
- https://p05113ef3.test.weline.com:9555/guide/returns
