# contracts — policy-copy-tone-soften

## 冻结依据

- 用户需求：合规政策页语气去生硬化 + 翻译同步
- 顾问 brief：`meetings/电商顾问-ops-brief.md`（stance=同意改语气；recommendation=A）
- 规格：`doc/开发/spec/policy-copy-tone-soften.md`（需求分析并入顾问约束后 `align_freeze_ready`）

## 施工契约

| plan_id | 席位 | 交付物 | 禁止 |
|---------|------|--------|------|
| theme-copy | 主题开发工程师 | 按 brief 改 `layouts/policy/{privacy,term-condition,refund,cookie,shipping,disclaimer,accessibility,default}.phtml` 内 `WidgetI18n::label` **简中源串**；同步 `Theme/i18n/zh_Hans_CN.csv` + `en_US.csv`；相关 UT 源串契约同步；`php bin/w i18n:collect Weline_Theme` | 改布局壳/结构/Token/CSS；削弱合规披露；改回无理由卖点；非中英模块 CSV |
| i18n-dict | 翻译工程师 | collect→对比；默认站其它已选 locale 系统词典 upsert+publishLocale；抽检 ≥1 非中英政策页 | 写非中英模块 CSV；代改 phtml 布局；代跑商品翻译优化 |
| ops-accept | 电商顾问 | Browser 禁缓存抽检；`ops_acceptance` + `issuer_acceptance` | 写码 |

## UC（执行意图冻结）

- UC-1…5 见规格；顾问 ops_acceptance 六条见 brief。
- 商家友好退换口径不可逆。

## related_web_urls

`/policy/privacy` `/policy/term-condition` `/policy/refund` `/policy/cookies` `/policy/shipping` `/policy/disclaimer` `/policy/accessibility`
