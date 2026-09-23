# channel — kickoff reopen（用户驳回漏译）

@后端 @翻译工程师 @测试

用户截图/log52：`ru_RU` subscribe_welcome **渠道正文已俄文**，但整信仍英漏：

1. 壳 UI：`Phone:` / `Hours:` / `Address:`（region 中文源经 `translateShellWord` 回落成英文，未用 seed `shellCopy` 的 `Телефон:` 等）
2. 品牌字段：`service_hours` = `Monday to Friday 9:00 AM - 6:00 PM...`
3. 样例变量：`topics_label` = `Offers / New arrivals`
4. 矩阵假绿：未把门禁扩到壳层英标

## 分工

- **后端** `be-shell-prefer-seed`：`MailTemplateShellComposer::translateShellWord`（及 localize）对壳短语优先 `MailTemplateSeedCopyCatalog::shellCopy($locale)`；无 pack 再 I18n；**禁止**目标非 en 时用英文 Phone/Hours/Address。
- **翻译** `i18n-shell-brand-hours`：补全/校对 40 语 shell pack；`website-brand-local-copy` / LocalDescription 的 `service_hours`（及必要时 address）按 locale 真译；确认 Smtp CSV/词典「客服电话」等非 en 回落。
- **测试** `test-shell-en-gate`：矩阵对非 en/非 zh 增加壳层英标门禁；topics 样例按 locale 或排除变量注入误杀；独立复跑 + ≥5×3 预览证明无 Phone:/Hours:。

交付：`meetings/{席}-closed.md` + notify_pm。
