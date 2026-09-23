# mail-template-all-locales — 规格

status: ready-for-plan  
module: Weline_Smtp（联动各渠道归属模块 view/email）  
updated: 2026-09-23

## 背景

用户要求：**所有邮件模板** × **默认站全部已启用语种** 正确发信与可预览验收；禁止只验一条 subscribe_gift/en_US 发件记录。

审计：默认站 40 语；种子/DB 仅 10 语；缺行时 `MailTemplateResolver` 回落站默认语 `zh_Hans_CN`，导致 de_DE/it_IT/ru_RU/en_GB 等解析到中文模板。

## 用户故事

As a 订阅者/客户, I want 按我的语言收到完整译文邮件, so that 非中文环境下不会看到中文模板正文。

As a 运营, I want 发件记录可按语言预览全部渠道模板, so that 能验收全语种而非抽样一条。

## EARS

- WHEN 默认站某 locale 已启用且存在渠道模板种子 THEN 系统 SHALL 为该 locale 物化并 sync 独立模板行。
- WHEN 目标 locale 暂无行且非中文 THEN 系统 SHALL 优先回落 `en_US`，禁止直接回落 `zh_Hans_CN`。
- WHILE 渲染/发信/发件预览非中文 locale THEN 可见正文/主题/壳页尾 SHALL 无汉字叙述（品牌专有名词可保留拉丁/音译）。
- WHEN 测试席跑矩阵 THEN 对每一个启用 locale × 每一个已注册渠道 SHALL 断言解析 locale 与可见区无 CJK 漏译。

## UC

| id | 名称 | 验收 |
|----|------|------|
| UC-1 | 回落链 | de_DE 等缺行时解析到 en_US（或本语种行），不得为 zh_Hans_CN |
| UC-2 | 全语种种子 | 40 语均有各渠道模板；非中文无 CJK 正文 |
| UC-3 | 矩阵预览 | 脚本/发件记录覆盖全渠道×全语种抽样+穷尽渲染 |

## 非目标

- 不改 SMTP 传输账户
- 不要求对每个 locale 真发外网邮箱（可渲染落库预览）；至少 ≥3 语种真实发信或等价落库预览
