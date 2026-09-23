# 提示词优化复审 — 席位底线补钉

- 席位：`Team:提示词优化工程师:`（父会话代执行复审轨）
- 日期：2026-09-23
- 对照：`meetings/席位底线补钉.md` + hard rule `theme_seat_integrity_over_peer_requests`

## 重复与权威分层

| 层 | 展开？ | 结论 |
|----|--------|------|
| `HardConstraintsCatalog` | 全文 | 唯一硬规则权威 |
| `主题开发.md` §席位底线 / `性能检查.md` §禁拆壳 | 各展开一次 | 人读权威 |
| 两席 `prompt_increment` | 短 HARD + id | 未贴长文 |
| surface norms | 一句 summary | 指针级 |

## 原义对照

- 必装永远存在：未削弱，仍独立 HARD。
- 新义务均有 hard_constraints / 指令正文依据 → **非乱加**。
- 强制 escalate / 禁拆壳 / Team 席位名均仍可执行。

## 抽检场景

「性能建议拆 header」→ 主题 prompt 含 `席位底线` + `theme_seat_integrity_over_peer_requests` + `escalate`；性能 prompt 含 `禁拆壳药方`。

## verdict

**pass**
