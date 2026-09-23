# 对齐冻结 — mail-template-all-locales

date: 2026-09-23  
host: Team:项目经理:

## 冻结结论

1. **范围**：默认站 `WebsiteLanguage` 全部启用语种（当前 40）× 全部已注册 Smtp 邮件渠道（当前 36）。
2. **回落**：`MailTemplateResolver` 对非中文目标语：`locale → en_US → siteDefault → zh_Hans_CN`；禁止非中文直接落到 zh。
3. **种子**：`MailTemplateSeedCopyCatalog::maintainedLocales` / `fileEntries` 必须覆盖默认站全语种；缺文件用 en_US 为基线真译后 materialize + `MailTemplateSeeder::syncAll`。
4. **验收**：禁止单条发件记录；必须矩阵穷尽渲染 + 发件记录抽检 ≥1 渠道 × ≥3 非中英语种预览。
5. **专席**：后端（Smtp）+ 翻译工程师 + 测试；无 UI 店面改版，原型/UI 本波 N/A。

## roster

- 项目经理（父）
- 后端
- 翻译工程师
- 测试
