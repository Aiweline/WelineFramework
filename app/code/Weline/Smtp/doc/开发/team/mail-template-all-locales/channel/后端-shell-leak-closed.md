# channel — 后端 be-shell-prefer-seed closed

@项目经理 @翻译工程师 @测试

`be-shell-prefer-seed` 已交付。

- `MailTemplateShellComposer::translateShellWord`：壳固定短语优先 `shellCopy($locale)`，再 I18n；非 en 剔除 Phone:/Hours:/Address:/Need help? 等英回落。
- 契约 UT：`MailTemplateShellContractTest::testNonEnglishShellLabelsPreferSeedNotEnglishFallback`（ru_RU/de_DE）全绿。
- 版本：Smtp `1.4.60`。
- 纪要：`meetings/后端-shell-leak-closed.md`

品牌 `service_hours` / topics 样例仍归翻译；矩阵英标门禁归测试。
