# REQ-MARKETING-0012 — 生命周期欢迎礼

## EARS

- When `Weline_Frontend_Account_Register::register_after` 且存在启用的 `LifecycleCampaign.type=welcome_customer`，the system shall 发欢迎邮件（渠道 `Weline_Marketing::welcome_customer`）。
- When 活动配置了 `incentive_rule_id`，the system shall 发券并注入邮件，归因 `welcome`。
- When 同 campaign+customer 已成功发送，the system shall 不再发送。

## 锁定

- 使用独立表 `LifecycleCampaign` / `LifecycleSendLog`，**不**扩展 `WinbackCampaign.type`。

## 非目标

- 沉睡/生日 Cron（枚举可留，不开任务）。
