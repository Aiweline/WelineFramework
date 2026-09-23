# mail-template-zh-only-disk — 对齐冻结契约

updated: 2026-09-23  
status: frozen

## 机制

- Extends：`MailChannelProvider` → `default_templates`
- 种子：`MailTemplateDefaultLocales::fileEntries` 产出 zh 文件路径 + 它语内联 subject/body
- 文案包：`MailTemplateSeedCopyCatalog` + `Service/data/mail_template_seed_copy.json`（含 en_US）
- 落库：`MailTemplateSeeder::syncAll` → `SmtpMailTemplate`
- 解析：`MailTemplateResolver`（不变）

## 禁止

- `materializeFiles` 写多语实体进 `view/email/`
- 非 `zh_Hans_CN` 的 `{locale}.html` / `{locale}.subject.txt` 进 Git

## 席位

| 席 | 交付 |
|----|------|
| 架构师 | 机制确认 |
| 后端 | API/清理/gitignore |
| 翻译工程师 | en_US pack + 抽检 |
| Setup | 升版 + syncAll |
| 测试 | UC-1~4 |
| 文档 | Smtp doc 叙事 |
