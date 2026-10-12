# 规格 · Cloudflare Always Online 后台开关

---
status: ready-for-plan
work_kind: feature
feature_slug: cdn-cf-always-online-toggle
module: Weline_Cdn
updated: 2026-10-12
session_path: ../../../../../../../dev/session/cdn-cf-always-online-toggle.md
---

## 背景

`Cloudflare::putRules` 成功后硬编码 PATCH zone `always_online=on`。运维需要在 CDN 系统配置开关 Always Online，并在规则推送时同步到 CF（源站对 CF 挂掉时可吐快照；≠ 本地冷盘 FPC）。

## 已冻结决策

| ID | 决议 |
|----|------|
| 配置键 | `cdn/cloudflare/always_online`（bool，默认 `1`） |
| Scope | `global,website`（对齐 OAuth） |
| 推送时机 | 仅 `putRules` 成功后同步；保存配置不扫全 zone |
| API | zone settings `always_online` on/off；非 Cache Rules |
| 失败策略 | Always Online 同步失败不回滚已推送规则 |

## 用户故事

### US-1 后台开关

As a 站点管理员, I want 在 CDN 系统配置开关 Cloudflare Always Online, so that 我可以控制是否在规则推送时把 zone Always Online 设为 on/off。

**EARS**

1. WHEN 配置页加载 Weline_Cdn backend SystemConfig，THEN 系统 SHALL 展示 Always Online 开关且默认开启。  
2. WHEN 管理员保存开关值，THEN 系统 SHALL 持久化 `cdn/cloudflare/always_online`（global/website）。

### US-2 规则推送同步

As a 运维, I want 推送 Cloudflare Cache Rules 成功后按配置同步 Always Online, so that zone 设置与后台一致。

**EARS**

3. WHEN `putRules` 成功且配置为开启，THEN 适配器 SHALL PATCH `always_online=on`。  
4. WHEN `putRules` 成功且配置为关闭，THEN 适配器 SHALL PATCH `always_online=off`。  
5. IF Always Online PATCH 失败，THEN 系统 SHALL NOT 回滚已成功的规则推送，并在回执中标记 `always_online.ok=false`。

## 用例

| id | 名称 | 期望 |
|----|------|------|
| UC-1 | 配置可见 | CDN 系统配置含 Always Online 开关，默认开 |
| UC-2 | 契约 | `CloudflareAlwaysOnlineContractTest` + 配置页契约 PASS |
| UC-3 | 同步 | 有 CF 凭据时可选手动验回执；无凭据则 skip 并记 SESSION |

## 非目标

- 不把 Always Online 写成 Cache Rules 条目  
- 不改本地冷盘 / Nginx stale  
- 不在保存 SystemConfig 时立刻遍历全部 zone 推送  
