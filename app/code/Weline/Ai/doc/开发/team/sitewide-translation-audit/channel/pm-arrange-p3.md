# channel — PM 安排 P3 词典波

- date: 2026-09-22
- from: 项目经理
- to: Team:翻译工程师:

## 任务

用户要求「继续，完成所有」。中英波已 closed；本波 **P3**：默认站 **38** 非中英 locale 的 **CJK 源串** 系统词典缺口 → upsert + `publishLocale`。

## 硬规则

- 禁止非中英模块 CSV
- 禁止 Ollama / `ai:translate` 本地模型路径（会打 11434）
- 用自身模型译写；落盘 `LocaleDictionary` + `publishLocale`
- 抽检 ≥1 非中英（`ru_RU` / `de_DE`）店面 chrome 不再露中文源串

## 证据落点

- `channel/wave-p3-dict.md`
- `meetings/翻译-review.md`（P3 段）
- SESSION `sitewide-translation-audit.md` P3 → closed
