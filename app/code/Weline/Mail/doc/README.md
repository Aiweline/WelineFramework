# Weline Mail 企业邮箱模块

## 模块目标

Mail 是独立企业邮箱管理模块，不直接依赖 Websites 内部实现。模块不从零实现 SMTP、IMAP、POP3 协议，而是管理原生安装的邮件服务引擎，并提供域名、账号、DNS、服务状态和环境依赖入口。

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

## 后台域名与账号管理

Mail 后台允许开通多个邮箱域名。域名候选通过 `w_query('websites', 'getDomainPoolList', ...)` 和 `w_query('websites', 'getLocalDomains', ...)` 从 Websites 获取，Mail 模块不直接依赖 Websites 内部模型。

- 真实 Stalwart 邮箱域名必须从 Websites 候选中选择后创建，避免把未纳管域名混入邮箱服务。
- Fake 测试引擎继续只允许 `.test` / `.invalid` 域名，用于本地前后台收发信冒烟。
- 已创建的邮箱域名记录在 `weline_mail_domain`，后台候选列表会标记已开通状态，并在域名表中展示账号数量。
- 邮箱账号创建和列表管理按域名聚合；Fake 域名账号默认建议 `active`，真实域名账号默认建议 `pending`，等待原生邮件服务同步后再启用。

## 前台个人中心

个人中心的邮箱账号申请、暂停、恢复、测试收信和 fake 发信都必须绑定当前前台登录用户。控制器和模板读取客户 ID 时以登录用户模型 ID 为优先值；如果模型 ID 为空或为 0，则回退到前台 Session 的用户 ID，避免账号列表为空或发信时误判为未拥有邮箱账号。

fake 引擎仅用于 `.test` / `.invalid` 域名的本地业务冒烟。fake 账号发信会写入本地发件箱；如果收件人也是可用 fake 账号，会同步投递到本地收件箱。

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
