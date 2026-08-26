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

后台入口默认展示 Stalwart 真实收件箱，`?view=config` 展示域名、DNS 与账号设置。前台账号中心仅对 `customer_id` 明确绑定了 active 邮箱账号的登录用户展示“我的邮箱”；JMAP 读取固定走本机回环地址，并使用服务器上的密封读取凭据。\n\n`mail:env:install` 默认只展示安装计划；真实依赖安装优先走框架入口。Stalwart 在框架环境检测中属于推荐依赖，不阻断 Weline_Mail 模块安装：

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

邮箱地址中的域和邮件服务主机名是两个概念：例如 `user@example.com` 的邮箱域是 `example.com`，服务主机名可以是 `mail.example.com`。

### 必需 DNS

| 记录 | 主机/名称 | 建议值 | 要求 |
| --- | --- | --- | --- |
| A | `mail.example.com` | 服务器公网 IPv4 | 必需。 |
| AAAA | `mail.example.com` | 服务器公网 IPv6 | 仅当邮件服务、防火墙和 PTR 都完整支持 IPv6 时配置。 |
| MX | `example.com` | `10 mail.example.com` | 必需；MX 目标必须直接解析 A/AAAA，不使用 CNAME。 |
| SPF | `example.com` | `v=spf1 mx -all` | 必需，作为 TXT 发布。 |
| DKIM | `selector._domainkey.example.com` | 邮件引擎生成的公钥 | 必需；选择器和值以邮件引擎实际输出为准。 |
| DMARC | `_dmarc.example.com` | 先 `p=none`，再升级 `quarantine` / `reject` | 先观察报告，确认 SPF/DKIM 对齐后再收紧策略；模块检测器会给出强化建议。 |
| PTR/rDNS | 服务器公网 IP | `mail.example.com` | 在 VPS/云厂商控制台设置，并确保主机名正向解析回同一公网 IP。 |

### smtp 别名、TLS 与端口

`smtp.example.com` 不是必需项，客户端可以直接连接 `mail.example.com`。如需友好别名，可用 CNAME 指向 `mail.example.com`，也可使用指向同一公网 IPv4 的 A 记录；TLS 证书必须覆盖客户端实际连接的主机名。

- `25`：服务器接收入站邮件（MTA），不作为普通客户端提交端口。
- `587`：客户端提交发信，推荐 STARTTLS。
- `465`：可选的隐式 TLS 提交端口。
- `993`：IMAPS 客户端收信。

### 后台每域名配置面板

后台“企业邮箱管理”分为“邮箱 / 用户与账号 / 域名与 DNS”三页。域名页会为每个已开通域名展示可直接照做的 DNS 清单，并提供 Cloudflare 连接、预览和一键应用入口。

固定流程：

1. 连接或重新授权 Cloudflare。
2. 填写源站公网 IP，以及 Stalwart 实际生成的 DKIM 选择器和公钥。
3. 先预览变更，再确认 mail 主机将以 DNS-only 暴露源站公网 IP。
4. 应用当前域名的 mail A/AAAA、根 MX、根 SPF、当前 DKIM 和 DMARC；写后重新读取校验，失败则回滚并列出残留变更。

安全边界：

- mail A/AAAA 永远强制 proxied=false；SMTP、IMAP、POP 不能走 Cloudflare 橙云代理。
- smtp 子域名是可选项；配置时同样必须 DNS-only。
- Cloudflare Email Routing 锁定的 MX/SPF 会阻止写入，需先解除托管。
- DKIM 私钥、示例值或缺失公钥会阻止操作；后台只保存公开 DNS 值。
- PTR/rDNS 仍需 VPS/云服务器厂商配置，Cloudflare 无法代配。
- TLS 必须使用有效证书，但无需购买商业证书；可通过 Stalwart/ACME 自动申请 Let’s Encrypt，或配置自有证书。
- Fake 测试域名只用于本地业务冒烟，不执行公网 DNS 写入。

账号与邮件：

- 真实账号由后台通过只读文件权限保护的密封 Stalwart 管理凭据开通或重置密码；密码不写入业务数据库和日志。
- 后台可按账号查看真实 Inbox / Sent，并在 ACL 授权后使用活动账号代发。
- Customer 侧菜单仅在当前系统用户拥有 active MailAccount 时展示；发信前再次校验账号所有权。
- 通用 Mail QueryProvider 仍只允许 fake 发信，不能绕过前台所有权或后台 ACL 获得真实账号代发能力。

### 开通后的收发验收

1. 创建两个测试邮箱 A、B，验证 A→B 与 B→A。
2. 向 QQ、Gmail 或 Outlook 等外部邮箱发送，并从外部邮箱回复。
3. 检查外部收件邮件头，确认 SPF、DKIM、DMARC 均为 `pass`。
4. 未认证 SMTP 向外域中继必须被拒绝，确认服务器不是开放中继。
5. 验证中文主题、正文、附件，以及 587 STARTTLS、可选 465、993 的证书链和主机名。
6. 全部通过后再逐步提高发信额度；DNS 或 DKIM 未通过时不要开放大批量发信。
## 安全边界

- 默认不开放中继。
- 新域名和新账号应限制发信额度。
- 未完成 DNS 和 DKIM 检测前，不建议放开发信量。
- 系统服务安装需要管理员/root 权限。
