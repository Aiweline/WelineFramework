# Weline Mail 企业邮箱模块

## 模块目标

Mail 是独立企业邮箱管理模块，不依赖 Websites。模块不从零实现 SMTP、IMAP、POP3 协议，而是管理原生安装的邮件服务引擎，并提供域名、账号、DNS、服务状态和环境依赖入口。

v1 默认底层引擎为 Stalwart Mail Server。Linux 使用原生二进制和 systemd，Windows 使用原生二进制和 NSSM Windows 服务，不走 Docker/WSL2。

## 命令

```bash
php bin/w mail:env:check
php bin/w mail:env:install
php bin/w mail:env:install -y
php bin/w mail:service:status
php bin/w mail:dns:check example.com mail.example.com
```

`mail:env:install` 默认只展示安装计划；真实依赖安装优先走框架入口。Stalwart 在框架环境检测中属于推荐依赖，不阻断 Weline_Mail 模块安装：

```bash
php bin/w env:install stalwart-mail-server -y
```

## 数据表

- `weline_mail_domain`：邮箱域名、主机名、DNS 状态和配额策略。
- `weline_mail_account`：邮箱账号、显示名称、容量和状态。
- `weline_mail_service_log`：安装、诊断、健康检查和安全事件日志。

## DNS 要求

每个邮箱域需要配置：

- MX：指向邮件服务主机名。
- SPF：默认建议 `v=spf1 mx -all`。
- DKIM：由邮件引擎生成公钥后填写。
- DMARC：默认建议 quarantine 策略。
- PTR：公网 IP 反向解析应指向邮件服务主机名。

## 安全边界

- 默认不开放中继。
- 新域名和新账号应限制发信额度。
- 未完成 DNS 和 DKIM 检测前，不建议放开发信量。
- 系统服务安装需要管理员/root 权限。
