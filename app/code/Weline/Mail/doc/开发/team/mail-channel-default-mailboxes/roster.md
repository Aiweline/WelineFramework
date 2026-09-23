# roster（草案 · 立项/澄清波）

slug: `mail-channel-default-mailboxes`  
mode: team  
updated: 2026-09-22  
维护：项目经理定稿；**需求分析仅建议席位，不代 PM 关项 / 不改 SESSION**

## 本波上场

| 席位 | 状态 | 子智能体 | 备注 |
|------|------|----------|------|
| 项目经理 | active | 父会话 | SESSION 记账；收 notify |
| 需求分析 | delivered | （本回合子智能体） | 规格已落盘；`notify_pm: true` |
| 领域探查 | assigned | （并行） | 只读：生产域名/Stalwart/缺口 |
| 主题开发工程师 | delivered | （本回合子智能体） | `work_mode=implement`；`channel/theme-mailbox-review.md` **pass**；`notify_pm: true` |
| UI | delivered | （本回合子智能体） | 邮箱页 E/F 自检 pass；`channel/acceptance-ui.md`；`notify_pm: true` |

## 澄清后建议上场（待 PM 排期）

| 席位 | 触发 | 备注 |
|------|------|------|
| 架构师 | OQ 收口 + 对齐冻结前 | 自动/一键/Setup；邮箱粒度；Mail↔Smtp 归属 |
| 扩展点 | complex team 必到 | Extends/Event/Setup 边界；禁循环依赖 |
| Setup | 建站任务面 | 扩展 `SmtpSetupTaskProvider` 或新增 Mail Provider |
| 后端 | 施工 | Mail 开账号 + Smtp 传输/绑定批处理 |
| 测试 | 冻结 UC 后 | fake +（授权后）生产抽测发信证据 |
| 电商顾问 | OQ-1 命名/共享策略 | 禁写码；触发电商通知渠道时 |
| 翻译工程师 | 新用户可见文案 | 默认站全语种 |
| 性能检查工程师 | 批量开通热路径 | 设计检查 |

## skip（建议 · 本波）

- 原型：本波邮箱页构图由 UI 席按线稿落盘；其余原型变体待排期
- 前端：结构/交互细节可复核 `enterprise.phtml`；旧 `index.phtml` inbox 待排期
- 支付开发工程师：不触及支付壳
- ACL：仅当新增独立后台入口时再上

## 通道

- `channel/req-clarify-20260922.md`（需求分析交付）
- `channel/theme-mailbox-review.md`（主题席 enterprise Token/`w-*` 合规 pass）
- `channel/ui-shentu-mailbox-20260922.md`（审图线稿）
- `channel/acceptance-ui.md`（UI 席邮箱页 E/F 自检 + notify_pm）
- 规格：`../../spec/mail-channel-default-mailboxes.md`
- SESSION：`../../session/mail-channel-default-mailboxes.md`
