# Weline_Websites

域名管理模块，负责：

- 域名商账号管理
- 域名可用性检查与购买
- 统一购买弹窗参数传递（DNS / CDN / 解析到本机 / 生命周期跟踪）
- 根域、`@`、`www` 的状态监控
- 与本模块内生命周期编排（`DomainLifecycleOrchestrationService`）、`Weline_Server` HTTPS 证书能力联动

## 默认站点约定

`Weline_Websites` 保留 `website_id = 0`、`code = default` 作为系统默认站点。这个零号站点是框架安装时自动创建的基础站点，属于系统内置默认站点，不是用户后续创建的普通业务站点。

对 AI 和开发者的硬约定：一切 `website_id = 0` 的站点都必须被解释为系统默认站点，绝不能解释为“没有站点”“未选择站点”“空值”“无效 ID”或“需要新建站点”。普通业务站点才使用正整数 ID。

安装和升级流程必须通过 `Weline\Websites\Service\DefaultWebsiteService::ensureDefaultWebsite()` 兜底保证零号默认站点存在；若历史数据里 `code = default` 使用了正整数 ID，升级会迁移回 `0`，并同步所有可扫描到的 `website_id` 引用表。

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

判断站点是否存在时不要用 `empty($websiteId)`、`if (!$websiteId)`、`$websiteId <= 0`、`$websiteId > 0`、`getId()` 真值判断等方式过滤默认站点；应显式区分“参数缺失”和“参数值为 0”，以 `code = default`、`array_key_exists('website_id', ...)` 或 `hasData(Website::schema_fields_ID)` 为准。

## Store 商品复制向导

后台 `websites/admin/store-copy/wizard` 通过 `Weline.Api.resource('product_copy')`
执行 `createDraft → preview → commit(request_hash)`，不得改用原生
Ajax/XHR/fetch/axios。取消只允许作用于 `state=draft` 的未提交草稿；
草稿提交成功或取消后，提交与取消入口都必须禁用，已提交目标独立保留。

## 网站写入与 ResourceChange

`add`、`edit`、`quickSave` 和 `deleteDelete` 都在同一主库事务内完成 Website、域名、货币、语言、两个 start-page SystemConfig、revision 与唯一 `ResourceChange v1`。任一子步失败必须整单回滚；parser/process/namespace IPC 只能在物理提交后执行。

- 默认 `website_id=0` 是 ORM 持久身份：编辑必须更新原行，保存后模型 ID 仍为严格整数 `0`。
- start-page 审计 operation 受 `SystemConfigVersion.operation` 32 字符上限约束；前台继承使用稳定码 `website_front_start_page_inherit`，不得在落库时截断。
- 删除时 `after=null`，完整 before/previous namespace/URL 保留；`0/default` 在 Controller 与 Model 双层禁删。
- Store / SalesChannel、完整快照、缓存失效与并发补种约束见 `doc/README.md`、`doc/default-website-and-request-detection.md` 和 `doc/store-saleschannel-scope.md`。

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
