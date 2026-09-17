---
status: implementing
work_kind: feature
feature_slug: site-setup-site-dimension-progress
module: Weline_SiteSetupAssistant
related_modules:
  - Weline_Websites
  - Weline_SystemConfig
updated: 2026-09-15
plan_mode: rejected_by_user
---

# 建站助手：站点维度 + 全局总览 + 继承完成态

## 澄清（本轮用户确认）

| # | 确认 |
|---|------|
| 维度 | 建站任务按**站点**；另有**全局总览**分组/视图 |
| 默认页 | Progress **默认全局维度** |
| 全局视图 | 每个任务下列出各站点「未配置 / 已配置（含继承）」 |
| 单站视图 | 左上角/`website_id` 切到某站后，只显示该站任务 |
| 完成态 | 启用类：默认已启用或站点继承全局有效值 → **done**，不得因本站无自有行误判 todo |
| 选择器 | Progress 站点选择 `allow-empty`=全局总览；与请求 `website_id` 对齐（空=全局） |

## 需求纠偏

- `website_id=0` 是**默认网站**，不是「全局总览」。全局总览用**缺省/空** `website_id`（或显式 `dimension=global`）。
- Provider 仍只回答「某一站上下文下的任务状态」；多站矩阵由 Collector 聚合，不把站点清单写进各业务 Provider。

## EARS

1. WHEN Progress 无 `website_id`（或空）THEN SHALL 以全局总览渲染：任务列表 + 每任务的站点覆盖（含默认站 #0）。
2. WHEN Progress `website_id` 为合法站点（含 0）THEN SHALL 仅渲染该站任务，待办靠前。
3. WHEN 启用类配置在全局已开或应用默认已开，且站点未覆盖关闭 THEN SHALL 判定该站该任务 `done`，meta 可标 `inherited`/`default`.
4. WHEN 浮层带 `website_id` THEN SHALL 用单站 collect（与切站一致）；无站上下文时可不进入全局矩阵（浮层保持单站简洁）。

## UC

- UC1 运营打开建站助手菜单 → 默认见全局：任务「在线客服」下显示站 A 已配置、站 B 继承全局、站 C 未开。
- UC2 切到站 B → 列表变为站 B 任务；继承启用的项为已完成。
- UC3 站 B 显式关闭客服 → 该站 todo；全局矩阵中站 B 显示未配置/已关闭。

## fe_be_scope

- BE：Abstract helpers（resolveEffective / isTruthy）、Collector `collect` + `collectGlobalOverview`、Progress 控制器维度、启用类 Provider 修判定
- FE：Progress 模板双模式；去掉与维度冲突的「仅本页重复切站」语义（保留 Taglib 选择，空=全局）
- ui_skill_decision: participate（进度页信息架构变更）

## 架构

- mechanism: Extends Provider 单站自检 + Collector 多站聚合；配置用 `resolveConfig` 继承链 + 业务默认值
- owning_module: Weline_SiteSetupAssistant
- reuse: ConfigStore/SystemConfig::resolveConfig、Website 列表、WebsiteSelect allow-empty
- invent: overview DTO `site_coverage[]`；dimension=global|site
- not_to_do: 各 Provider 自己扫全站；浮层硬编码；把 website_id=0 当全局

## 契约字段（任务行）

单站（现有 + meta）：
- `status`, `tip`, `meta.configured`, `meta.inherited`, `meta.source_scope`, `meta.effective`

全局总览任务行额外：
- `dimension`: `global`
- `site_coverage`: `list<{website_id,label,status,inherited,tip}>`
- `status`: 汇总（全 done→done；有 todo→todo；仅 doing→doing）
- `tip`: 如「3/5 站已就绪」

## 就绪

- [x] 澄清
- [x] EARS + UC
- [x] Plan 被拒已记录，agent 内落笔后实现
