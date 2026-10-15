---
name: ui-driven-dev
description: >-
  Develop against a reference UI: after EACH feature slice, Browser cache-off
  screenshot → Read image → diff vs mock → fix until aligned, then next slice.
  Triggers: 按UI开发, 对照UI开发, design to code, ui-driven.
aliases:
  - weline-ui-driven-dev
  - ui_driven_development
  - ui-driven-dev
---

# ui-driven-dev · 按 UI 开发

## 权威

| 层 | 路径 |
|----|------|
| 指令 | `dev/ai-command/theme/按UI开发.md` |
| 互补 | `dev/ai-command/theme/审图.md`（用户附图 / 对稿 fail 联合流水线） |
| MCP surface | `ui_driven_development` |

## 强制循环

1. 钉参考 UI（无稿先问用户）。  
2. 只做一个功能切片 → 实现。  
3. Browser 禁缓存 → 截图 → **Read** → 对照参考列差异 → 改到对齐。  
4. 对齐后再做下一切片。

## 禁止

无参考假装对稿；拍了不 Read；curl/metrics 冒充视觉 PASS；未对齐就扩大下一刀。
