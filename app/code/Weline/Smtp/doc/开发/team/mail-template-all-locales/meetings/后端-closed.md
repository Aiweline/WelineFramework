# 后端 closed — mail-template-all-locales

date: 2026-09-23  
seat: Team:后端:  
plan_id: be-resolver-fallback + be-seed-all-locales  
result: delivered  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION

## 结论

- UC-1：`MailTemplateResolver` 非中文回落链改为 `locale → en_US → siteDefault → zh_Hans_CN`；`de_DE` 等不再直接落到 `zh_Hans_CN`。
- UC-2（管线）：`maintainedLocales` / `fileEntries` / `materializeFiles` 覆盖默认站 40 语；缺 JSON 时拷贝 `en_US` 基线（不覆盖已有文件，供翻译席真译替换）；已 `syncAll`。
- DB：`w_weline_smtp_mail_template` = **40 locale × 36 channel = 1440** 行。

## 改动文件

| 路径 | 说明 |
|------|------|
| `Service/MailTemplateResolver.php` | `localeFallbackChain` + 非中文插 `en_US` |
| `Service/MailTemplateSeedCopyCatalog.php` | `maintainedLocales` 并入默认站语种；`materializeTargets` 扩渠道；缺文件 `en_US` 拷贝 |
| `test/Unit/MailTemplateResolverFallbackContractTest.php` | UC-1 契约 UT（新建） |
| `test/Unit/MailChannelDefaultTemplatesContractTest.php` | 断言 maintained ⊇ forDefaultWebsite |
| `etc/module.php` | `1.4.58` → `1.4.59` |
| `doc/开发日志.md` | 短记 |
| 各模块 `view/email/**/{locale}.html|.subject.txt` | materialize 产出（JSON 8 语重生 + 其余 en_US 基线拷贝） |

## UT

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateResolverFallbackContractTest.php \
  app/code/Weline/Smtp/test/Unit/MailChannelDefaultTemplatesContractTest.php
```

结果：**16 tests, 1346 assertions, OK**（本席契约全绿）。

全模块 `php bin/w phpunit:run --module=Weline_Smtp` 另有 3 个既有/他席失败（BrandContext 域名空、Shell `ar_SA.csv` 缺文件），非本席 plan 范围；已在 channel 留言翻译席。

## syncAll

```text
maintained=40 wanted=40
materialize written/skipped（幂等二次）
syncAll={"inserted":1080,"updated":0,"skipped":360}
```

## related_web_urls

N/A（本席无店面/Browser 交付）

## 给翻译席

见 `channel/backend-i18n.md`：30 语种子现为 en_US 基线占位，需真译；Websites `notify_domain_*` en_US CJK 仍归翻译席 `i18n-websites-en-leak`。
