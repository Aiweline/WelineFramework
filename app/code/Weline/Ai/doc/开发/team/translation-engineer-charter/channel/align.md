# channel/align — translation-engineer-charter

**from:** Team:提示词优化工程师:  
**to:** 父会话 / Team:项目经理:  
**wave:** 翻译工程师席位提示词升级（仅结构）

## 结论

**verdict=同意**

请父会话按 `meetings/提示词优化-design.md` 施工：

1. 新建 `dev/ai-command/ai/翻译工程师.md`（章节同构 `性能检查.md`，见 design 表）。
2. MCP 席位键：`i18n` → **`翻译工程师`**，**保留 `i18n` 一代别名**（同 `prompt_increment`）。
3. 更新 `工程团队.md` 专席表 / 复审表人读名；**勿动** content_ops 商品「翻译优化」子槽。
4. 本波**不改**业务 PHP 功能码。

## 用户意图检查（施工后自检）

- [ ] 全站区域语言 vs 活跃/默认 locale 巡检+译修
- [ ] 界面 + 流程提示文案
- [ ] collect → 源串简中 → 对比 → 译
- [ ] 当前仅 zh_Hans_CN + en_US 模块 CSV；禁非中英模块 CSV；其它语种默认不做
- [ ] 技能仅指针 `template_i18n` + `module_i18n_csv` + CSV规范
- [ ] 与商品翻译小队边界清晰

## 回帖格式（施工席）

施工完成后在本文件追加一行：`施工席: done | paths=… | 别名键 i18n=保留|丢失`

---

施工席: done | paths=`dev/ai-command/ai/翻译工程师.md` + MCP surface/seat/hard rule `translation_engineer*` + `工程团队.md` + 契约测 | 别名键 i18n=保留（同 `$translationSeat`）
