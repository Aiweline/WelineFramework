# mail-template-zh-only-disk — 规格

status: frozen  
module: Weline_Smtp  
updated: 2026-09-23

## 背景

邮件多语实体文件（`view/email/{locale}.html`）进 Git 造成巨大噪音，且与「模板译文在库」模型冲突。

## 用户硬约束

1. Git/磁盘实体只保留默认中文：`zh_Hans_CN.html` + `zh_Hans_CN.subject.txt`（及 `shell.phtml`）。
2. 其它语种（含 `en_US`）种子生成后写入 `SmtpMailTemplate`，禁止落多语实体文件、禁止进 Git。
3. 运行时仍由 `MailTemplateResolver` 读库。

## EARS

- WHEN 种子/upgrade/后台列表同步 THEN 系统 SHALL 仅为 zh 读取模块磁盘文件，其它 locale SHALL 由 `mail_template_seed_copy.json` 渲染内联 subject/body 并 upsert 库表。
- WHEN 任何代码路径调用旧 `materializeFiles` THEN 系统 SHALL 不写 `view/email/{locale}.*`（方法删除或永久 no-op）。
- WHILE 仓库处于清洁状态 THEN `view/email` 下 SHALL 不存在非 zh_Hans_CN 的 locale 命名 html/subject（白名单样例除外）。

## UC

| id | 名称 | 验收 |
|----|------|------|
| UC-1 | 磁盘洁净 | 除白名单外无非 zh locale 邮件实体 |
| UC-2 | 库行覆盖 | syncAll 后默认站启用 locale × 渠道有行 |
| UC-3 | 契约 | 中文文件存在；Upgrade/Template 无 materializeFiles |
| UC-4 | 非中英抽检 | de_DE/ru_RU 等渲染无 CJK 叙述漏出 |
