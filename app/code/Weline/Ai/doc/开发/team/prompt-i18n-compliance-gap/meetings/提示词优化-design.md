# 提示词优化-design — prompt-i18n-compliance-gap

**stance**：同意（澄清误导措辞，对齐既有硬规则；不削弱 `active_locale_must_show_target_language`；不开放非中英模块 CSV）

## 本波根因

合规整改后 `/ru_RU/faq` 仍英文；后补才想起翻译。提示词把「模块 CSV 仅中英」误写成「其它语种默认不做」，与活跃语须目标语冲突。

## 重复 / 冲突证据（≥2 处同义错误展开）

| # | 路径 | 摘句 / 语义 |
|---|------|-------------|
| 1 | `dev/ai-command/ai/翻译工程师.md` §C | 「其它语种**默认先不做**」「未明示全语种 → 默认只做中英」 |
| 2 | `dev/ai-command/ai/工程团队.md` 席位表 / 分配硬规则 / 席位镜行 | 「当前阶段仅 zh+en」当施工范围 |
| 3 | `HardConstraintsCatalog` `translation_engineer_for_i18n_work` | 「other locales default skip unless user_mentions_translation…」 |
| 4 | `McpSkillCatalog` 翻译席 `prompt_increment` + `GuidanceWorkflowCatalog` surface / `i18n_zh_en_csv_only_this_phase` | 「其它语种默认先不做」 |
| 权威对照 | `active_locale_must_show_target_language` + `user_mentions_translation_all_default_website_locales` | 活跃语须目标语；用户提翻译→默认站全语种（中英 CSV，其它进词典） |

**第二漏检**：`电商顾问.md` 合规检查只强调政策/宣称，未点名店面文案面（政策页/顶栏/FAQ Hub·实体/Cookie）；`suggested_seats` 未强制含翻译工程师 → PM 只派主题改 CSV → 漏词典与 FAQ 实体多语。

## 改写方案（指针化；禁削弱）

1. 「仅中英」**只**描述模块 CSV 格式边界；**禁止**再写成「其它语种默认不做」。
2. 工程改用户可见文案 → 同波上场翻译工程师；解析默认站 `language_codes`；zh/en→CSV+collect；其它→词典/实体；抽检≥1 非中英。
3. 电商顾问合规面增量 + escalate `suggested_seats` 须含翻译工程师（改可见串时）。
4. MCP catalog / surface / 契约测同步。

## 权威落点（改后仍保留）

- 模块 CSV 仅 `zh_Hans_CN` + `en_US`（禁非中英模块 CSV）
- 非中英 → 系统词典 / FAQ 实体 locale 行
- `active_locale_must_show_target_language` 不削弱
- `user_mentions_translation_all_default_website_locales` 不削弱

## 禁止

- 允许非中英模块 CSV
- 大段复制技能正文
- 改业务 PHP
