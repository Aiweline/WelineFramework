# Weline_Websites

域名管理模块，负责：

- 域名商账号管理
- 域名可用性检查与购买
- 统一购买弹窗参数传递（DNS / CDN / 解析到本机 / 生命周期跟踪）
- 根域、`@`、`www` 的状态监控
- 与本模块内生命周期编排（`DomainLifecycleOrchestrationService`）、`Weline_Server` HTTPS 证书能力联动

## 默认站点约定

`Weline_Websites` 保留 `website_id = 0`、`code = default` 作为系统默认站点。安装和升级流程必须通过 `Weline\Websites\Service\DefaultWebsiteService::ensureDefaultWebsite()` 兜底保证该站点存在；若历史数据里 `code = default` 使用了正整数 ID，升级会迁移回 `0`，并同步本模块内的网站域名、货币、语言关联。

默认站点基础数据：

```text
website_id       = 0
code             = default
name             = 默认网站
url              = http://localhost
default_currency = CNY
default_language = zh_Hans_CN
default_timezone = Asia/Shanghai
```

普通业务站点仍使用正整数 ID。判断站点是否存在时不要用 `empty($websiteId)`、`getId()` 或 `> 0` 过滤默认站点；应以 `code = default` 或显式字段 `website_id` 是否存在为准。

## GName 购买结果兼容

`Weline\Websites\Adapter\GnameRegistrar` 已对 `code = -1` 且提示“已被注册”的歧义结果做二次确认：

1. 先调用购买接口
2. 若返回 `-1`，再调用 `getDomainList()`
3. 域名已在当前账号下则按成功处理
4. 不在当前账号下则按真实失败返回

## 生命周期编排

购买成功后可自动启动 `Weline\Websites\Service\DomainLifecycleOrchestrationService`，持续推进：

1. 购买确认
2. DNS / 解析处理
3. 根域、`@`、`www` 解析校验
4. 访问验证
5. HTTPS 证书申请

轮询任务：`Weline\Websites\Cron\DomainLifecycleOrchestration`（每分钟执行，推进未完成的生命周期订单）
