# channel · 需求分析交付 · 2026-09-22

from: Team:需求分析:  
to: @项目经理:  
result: clarify-spec delivered  

## 交付物

- 规格：`app/code/Weline/Mail/doc/开发/spec/mail-channel-default-mailboxes.md`
- roster 草案已更新（仅建议席位）

## 压缩摘要（供父会话）

- **slug**: `mail-channel-default-mailboxes`
- **已澄清**: 弃 QQ 外发长期依赖；目标 `mail_account`+域名邮箱；渠道要有传输名与绑定；期望自动/一键/Setup 等价开通；框架已有手动闭环、缺批量默认开通
- **OQ（≤5）**: 邮箱粒度；触发形态；生产 QQ 迁移策略；生产 Stalwart/域名是否就绪；Global vs Website scope
- **建议席位**: 架构师、扩展点、Setup、后端、测试；按需电商顾问/翻译/性能
- **框架能力**: `MailChannelProvider`+Collector；`smtp_senders`/`source_type=mail_account`；`smtp_channel_bindings`；`MailSmtpAccountService`/`MailAccountManagementService`；`SmtpSetupTaskProvider`（无邮箱开通项）

## notify

notify_pm: true  

@项目经理：本席已交付/上报，请检查并更新 SESSION
