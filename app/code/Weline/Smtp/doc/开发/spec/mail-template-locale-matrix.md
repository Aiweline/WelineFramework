# 邮件模板全语种矩阵验收

status: active  
module: Weline_Smtp  
plan_id: test-matrix  
updated: 2026-09-23  
UC: UC-1 / UC-2 / UC-3（`mail-template-all-locales.md`）

## 目的

对**默认站每个启用 locale** × **每个已注册 Smtp 渠道**做可重复渲染验收（**不真发 SMTP**）：

1. **UC-1**：非中文目标语解析到的 `template locale` ≠ `zh_*`（须等于目标 locale 或 `en_US`）。
2. **无 CJK**：非中文 locale 渲染后主题 + 壳/正文（去 HTML 注释）无汉字（白名单：`长安汉服`）。
3. **禁 en 占位（硬）**：非 `en_*` locale 不得仍整页残留该 channel 的 **en_US 种子特征串**（如 `Welcome gift`）。**en 拷贝占位 ≠ 目标语真译**，未真译前矩阵必须红，禁止判绿。
4. **禁壳层英标（硬 · `test-shell-en-gate`）**：非 `zh_*` / 非 `en_*` 的可见 subject+wrapped content 不得命中 `Phone:` / `Hours:` / `Address:` / `Need help?` / `Monday to Friday`（及 Mon-Fri 等变体）。正则要求冒号/问号形态，避免误杀 brand URL。`topics_label` 样例按 locale 本地化（ru ≠ `Offers / New arrivals`）。
5. **UC-3 预览**：`--persist-preview` 落库发件记录；要求 **≥5 locale × ≥3 channel**（禁止单条交差）。

## 如何跑

仓库根目录：

```bash
# 全矩阵（约 40×36=1440）
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-latest.json

# ≥5 locale × ≥3 channel 落库预览
php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php \
  --persist-preview \
  --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
  --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created \
  --json-out=app/code/Weline/Smtp/test/evidence/matrix-with-preview.json

# 契约 UT（抽样；全量加 MATRIX_FULL=1；真译后绿加 MATRIX_EXPECT_GREEN=1）
php vendor/phpunit/phpunit/phpunit --configuration tests/phpunit/config.xml --testdox \
  app/code/Weline/Smtp/test/Unit/MailTemplateLocaleMatrixContractTest.php
```

退出码：`0` 全绿 / `1` 有失败 / `2` 运行错误。

实现：`test/Support/MailTemplateLocaleMatrixRunner.php`。

## 翻译未完成时

写 `meetings/测试-progress.md`（等真译），**不要**写 closed 假绿。真译到位后再跑绿 → `meetings/测试-closed.md` + `notify_pm`。

## 预览 URL

`https://p05113ef3.test.weline.com:9555/{backend.prefix}/zh_Hans_CN/smtp/backend/log?log_id={id}&embed=1`
