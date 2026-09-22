# 提示词优化 · review（翻译工程师席位升级）

- seat: Team:提示词优化工程师:（design 已由子席同意；本文件父会话施工后语义复审）
- wave: translation-engineer-charter
- date: 2026-09-22
- verdict: **pass**

## 原义对照（用户意图）

| 意图 | 落点 | OK |
|------|------|----|
| 全站区域 vs 活跃/默认 locale 巡检+译修 | `翻译工程师.md` 角色/巡检范围；increment「巡检修复」 | yes |
| 界面 + 流程提示 | 指令角色表 + 巡检范围表 | yes |
| collect → 源串简中 → 对比 → 译 | 标准流程（硬）+ increment | yes |
| 当前仅 zh+en 模块 CSV；禁非中英 CSV；其它语种默认不做 | 当前阶段 CSV（硬）+ surface norms | yes |
| 技能指针 template_i18n + module_i18n_csv + CSV规范 | increment + surface companion | yes |
| 与商品翻译小队边界 | 角色表 + content_ops 节 | yes |
| 席位键 翻译工程师 + i18n 别名 | McpSkillCatalog 双键同 `$translationSeat` | yes |

## 禁丢义 / 禁乱加抽检

- [x] 未削弱 `active_locale_must_show_target_language` / `module_i18n_chinese_source_default` / `module_i18n_csv_collect`
- [x] `user_mentions_translation_all_default_website_locales` 仍指针保留（CSV 仍仅中英）
- [x] 未要求写非中英模块 CSV
- [x] 未把商品翻译并入本席默认范围
- [x] 新增硬规则 `translation_engineer_for_i18n_work` 与用户编制意图同义（强制上场，非另造流程）

## 产物路径

- `dev/ai-command/ai/翻译工程师.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/meetings/提示词优化-design.md`
- MCP：`translation_engineer` surface + seat mirrors
