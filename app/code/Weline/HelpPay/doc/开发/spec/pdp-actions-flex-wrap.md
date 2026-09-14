---
status: ready-for-plan
work_kind: feature
feature_slug: pdp-actions-flex-wrap
module: Weline_HelpPay
updated: 2026-09-14
---

# PDP 购买次级钮瀑布流

## 澄清

| 问题 | 答案 |
|------|------|
| 问题 | 分享/快捷购买被两列等宽固定，长文案（Share with friends）钮内折行 |
| 目标 | 按文案定宽；一行放不下则整钮换行；主购买仍通栏 |

## EARS

1. WHEN 购买槽存在次级钮，系统 SHALL 用 flex-wrap 排布，次级钮宽度随文案且 `nowrap`。
2. WHEN 一行放不下两个次级钮，系统 SHALL 将后一钮换到下一行（非整行等分挤折字）。
3. WHEN 主购买（加购/结账）存在，系统 SHALL 仍各占通栏。
