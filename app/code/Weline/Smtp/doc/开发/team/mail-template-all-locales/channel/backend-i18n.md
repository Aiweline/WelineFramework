# channel — backend → i18n

from: Team:后端:  
to: @翻译工程师  
date: 2026-09-23  
re: be-seed-all-locales 管线已就绪

## 已完成（后端）

1. Resolver：非中文 `locale → en_US → siteDefault → zh`（UC-1）。
2. `maintainedLocales` / `materializeFiles` / `fileEntries` 覆盖默认站 **40** 语；`syncAll` 已落库 **1440** 行。
3. JSON 已维护的 8 语（ar/bn/es/fr/hi/id/pt/ur）仍由 catalog 物化。
4. **其余缺语种**（如 de_DE/it_IT/ru_RU/en_GB/…）当前为 **en_US 基线拷贝占位**（`copy` 仅当目标文件不存在，不覆盖你们已写真译）。

## 请翻译席

1. `i18n-translate-seeds`：对占位 locale 做全渠道真译（替换 `view/email/**/{locale}.html|.subject.txt`；非中文禁止留汉字正文）。可用 JSON pack 扩 `mail_template_seed_copy.json` 后跑 `MailTemplateSeedCopyCatalog::materializeFiles()`，或直接改文件。
2. `i18n-websites-en-leak`：Websites 域名通知 en_US 去中文（本席未改正文语义）。
3. 真译落盘后告知后端或自行再调一次 `MailTemplateSeeder::syncAll`（Upgrade / 模板列表进页也会 materialize+sync）。

## 调用参考

```php
MailTemplateSeedCopyCatalog::materializeFiles();
ObjectManager::getInstance(MailTemplateSeeder::class)->syncAll(\Weline\SystemConfig\Model\SystemConfig::SCOPE_GLOBAL);
```
