# Weline_Server 证书管理 Hook 集成文档

## 概述

Server 模块通过 Hook 机制将 SSL 证书管理 UI 注入到 Websites 模块的域名管理页面，实现证书管理与域名管理的统一入口。

## Hook 实现

### Tab 按钮

**文件**: `view/hooks/Weline_Websites/backend/partials/domain/tabs.phtml`

注入一个"证书管理"Tab 按钮到域名管理页面的导航栏。

### Tab 内容

**文件**: `view/hooks/Weline_Websites/backend/partials/domain/tabs-content.phtml`

通过 AJAX 懒加载现有的 SSL 证书管理界面：
- 首次点击 Tab 时通过 AJAX 加载 `*/backend/server/ssl-certificate` 页面
- 复用已有的证书列表、统计卡片、申请/续签/删除等全部功能
- 加载失败时提供直接链接供用户在新窗口打开

### 懒加载策略

```
用户点击"证书管理" Tab
    ↓
监听 shown.bs.tab 事件
    ↓
fetch(sslIndexUrl) → AJAX 请求
    ↓
返回 HTML → 插入容器 → 执行嵌入脚本
    ↓
证书管理 UI 完整呈现
```

## 已有能力（由 Server 模块提供）

| 功能 | 说明 |
|------|------|
| Let's Encrypt 证书 | 自动申请和续签正式 SSL 证书 |
| 自签证书 | 开发环境自动生成自签名证书 |
| 自动续签 Cron | 每天凌晨 3 点检查并续签即将到期的证书 |
| HTTPS 开关 | 按域名切换 HTTPS 启用/禁用 |
| 域名同步 | 通过事件从 Websites 模块获取域名列表 |
| SNI 支持 | 多域名证书匹配 |

## 事件集成

Server 模块与 Websites 模块通过以下事件解耦集成：

| 事件 | 触发方 | 监听方 | 说明 |
|------|--------|--------|------|
| `Weline_Server::domain::certificate_issued` | Server | Websites | 证书签发完成 |
| `Weline_Server::domain::certificate_disabled` | Server | Websites | 证书禁用 |
| `Weline_Server::domain::certificate_renewed` | Server | Websites | 证书续签完成 |
| `Weline_Server::integration::domain_list_requested` | Server | Websites | 请求域名列表 |

## 证书临期通知

`CertificateAutoRenew` 通过 Framework 公开的 `w_msg()` 入口发布
`Weline_Framework_Message::system_notification` 中性事件，不直接依赖 Backend 私有事件名。

- 已启用 `Weline_Backend` 时，Backend 的 `SystemNotificationObserver` 监听该事件，通知仍持久化并按原路径分发。
- 未安装 Backend 时，Server 的证书检查与续签能力仍可独立运行。
- `Weline_Backend` 在 Server 模块清单中是 `optional`，只用于后台 ACL/菜单、主题配置集成和后台通知消费，不是 WLS 运行的必需依赖。
- Server 后台控制器中的 `Weline_Backend::system_service_group` 只是 ACL 父资源标识，必须保持层级语义，不应改写为 Server 自有根节点。

## 文件清单

```
Weline/Server/
├── view/hooks/Weline_Websites/backend/partials/domain/
│   ├── tabs.phtml                     # Tab 按钮 Hook 实现
│   └── tabs-content.phtml             # Tab 内容 Hook 实现（AJAX 加载）
├── Controller/Backend/SslCertificate.php  # SSL 证书控制器（已有）
├── Service/SslCertificateService.php      # SSL 证书服务（已有）
├── Cron/CertificateAutoRenew.php          # 自动续签 Cron（已有）
└── register.php                            # 版本升级到 1.5.0
```
