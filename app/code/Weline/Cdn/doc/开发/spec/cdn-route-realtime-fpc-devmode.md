# 规格 · CDN 新路由识别 + Scope 开发模式绕过 FPC

---
status: ready-for-plan
work_kind: feature
feature_slug: cdn-route-realtime-fpc-devmode
module: Weline_Cdn
updated: 2026-09-28
session_path: ../../../../../../../dev/session/cdn-route-realtime-fpc-devmode.md
clarify_path: ../../../../../../../dev/team/cdn-route-realtime-fpc-devmode/meetings/2026-09-28-clarify.md
correct_path: ../../../../../../../dev/team/cdn-route-realtime-fpc-devmode/meetings/2026-09-28-pm-correct-extra-fpc.md
reuse_path: ../../../../../../../dev/team/cdn-route-realtime-fpc-devmode/meetings/2026-09-28-framework-reuse.md
---

## 背景

用户要：（1）新增路由立即被 WLS FPC 与 Cloudflare 识别；（2）CDN 系统配置按 Scope 开「开发模式」暂时绕过 FPC。  
**纠偏（用户确认）**：源站 FPC 是否可缓存，以控制器 **`@Extra type=fpc`** 为准，禁止另造旁门。

## 已冻结决策

| ID | 决议 |
|----|------|
| FPC 主链 | `@Extra type=fpc enabled/ttl/namespaces/public_path_patterns` → Extra 收集侧车 → Coordinator |
| CF Free「识别」 | **选项 A**：不按路由推 Cache Rule；靠 `default-rules` + FPC HIT `CDN-Cache-Control` |
| Scope | 复用 CDN Config 页已有 `SystemConfigTargetScopeService` / `<w:scope>` 三级 |
| 开发模式 | **仅绕过 FPC**（overlay）；不改写控制器 Extra；不改 CF 规则集；可选顺带 `X-Weline-Cache-Bypass` |
| Free `@Cdn` 推送 | 注解可入库；**默认不**全量 PUT CF（闸门）；与 Extra 正交 |

## 机制三分（硬）

| 轨 | 声明 / 配置 | 管什么 | 本需求 |
|----|-------------|--------|--------|
| **Extra FPC** | `@Extra type=fpc …` | **源站**是否 FPC、TTL、失效 ns | **主链**：新路由必须声明才被 FPC 策略认到 |
| **CDN 边缘** | `default-rules` / `@Cdn` | **CF Cache Rules** | Free：维护默认 6 条；`@Cdn` 不替代 Extra |
| **开发模式** | CDN SystemConfig（Scope） | **临时旁路** FPC | 缺口：Provider + facts 注入 |

## 框架已有 · 必须复用（禁止重造）

详见 `meetings/2026-09-28-framework-reuse.md`。摘要：

| 能力 | 已有机制 | 本需求用法 |
|------|----------|------------|
| FPC 读策略 | `FpcExtraType` / `CollectControllerExtra` / `ExtraPolicyResolver` / `controller_extra.php` | 新路由声明 Extra；收集挂 `after_route_collection` |
| FPC 旁路声明 | `FpcBypassRuleProviderInterface`（Theme/Server 已有示例） | CDN 新增 Provider，`env_flags=['cdn_fpc_dev_mode']`，对齐 Theme `editor_mode` |
| 旁路侧车 | `CollectFpcBypassRules` → `fpc_bypass_rules.php` | Provider 进侧车；热路径只读 Evaluator |
| facts 注入 | `FullPageCacheCoordinator` 组装 `env.editor_mode` | **同模式**注入 `env.cdn_fpc_dev_mode`（按请求 Scope 读配置） |
| Scope 配置页 | `Backend\Config` + `SystemConfigTargetScopeService` | 新键落同一页，不新开 Scope UI |
| CF 默认规则 | `default-rules.json` + `pushDefaultRules` | 店面桥接继续用；不走合并全量 |
| 边缘旁路头 | `X-Weline-Cache-Bypass` + default-rules #4 | 开发模式可选出站带此头 |
| 传输旁路 | `x-wls-fpc-bypass`（Server Provider） | 不混用；开发模式走 env_flag |
| 失效写路径 | `w_changed` → `FpcCapability` / CDN purge | 不改；开发模式不做 purgeAll |
| `@Cdn` 收集 | `ControllerAnnotationRulesCollector` / `CdnRuleCollector` | 与 Extra **并列**；Free 加推送闸门 |

## 用户故事

### US-1 新路由被 FPC / CF 识别

As a 店面开发者, I want 在控制器上声明 `@Extra type=fpc` 并完成路由收集后，该路径被 WLS FPC 按策略缓存，且 FPC HIT 时可被 CF 默认规则进边缘, so that 我不必手写单路径 CF Rule。

**EARS**

1. WHEN 控制器声明 `@Extra type=fpc enabled=true` 且 Extra 已收集进侧车，THEN 系统 SHALL 按该策略允许对应 `public_path_patterns` 走 FPC serve/build（与既有店面页一致）。  
2. WHEN `@Extra type=fpc enabled=false`（或未声明且策略不适用），THEN 系统 SHALL NOT 将该路径当作可公共 FPC 页（不得假装「默认全店面可 FPC」）。  
3. WHEN 路径 FPC HIT 且出站带 `CDN-Cache-Control`，THEN CF SHALL 可按已推送 `bypass_by_default` 进边缘，**无需**新增该路径专用 Cache Rule。  
4. IF 仅声明 `@Cdn` 而未声明 `@Extra type=fpc`，THEN 系统 SHALL NOT 将其等同为「已启用源站 FPC」。

### US-2 Scope 开发模式绕过 FPC

As a 站点管理员, I want 在 CDN 系统配置按 Scope 开启开发模式, so that 该范围请求暂时旁路 FPC，且不改控制器 Extra 声明。

**EARS**

5. WHEN 在选定 Scope 开启开发模式并保存，THEN 匹配该 Scope 的后续请求 SHALL bypass FPC（facts.env 命中 Provider）。  
6. WHEN 关闭开发模式，THEN 在无其他旁路时 SHALL 恢复以 `@Extra type=fpc` 为准的 FPC 行为。  
7. WHILE 开发模式开启，系统 SHALL NOT 修改 Extra 侧车中的控制器声明，SHALL NOT 自动改写 CF default-rules。

## 用例

| id | 名称 | 期望 |
|----|------|------|
| UC-1 | 新页声明 Extra 后 FPC | 路由收集后二次请求可 `x-weline-fpc: HIT`（无其他旁路） |
| UC-2 | Extra enabled=false | 不按公共 FPC HIT |
| UC-3 | FPC HIT → CF | 有 CDN 头时 `cf-cache-status` MISS→HIT；无新单路径 Rule |
| UC-4 | 仅 @Cdn 无 Extra | 不视为已开源站 FPC |
| UC-5 | Scope 开开发模式 | 该 Scope 旁路 FPC；Extra 声明仍在 |
| UC-6 | 关开发模式 | 恢复 Extra 策略 |
| UC-7 | 他 Scope 隔离 | 未开 Scope 仍按 Extra |

## 非目标

- 不为每条新店面路径强制新增 CF Cache Rule（Free 选项 A）。  
- 不重做 `@Extra` / `FpcExtraType` 语法。  
- 不重做 `@Cdn` 注释语法（仅闸门与文档纠偏）。  
- 不把开发模式做成改 CF 规则集或全站 purge。  
- 禁止 Evaluator 热路径读 SystemConfig/OM。  
- 不合并 Theme 编辑器旁路与 CDN 开发模式为一个开关。

## 施工归属（计划用）

| 包 | 模块 | 要点 |
|----|------|------|
| P1 文档/闸门 | Cdn | 指南区分 Extra vs Cdn；`pushRealtimeRule`/合并推送 Free 闸门 |
| P2 开发模式 | Cdn + Framework 最小 | SystemConfig 键；`CdnFpcDevModeBypassProvider`；Coordinator facts 注入 |
| P3 验收 | 测试 | UC-1…7；Free 推送体无 `matches`、≤10 |

## 相关入口

- 后台：`cdn/backend/config`  
- Extra 权威：`Framework/doc/开发/spec/controller-extra-fpc.md`、`controller-extra-fpc/README.md`  
- FPC 旁路：`Framework/doc/开发/FPC上收Framework与Store适配器.md`

## 澄清记录

| 日期 | 来源 | 结论 |
|------|------|------|
| 2026-09-28 | 用户原话 | 初澄清 + OPT |
| 2026-09-28 | 用户纠偏 Extra | FPC 以 `@Extra type=fpc` 为准 |
| 2026-09-28 | 用户确认纠偏 + 复用盘点 | 本规格 `ready-for-plan`；冻结 A + Scope 三级 + 仅 FPC overlay |
