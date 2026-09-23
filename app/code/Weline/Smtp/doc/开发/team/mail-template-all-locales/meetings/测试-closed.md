# 测试 closed — mail-template-all-locales / test-matrix

date: 2026-09-23  
seat: Team:测试:  
plan_id: test-matrix  
result: **closed / pass**  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION。

## 结论

| 项 | 状态 |
|----|------|
| 独立全矩阵复跑（非采信翻译自跑） | **pass** |
| `verdict` | **pass** |
| 格子 | **1440 / 1440** |
| `en_placeholder_fails` | **0** |
| `cjk_fails` | **0** |
| `uc1_fails` | **0** |
| ≥5 locale × ≥3 channel 预览落库 | **15 条**（log_id=45～59） |
| 假红修复仍在 | **确认**：Runner resolve 后立即快照 subject/body；`Resolver::loadRow` `clone` |

## 本席命令与证据

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-test-rerun.json
```

```text
matrix verdict=pass total=1440 pass=1440 fail=0
en_placeholder=0 cjk=0 uc1=0 locales=40 channels=36
```

证据：`app/code/Weline/Smtp/test/evidence/matrix-test-rerun.json`（本席独立产出，非 `matrix-after-i18n.json`）

## 预览 URL（5×3，禁止单条交差）

Host：`https://p05113ef3.test.weline.com:9555`  
prefix：`jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH`

| log_id | locale | channel | embed |
|--------|--------|---------|-------|
| 45 | de_DE | Weline_Newsletter::subscribe_gift | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=45&embed=1) |
| 46 | it_IT | Weline_Newsletter::subscribe_gift | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=46&embed=1) |
| 47 | ru_RU | Weline_Newsletter::subscribe_gift | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=47&embed=1) |
| 48 | pl_PL | Weline_Newsletter::subscribe_gift | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=48&embed=1) |
| 49 | nl_NL | Weline_Newsletter::subscribe_gift | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=49&embed=1) |
| 50 | de_DE | Weline_Newsletter::subscribe_welcome | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=50&embed=1) |
| 51 | it_IT | Weline_Newsletter::subscribe_welcome | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=51&embed=1) |
| 52 | ru_RU | Weline_Newsletter::subscribe_welcome | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=52&embed=1) |
| 53 | pl_PL | Weline_Newsletter::subscribe_welcome | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=53&embed=1) |
| 54 | nl_NL | Weline_Newsletter::subscribe_welcome | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=54&embed=1) |
| 55 | de_DE | Weline_Order::order_created | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=55&embed=1) |
| 56 | it_IT | Weline_Order::order_created | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=56&embed=1) |
| 57 | ru_RU | Weline_Order::order_created | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=57&embed=1) |
| 58 | pl_PL | Weline_Order::order_created | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=58&embed=1) |
| 59 | nl_NL | Weline_Order::order_created | [打开](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=59&embed=1) |

DB 抽检：`log_id=45/46/47/50/55/59` subject **无** `Welcome gift` / `Thanks for joining` / `Your order is confirmed`（例：de gift 为德语 Abo/Willkommen 向；order_created de 含 `Best…`）。

列表：[发件记录 listing](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log/listing)

## 假红修复核对

| 修复 | 位置 | 本席核对 |
|------|------|----------|
| resolve 后立即快照 subject/body | `test/Support/MailTemplateLocaleMatrixRunner.php`（`Snapshot BEFORE buildEnMarkers`） | 仍在 |
| `loadRow` 返回 `clone` | `Service/MailTemplateResolver.php` | 仍在 |

## UT

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateLocaleMatrixContractTest.php
# MATRIX_EXPECT_GREEN=1 同上
```

结果：文件存在 + EN marker 门禁 **pass**；抽样格在 PHPUnit SQLite（无 `weline_websites_website` / 仅基线语）**skip**——全量绿以本席 CLI `matrix-test-rerun.json` 为准。Runner 已对 Website::load 做 try/catch，避免 SQLite 硬炸。

## related_web_urls

- 上表 15 条 embed（log_id=45～59）
- [发件记录列表](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log/listing)

## 对照 progress

此前 `meetings/测试-progress.md` 为等真译红灯；本文件为独立复跑后 closed。
