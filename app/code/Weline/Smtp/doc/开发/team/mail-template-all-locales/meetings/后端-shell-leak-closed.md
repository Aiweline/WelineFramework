# 后端 closed — be-shell-prefer-seed（壳英漏）

date: 2026-09-23  
seat: Team:后端:  
plan_id: be-shell-prefer-seed  
result: delivered  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION

## 结论

- 根因确认：`MailShellRegionStore` / `shell.phtml` 壳 UI 为中文源；`translateShellWord` 仅走 I18n 时，ru_RU 等缺译回落 `en_US` → 可见 `Phone:`/`Hours:`/`Address:`，而 seed `shellCopy` 已有 `Телефон:` 等。
- 修复：壳固定短语（需要帮助？/访问/客服邮箱：/客服电话：/服务时间：/地址：/自动发送长句）**优先** `MailTemplateSeedCopyCatalog::shellCopy($locale)`；无映射再 `TranslationResolver`；目标 locale 非 `en_*` 时若候选仍是 `Phone:`/`Hours:`/`Address:`/`Need help?`/`Visit`/`Support:` 等英标则剔除，继续下一候选，最终回落中文源（禁止英标漏出）。
- 本席只管壳 UI 标签路径；品牌 `service_hours` 值归翻译席。

## 改动文件

| 路径 | 说明 |
|------|------|
| `Service/MailTemplateShellComposer.php` | `translateShellWord` seed 优先 + 非 en 英标拒绝 |
| `test/Unit/MailTemplateShellContractTest.php` | 新增 ru_RU/de_DE 契约；CSV 断言对齐 zh+en only + seed wrap |
| `etc/module.php` | `1.4.59` → `1.4.60` |
| `doc/开发日志.md` | 短记 |

## UT

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateShellContractTest.php
```

结果：**3 tests, 125 assertions, OK**

抽检：`ru_RU` wrap 含 `Телефон:`/`Часы работы:`/`Адрес:`/`Нужна помощь?`；`de_DE` 含 `Telefon:`/`Öffnungszeiten:`/`Adresse:`；二者 loadShell+wrap **不含** `Phone:`/`Hours:`/`Address:`/`Need help?`。

## related_web_urls

N/A（本席无店面/Browser 交付；壳译路径契约 UT）

## 并行提醒

- 翻译席：品牌 `service_hours` / topics 样例仍可能英漏（非本席标签路径）。
- 测试席：矩阵壳层英标门禁可依赖本修复后的 wrap 输出。
