# 前端收口 · 订阅名单搜索 + 中英 CSV（2026-09-22）

## 现象
- 后台订阅名单搜索：全宽竖叠、英文「Search email」；模块 CSV 未按中英双文约定写好。

## 处置
1. `subscriber/index.phtml`：标题行右侧 `w-cluster` 紧凑横排（max 28rem，sm 控件）。
2. `i18n/zh_Hans_CN.csv` / `en_US.csv` 齐写；全量 `i18n:collect`。
3. MCP：前端席 `template_i18n`+`module_i18n_csv`；硬规则 `frontend_ui_requires_zh_en_csv`；`工程团队.md` 前端镜同步。
4. 修 `Phrase\Parser`：zh 身份译不得回落 en；订正 DB `w_i18n_locale_dictionary` zh 误英。

## 验收
- 中文 locale：placeholder/aria「搜索邮箱」；表头中文；紧凑工具条。
- URL：`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/newsletter/backend/subscriber/index`
