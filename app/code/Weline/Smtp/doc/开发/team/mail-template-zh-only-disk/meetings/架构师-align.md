# 架构师对齐 — mail-template-zh-only-disk

updated: 2026-09-23  
stance: **approve**

## 机制冻结

- Extends `MailChannelProvider` → `default_templates`（不变）
- 磁盘实体：**仅** `zh_Hans_CN.html` / `.subject.txt` + `shell.phtml`
- 其它语种（含 `en_US`）：`mail_template_seed_copy.json` → `MailTemplateSeedCopyCatalog::forSlug` → `MailTemplateSeeder` → `SmtpMailTemplate`
- 运行时：`MailTemplateResolver` 只读库
- **禁止** `materializeFiles` 写 `view/email/{locale}.*`

## 否决项

- 无。不新造平行文件存储或旁路表。

## 与 mail-template-all-locales 关系

- 全语种覆盖目标保留（库行）；**写盘物化路径作废**，改为内联种子进库。

notify_pm: true  
@项目经理：本席已交付，请检查并更新 SESSION
