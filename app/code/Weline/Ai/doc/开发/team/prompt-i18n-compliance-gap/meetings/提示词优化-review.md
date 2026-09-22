# 提示词优化-review — prompt-i18n-compliance-gap

**verdict**：`pass`

## 原义对照抽检

| 原强制项 | 改后是否仍可执行 |
|----------|------------------|
| 模块 CSV 仅 zh+en；禁非中英 CSV | yes（格式边界措辞保留） |
| `active_locale_must_show_target_language` | yes（显式对齐；禁英文回落） |
| `user_mentions_translation_all_default_website_locales` | yes（指针保留） |
| Team:翻译工程师: 强制上场 | yes（扩到「改用户可见文案」同波） |
| 电商顾问禁写码 / findings_wake_pm | yes |
| 双轨产物 meetings/* | yes（本目录 design+review） |

## 压缩合法性

1. 有重复证据 ≥2 处 → 已写 design  
2. 未削弱硬规则 id / 否决项  
3. 未开放非中英模块 CSV  
4. 新增语义仅为澄清误导措辞 + 顾问面清单指针（对齐既有 hard rules，非发明平行流程）  
5. 未整段粘贴技能正文  

## 契约测

```bash
php app/code/Weline/Ai/Mcp/tests/guidance-workflow-contract.php
php app/code/Weline/Ai/Mcp/tests/mcp-skills-catalog.php
```

（执行结果见本席回报 `tests`）

## fail 返工项

无（pass）。
