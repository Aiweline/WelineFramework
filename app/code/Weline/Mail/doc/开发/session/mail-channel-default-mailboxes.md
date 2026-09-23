# 需求会话总控（SESSION）

---
slug: mail-channel-default-mailboxes
module: Weline_Mail
mode: team
wave: clarifying
status: open
updated: 2026-09-22
last_checked_by: 项目经理
spec_path: ../spec/mail-channel-default-mailboxes.md
team_path: ../team/mail-channel-default-mailboxes/
---

## 当前阶段

- 交付流程阶段：`1b 澄清` → 等用户拍板 OQ 后进 `align_freeze`
- team 波次：`clarifying`（立项双席已交；`align_freeze_eligible=false`）
- 一句话进度：企业邮箱「邮箱」页 UI 已 Browser 复测 **pass**；主需求仍卡 OQ-1/2/4/5。
- team 波次：`mail-admin-ui-inbox` closed；主链仍 `clarifying`

## 计划项表

| plan_id | 来源 | 负责人席 | 状态 | 验收指针 | 关闭条件 |
|---------|------|----------|------|----------|----------|
| main | 用户需求 | 项目经理汇总 | open | 待 UC 冻结 | 子项全 closed + 测试 + PM 复检 + 汇审 |
| clarify-spec | 立项波 | 需求分析 | closed | [spec](../spec/mail-channel-default-mailboxes.md) | 规格落盘 + OQ 列出 ✓ |
| explore-surface | 立项波 | 领域探查 | closed | [explore](../team/mail-channel-default-mailboxes/channel/explore-mail-smtp-auto-bind.md) | 能力/缺口/冲突报告落盘 ✓ |
| align-freeze | 对齐冻结 | 项目经理+架构/扩展点/测试等 | open | contracts+UC | OQ 拍板后开会冻结 |
| mail-admin-ui-inbox | 用户附图吐槽乱 | 项目经理汇总 | closed | acceptance-test-mailbox-browser.md 复测 pass | Browser 1–6 全过 ✓ |

## 未完成清单

- **阻塞**：用户拍板 OQ-1/2/4/5（OQ-3 已部分拍）
- align-freeze 未开
- 生产 Stalwart/域名未证实（domains=0）
- deploy.env-map QQ 退役策略待冻
- 下一波：清理 `index.phtml` 旧 inbox `#hex` 平行皮肤
- （mail-admin-ui-inbox 已 closed）

### 待拍板 OQ（来自规格）

1. **OQ-1** 每渠道独立邮箱 vs 共享角色邮箱（noreply/orders/support）vs 混合 — **未拍**
2. **OQ-2** 全自动 / 一键 / 仅 Setup / 组合 — **未拍**
3. **OQ-3** QQ 迁移 — **用户已表态（2026-09-22）**：本机继续 QQ；线上靠 `deploy.env-map` **按档位替换**（不是清空删库）；旧 QQ 配置在 prod 被新值盖掉。细节「新值=外发域名 SMTP 还是 mail_account」仍待对齐冻结。
4. **OQ-4** 生产邮箱域名 + Stalwart/DNS — **未拍**
5. **OQ-5** Global vs Website — **未拍**

### 用户澄清摘录（配置面）

- 本机 = QQ；线上 = 映射文件换成新的 → **替换写入**，不是先删再空着。
- 问「这些都是配置吗？」→ Smtp 传输/绑定是 SystemConfig；邮局域名/用户是 Mail 实体；env-map 不能单靠开箱。
- 2026-09-22 附图吐槽「邮箱」页乱 → `ui_shot`；线稿见 `channel/ui-shentu-mailbox-20260922.md`。

## 审查索引

| 席位 | verdict | 纪要路径 | 一句话结论 |
|------|---------|----------|------------|
| 需求分析 | pass | ../spec/mail-channel-default-mailboxes.md | 目标/非目标/EARS/OQ 齐；未越权实现 |
| 领域探查 | pass | ../team/mail-channel-default-mailboxes/channel/explore-mail-smtp-auto-bind.md | 无端到端编排；底层积木可复用 |
| 原型 | pass | ../team/mail-channel-default-mailboxes/channel/prototype-mailbox-adjust.md | Variant A 冻结；throwaway 对照已交 |
| 主题开发工程师 | pass | ../team/mail-channel-default-mailboxes/channel/theme-mailbox-review.md | Token/`w-*` 合规；enterprise 已改 |
| UI | pass（自检） | ../team/mail-channel-default-mailboxes/channel/acceptance-ui.md | E/F/G 自检 pass；正式签收待测试 |
| 前端 | pass | ../team/mail-channel-default-mailboxes/channel/frontend-mailbox-notes.md | folder/compose/空态交互已合入 |
| 测试 | pass | ../team/mail-channel-default-mailboxes/channel/acceptance-test-mailbox-browser.md | 复测 1–6 全过 |

## 相关入口

- 生产站：`https://www.aiweline.com/`（长安汉服）
- 企业邮箱·邮箱页：[打开验收](https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox)  
  `https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/weline_mail/backend?view=mailbox`

## 停工 / escalate

- 无硬停工；**软阻塞**于用户 OQ

## 交付通知日志

| 时间 | 来自席位 | result | PM DoD 检查 |
|------|----------|--------|-------------|
| 2026-09-22T17:21+08:00 | 项目经理 | team 立项 | 已建 SESSION；已派需求分析+领域探查 |
| 2026-09-22T17:25+08:00 | 领域探查 | closed | pass：报告齐；explore-surface → closed |
| 2026-09-22T17:27+08:00 | 需求分析 | closed | pass：spec 路径存在；complex/backend/OQ≤5；clarify-spec → closed |
| 2026-09-22T17:43+08:00 | 项目经理 | ui_shot 立项 | 已派 原型/UI/前端/主题；mail-admin-ui-inbox=assigned |
| 2026-09-22T17:47+08:00 | 原型 | closed | pass：方案+PROTOTYPE HTML；**冻结 Variant A**；广播 `pm-freeze-variant-a.md` |
| 2026-09-22T17:49+08:00 | 主题开发工程师 | closed | pass：enterprise Token/`w-*`；残留 index.phtml 旧 inbox 记下一波 |
| 2026-09-22T17:49+08:00 | UI | closed（自检） | pass：acceptance-ui.md；待测试席 Browser；前端 notes 仍缺 |
| 2026-09-22T17:51+08:00 | UI | closed | 正式回报：施工+CSV+collect；请前端复核后测 Browser |
| 2026-09-22T17:51+08:00 | 前端 | closed | pass：frontend-mailbox-notes.md；交互已合入；唤醒测试 |
| 2026-09-22T17:53+08:00 | 前端 | closed | 正式回报：folder/compose/空态+CSV+契约测；测试席进行中 |
| 2026-09-22T17:55+08:00 | 测试 | fail | Browser：#1/#4 fail；mail-admin-ui-inbox→rework |
| 2026-09-22 | UI+前端 | closed | #1/#4 返工交卷 |
| 2026-09-22 | 测试 | pass | 复测 1–6 全过；mail-admin-ui-inbox→closed |
| 2026-09-22 | UI | closed | 去重页内「企业邮箱管理」h1（壳标题保留） |

## 返工循环日志

| 时间 | 起因 | 拉起席位 | 再验结果 |
|------|------|----------|----------|
| 2026-09-22T17:55+08:00 | 测试 Browser #1/#4 fail | UI + 前端 | 返工后复测 **pass** |

## PM DoD 检查清单（每次收交付勾选）

- [x] 契约交付物路径存在（spec + explore）
- [ ] 该有时 `related_web_urls` / 证据指针非空（施工后）
- [x] 未偷改已冻 UC 意图（尚未冻结）
- [ ] 计划项负责人与 `contracts.md` 一致（未写）
- [x] 测试未到 → 不得将该 plan_id 标 `closed`（main 仍 open）
- [x] 本检查 ≠ 替代架构/专席合规/代码级复审
