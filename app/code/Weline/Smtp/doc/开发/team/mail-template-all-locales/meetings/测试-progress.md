# 测试 progress — mail-template-all-locales / test-matrix

date: 2026-09-23  
seat: Team:测试:  
plan_id: test-matrix  
result: **progress（矩阵红 · 等真译）**  
notify_pm: true  
禁止假绿: 是（en_US 占位 ≠ 目标语真译）

@项目经理：本席已交付/上报脚手架与红灯证据，请检查并更新 SESSION。真译到位后本席再跑绿写 `测试-closed.md`。

## 结论（本回合）

| 项 | 状态 |
|----|------|
| 穷尽矩阵脚手架 | **done** |
| 契约 UT | **done**（抽样绿；全量 MATRIX_FULL=1 可加） |
| 全矩阵 40×36=1440 | **跑完 · verdict=fail**（真实红，非假绿） |
| en 占位门禁 | **生效**：`en_placeholder_fails=480`；`cjk=0`；`uc1=0` |
| ≥5 locale × ≥3 channel 预览落库 | **done（15 条）** |
| Browser 抽检 | **blocked**：ide-browser 本席无法稳定开 tab；curl 字面 URL 见下；DB 已核内容含 EN 特征 |

**阻塞**：`i18n-translate-seeds` / `i18n-websites-en-leak` 未完成。30 语种子仍为 en 拷贝；矩阵在真译完成前**不得判绿**。

## 矩阵计数（本机真实跑）

```text
locales=40 channels=36 cells=1440
verdict=fail
pass=960 fail=480
en_placeholder_fails=480  cjk_fails=0  uc1_fails=0
```

证据 JSON：`app/code/Weline/Smtp/test/evidence/matrix-with-preview.json`

命令：

```bash
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-with-preview.json
```

说明：`pass=960` 主要为 `zh_*` / `en_*`（跳过 en 占位门）以及部分渠道未命中抽取到的 EN 特征；**非**「已真译」。失败格全部为 `en_placeholder`（例：`Welcome gift` / `Thanks for joining` / `Your order is confirmed`）。

## 交付物

| 路径 | 作用 |
|------|------|
| `test/Support/MailTemplateLocaleMatrixRunner.php` | 穷尽断言引擎（CJK + en 特征串） |
| `scripts/mail-template-locale-matrix.php` | CLI 入口 |
| `test/Unit/MailTemplateLocaleMatrixContractTest.php` | 契约 UT（默认抽样；`MATRIX_EXPECT_GREEN=1` 真译后改绿门） |
| `doc/开发/spec/mail-template-locale-matrix.md` | 如何跑 |

UT：

```bash
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateLocaleMatrixContractTest.php
```

结果：3 pass + 1 skip（`MATRIX_FULL`）；`de_DE×subscribe_gift` 正确检出 `en_placeholder`。

## 样本发件记录预览（≥5×≥3，禁止单条交差）

Host：`https://p05113ef3.test.weline.com:9555`  
prefix：`jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH`

locales：`de_DE, it_IT, ru_RU, pl_PL, nl_NL`  
channels：`subscribe_gift` / `subscribe_welcome` / `order_created`

| log_id | locale | channel | preview |
|--------|--------|---------|---------|
| 27 | de_DE | Weline_Newsletter::subscribe_gift | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=27&embed=1) |
| 28 | it_IT | Weline_Newsletter::subscribe_gift | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=28&embed=1) |
| 29 | ru_RU | Weline_Newsletter::subscribe_gift | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=29&embed=1) |
| 30 | pl_PL | Weline_Newsletter::subscribe_gift | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=30&embed=1) |
| 31 | nl_NL | Weline_Newsletter::subscribe_gift | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=31&embed=1) |
| 32 | de_DE | Weline_Newsletter::subscribe_welcome | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=32&embed=1) |
| 33 | it_IT | Weline_Newsletter::subscribe_welcome | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=33&embed=1) |
| 34 | ru_RU | Weline_Newsletter::subscribe_welcome | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=34&embed=1) |
| 35 | pl_PL | Weline_Newsletter::subscribe_welcome | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=35&embed=1) |
| 36 | nl_NL | Weline_Newsletter::subscribe_welcome | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=36&embed=1) |
| 37 | de_DE | Weline_Order::order_created | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=37&embed=1) |
| 38 | it_IT | Weline_Order::order_created | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=38&embed=1) |
| 39 | ru_RU | Weline_Order::order_created | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=39&embed=1) |
| 40 | pl_PL | Weline_Order::order_created | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=40&embed=1) |
| 41 | nl_NL | Weline_Order::order_created | [embed](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log?log_id=41&embed=1) |

列表入口：[发件记录 listing](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log/listing)

DB 抽检：`log_id=27/32/37` 正文均命中 EN 特征（`Welcome gift` / `Thanks for joining` / `Your order is confirmed`）——与矩阵 `en_placeholder` 一致。

curl 字面 URL（未登录态）：`log_id=27`→502；`32/37`→302（后台登录跳转）。需登录后 Browser 再验；不挡「脚手架 + 红灯证据」上报。

## related_web_urls

- 上表 15 条 embed
- [发件记录列表](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/smtp/backend/log/listing)

## escalate / 下一步

1. **等翻译席**完成 30 语真译（替换 en 占位）+ Websites en 去中文。
2. 真译后本席重跑全矩阵；期望 `en_placeholder_fails=0` 且 `verdict=pass`。
3. 再写 `meetings/测试-closed.md` + `notify_pm: true`；UT 加 `MATRIX_EXPECT_GREEN=1`。

## 未写 closed 的原因

按 PM 口径：**真译完成前矩阵不得判绿**。本文件为 progress，非 closed。
