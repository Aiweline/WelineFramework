# Weline_Websites

域名管理模块，负责：

- 域名商账号管理
- 域名可用性检查与购买
- 统一购买弹窗参数传递（DNS / CDN / 解析到本机 / 生命周期跟踪）
- 根域、`@`、`www` 的状态监控
- 与 `Weline_Saas` 生命周期编排、`Weline_Server` HTTPS 证书能力联动

## GName 购买结果兼容

`Weline\Websites\Adapter\GnameRegistrar` 已对 `code = -1` 且提示“已被注册”的歧义结果做二次确认：

1. 先调用购买接口
2. 若返回 `-1`，再调用 `getDomainList()`
3. 域名已在当前账号下则按成功处理
4. 不在当前账号下则按真实失败返回

## 生命周期编排

购买成功后可自动启动 `Weline\Saas\Service\DomainLifecycleOrchestrationService`，持续推进：

1. 购买确认
2. DNS / 解析处理
3. 根域、`@`、`www` 解析校验
4. 访问验证
5. HTTPS 证书申请

轮询任务：`Weline\Saas\Cron\DomainLifecycleOrchestration`
