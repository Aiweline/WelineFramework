# channel — PM 排期：汉服站遗留缺陷修复

日期：2026-09-23  
发起：`Team:项目经理:`  
背景：Wave 打造-1 `ops_acceptance=pass` · 汇审 PASS；本文件只排遗留，不返工已过签项  
验收 Host：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`  
硬约束：**`$49` 包邮只读不改** · PM 禁改业务 PHP · 禁拆 chrome 壳

`notify_pm: true`

## 决策摘要

| 优先级 | 工单 | 做什么 | suggested_seats | 状态 / 依赖 |
|--------|------|--------|-----------------|-------------|
| **P0** | WO-BUILD-REACH-01 | 本机 9555 HTTP worker 恢复（Master 空听 → worker 可响应） | 父会话运维 | **父已做** `server:restart -r`；本席只盯可达，不派码 |
| **P0** | WO-HP-P3-MALL-07 | 默认访客中文（根路径 / 默认 locale → `zh_Hans_CN`） | `@前端` · 配置侧可协同 | Codex 探索挂图路径中；**可达后立刻开工** |
| **P0** | WO-BUILD-PAY-HYDRATE | 支付水合深验 + 断点修（非只读笔记） | `@Team:支付开发工程师:` | 依赖 REACH 通；修后测席复跑结账主链 |
| **P1** | WO-BUILD-ASSET-02 | 全量主图：用**已有样张**先挂核心 SKU（不全量新生成） | 内容运营 · **主图优化**（**SKIP MCP**） | 宿主 Read `dev/ai-command` + `doc/ai/skills`；禁 prepare |
| **P1** | WO-BUILD-COL-01 / PDP-01 | 仅修**可买阻塞**（加购/价/可达）；**不做**杂志深修整页重做 | `@前端` `@主题开发工程师:` | 与 ASSET-02 可并行；边界见下 |

## 唤醒顺序

1. **REACH-01** — 父会话恢复可达；PM 确认 Host 探活后再放行下列 P0。
2. **并行 P0** — `@前端：请立刻开工 MALL-07` · `@Team:支付开发工程师：请立刻开工支付水合深验与断点修`
3. **并行 P1**（REACH 通即可，不挡 P0 收口）— 内容运营 ASSET-02 · `@前端`/`@主题开发工程师：COL/PDP 可买阻塞`（窄修）
4. 各席 `done` channel + `notify_pm: true` → 测席抽检 → 顾问按需补签 → PM 记进度（本波不强制新汇审，除非顾问驳回）

## 文件 / 范围边界（硬）

| 工单 | 可做 | 禁止 |
|------|------|------|
| MALL-07 | 默认 locale / 访客语言配置与前端跳转 | 改 `$49` 阈值；整站 i18n 大扫 |
| PAY-HYDRATE | 水合断点、结账支付壳修复 | 改运费/包邮门槛；扩支付 Provider |
| ASSET-02 | 已有样张挂核心 SKU 主图 | 无样张批量 AI 生成整库；改业务 PHP |
| COL/PDP | 可买阻塞（空价、加购不可达、致命布局挡 CTA） | 杂志风整页重做、深修审美整页 |

## DoD（短）

- [ ] Host `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/` 可达（非 Master 空听）
- [ ] 默认访客进站为中文（MALL-07）
- [ ] 结账支付水合深验 pass 或已修断点 + 复跑证据
- [ ] 核心 SKU 主图已挂（样张路径可追溯）
- [ ] COL/PDP 可买主链无阻塞；杂志深修明确 **out of scope**
- [ ] `$49` 包邮文案/阈值未改

## notify_pm · @席位

`notify_pm: true`  

**suggested_seats**

- `@前端` — WO-HP-P3-MALL-07；WO-BUILD-COL-01/PDP-01（可买阻塞）
- `@Team:支付开发工程师:` — WO-BUILD-PAY-HYDRATE
- `@主题开发工程师:` — COL/PDP 窄修协同（勿整页杂志重做）
- 内容运营（主图优化 · SKIP MCP）— WO-BUILD-ASSET-02
- `@Team:测试:` — REACH 通且各席 done 后由 PM 再唤醒抽检

**@前端 @Team:支付开发工程师：请立刻开工（P0；等 REACH 通）** · **@主题开发工程师：P1 COL/PDP 窄修待命** · 内容运营 ASSET-02 可与 P1 并行
