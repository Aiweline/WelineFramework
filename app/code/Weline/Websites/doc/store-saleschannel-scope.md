# Store / SalesChannel 与请求 Scope 三段解析（P1a）

> 万能商城内核 P1a 交付。Scope 三段 = `website.store.channel`，`store_mode ∈ normal|dev|test`。

## 模型

| 模型 | 表 | 关键约束 |
|---|---|---|
| `Weline\Websites\Model\Store` | `weline_websites_store` | `UNIQUE(website_id, code)`；父 Website 必须在同连接存在；`store_mode` 创建后不可变；default 店铺禁删、代码/默认位不可改 |
| `Weline\Websites\Model\SalesChannel` | `weline_websites_sales_channel` | `UNIQUE(store_id, code)`；父 Store 必须存在且 Website 一致；default 渠道禁删 |

- 表中展示的是逻辑名；实际 SQL 必须通过 Model/Connector 解析配置前缀与 PostgreSQL runtime schema（例如 `prefix=w_` 时为 `w_weline_websites_store`），不能把 `getOriginTableName()` 直接拼入 prepared SQL。
- `website_id=0`（code=default）是合法系统默认站，不是空值。
- 每个 Website 恒有一个 default/normal Store；每个 active Store 恒有一个 default Channel。tombstone Store 只保留历史身份与既有 Channel，迁移和补种不得向其新增 Channel，迁移完整性统计必须排除墓碑。

## 补种（幂等）

`Weline\Websites\Service\StoreChannelSeedService`：

- `ensureDefaults()`：`setup:upgrade` 时全站补种（`Websites/Setup/Upgrade.php`）。
- `ensureDefaultsForWebsite()`：`Website::save_after` 新站点即时补种。
- `DefaultWebsiteService::ensureDefaultWebsite()` 只在 Install/Upgrade 控制面同一受管事务内确保 `website_id=0`，随后补齐它的默认 Store/Channel；直接 prepared SQL 使用 Model 解析后的物理表名。运行时 Catalog、QueryProvider 与证书域名读取不得调用该修复入口，缺失不变量必须回到升级流程处理，不能让 read 操作夹带写入。
- 补种根边界使用 `WriteIntentTransactionCoordinatorInterface::runWrite()`；SQLite 在首次读取前取得 `BEGIN IMMEDIATE`，已有受管 owner 时直接复用且拒绝从普通 SQLite 事务中途升级。缺失项只在 savepoint 内执行普通 INSERT，Store/Channel Model 在已有 owner/savepoint 内不再重复开启事务。
- 已有 owner 内的 Website/Store/Channel/Seeder/默认站/墓碑失败会先把 owner 标记为 rollback-only 再上抛；即使外层 callback 错误捕获异常，最终也只能物理回滚。显式 savepoint 的预期竞争仍只回滚该保存点并恢复 owner 快照。
- 正式 Model Hook 与缓存 namespace authority 只支持同一进程默认主连接；`NamespaceGenerationRepository` 会在 generation 写入前校验逻辑连接亲和性，不同数据库连接直接 fail-closed 并令 owner transaction 回滚，禁止业务行与 generation 分库提交。隔离三库 runner 覆盖的是关闭缓存 Hook 后的 Seeder/模型约束核心；完整 Hook 由默认主连接集成测试和专用 WLS 验证，不把测试连接能力宣传为多数据库运行时契约。
- 并发失败只接受完整 `(website_id, code)` 或 `(store_id, code)` 的目标唯一约束。聚合锁序固定为 `Website → Store → Channel`：Store 更新/墓碑先以普通读定位父级，再锁 Website 与 Store current row；Channel 更新/删除先定位并锁父 Store，再锁 Channel current row，最终校验不得复用锁前快照。MySQL/PostgreSQL 冲突后同样用 `FOR UPDATE` current read 回读获胜行，避免 repeatable-read 旧快照。其他唯一约束与普通 SQL 异常一律回滚上抛；二次执行新增 0 行，不以异常风暴实现幂等。
- Store/Channel 的规范 code 最长 64 字符、trim 后 name 最长 128 字符，模型层在三库统一 fail-closed。Seeder 从 Website 名派生默认 Store 名时会为本地化后缀预留空间并按 UTF-8 字符确定性截断，不能依赖 SQLite 忽略 `varchar(n)` 长度。
- PostgreSQL 显式约束名同时接受 `PgsqlIndexName` 的 raw、当前 54+完整 hash 和历史 55+截断 hash 候选；完全没有约束名时才按完整 `Key (website_id, code)` / `Key (store_id, code)` 列表回退。不得因物理名被哈希而漏掉目标幂等命中，也不得把另一约束、部分列或普通 SQL 错误吞成成功。

## 请求解析与冻结

`Weline\Websites\Service\ScopeResolver`（由 `DetectWebsite::processSite` 在 Website 命中后调用，一次解析并冻结）：

1. Website 阶段先按“精确 Host > 单层 `www.` 别名”和最长完整路径段边界选站；`Website.url` 与 `WebsiteDomain` 同 rank 命中不同站点时直接 409。
2. 以 Website 入口为基准，先由 Framework 统一解析可选 area 与货币/语言前缀，Store 选择不依赖本地化段的顺序。
3. 使用服务端构造的可信请求 URL 做规范 Origin 匹配；该 Origin 要求 scheme、端口一致，Host 只允许单层 `www.` 等价。
4. 在同 Origin 候选中按完整路径段边界选择最长 Store URL；同等优先级多条命中直接 409。
5. 命中 Store URL 后会消费它的入口路径前缀，把剩余的规范路径作为真正控制器路由；例如 Store 入口 `/outlet` 命中 `/outlet/catalog/list` 后，Router 只接收 `/catalog/list`。
6. 可信 URL 无 Store 命中时，才选择站点的 `default/normal` Store，此时保留已移除本地化前缀的站点相对路由；
7. 只选择该 Store 的 `default` Channel，并复核 Store/Channel 的 enabled、归属和有效生命周期；
8. `__store` / `__channel` 仅作一致性断言，不能改变可信解析结果。

未知、跨站、停用、墓碑、无效配置、路径歧义或显式断言冲突全部 fail-closed；只有“可信 URL 没有匹配 Store”这一种情况才允许使用 default，不会回落到其他站点的店铺。普通页面 Scope 冻结后不可改写；`rest_frontend` QueryBin 因 API 路径不携带 storefront Store 段，允许在 Host、Token、Catalog、rollout 复核完成且 execution binding digest 完全一致时，把 Host 默认 Store 细化为同 Website 的受信 Store/Channel。该受控例外只替换 Scope 投影并清空 storefront route remainder，不改变 authority、method、URI、locale、currency 或 timezone；跨 Website、非 frontend、非权威或 binding 不一致均在改写前返回 409。冻结位置：

- `RequestContext`：通过 `getWelineStoreId()`、`getWelineStoreCode()`、`getWelineStoreMode()`、`getWelineChannelId()`、`getWelineChannelCode()` 和 `getStorefrontRoutePath()` 读取；冻结身份、数值 ID、路由余量与时区保存在 `runtime.request_context.*`，兼容 `route.*` 只作当前 Context 镜像；
- `ScopeContext`：三段字符串（`getScope()` = `website.store.channel`）。

`RequestContext::resetWelineVars()` 会清理全部三段与 ScopeContext key（WLS request/fiber 隔离）。

### 本地化路径约定

- 可选 area 段之后，货币和语言都可单独出现，也可以 `currency/locale` 或 `locale/currency` 任意顺序组合。`/USD/zh_Hans_CN/products` 与 `/zh_Hans_CN/USD/products` 解析为同一货币/语言上下文。
- 规范生成顺序固定为 `currency -> locale`；因此上例的 canonical 段是 `/USD/zh_Hans_CN/products`。解析阶段只按段形状识别，不依赖当前 Website 的 allowed currency/language 缓存，合法性由后续配置/业务层校验。
- 后台 area key 必须是 URL 第一段，并通过 `Env::getAreaRoutePrefix('backend')` 读取；本地化段只能位于该 key 之后。不得硬编码 `/backend` 或 `/admin`，也不得接受把后台 key 放在货币/语言之后的地址。
- 本地化段在 Store 匹配前从可信匹配路径中去除，Store URL 命中后再消费 Store 路径前缀。例如 Website 入口 `/mall`、Store 入口 `/mall/outlet`，请求 `/mall/zh_Hans_CN/USD/outlet/catalog` 最终冻结为该 Store，而 Router 接收 `/catalog`；对外规范前缀为 `/mall/USD/zh_Hans_CN/outlet/catalog`。

### 请求隔离、时区与安全元数据

- `RequestContext` 按当前请求/Fiber 保存不可变 `ScopeIdentity`、数值 ID、本地化和路由余量。`WebsiteData` 把克隆的 Website 快照及其派生缓存也写入该上下文；重置后不得沿用上一请求的站点、Store、Channel、locale、currency 或时区。
- Website 时区只写入 `RequestContext::setWelineTimezone()`，并与路由余量一样在当前请求冻结后只允许同值幂等写入；不得修改 PHP 进程全局 timezone。
- `RequestContext::scopeMetadata()` 是稳定的响应安全投影，字段严格为：`scope_kind`、`website_id`、`website_code`、`store_id`、`store_code`、`store_mode`、`channel_id`、`channel_code`、`locale`、`currency`、`timezone`、`context_version`。`QueryBin` 成功响应以 `scope_meta` 返回该投影，Website 解析事件也发布同名字段。
- `scope_meta` 故意不含 Scope Token、签名、密钥、opaque bootstrap ID、完整指纹、Worker HMAC secret、显式断言和路由余量。这些敏感或内部字段不得为了调试追加到响应。

## ScopeIdentity 与 Token

- `Weline\Framework\Runtime\ScopeIdentity`：不可变值对象，`kind = global|website|store|channel` 判别联合，`canonicalKey()` 用于锁/幂等/缓存键，`toLegacyScopeString()` 兼容三段字符串。
- `Weline\Websites\Service\ScopeTokenService`：HMAC-SHA256 token 格式为 `v1.<kid>.<payload>.<sig>`，固定 audience `weline.storefront.v1`，精确绑定 Host、完整 Scope 与 `context_version`。payload 必须按服务端唯一字段顺序和 JSON 编码生成；即使签名正确，字段重排、重复键或其它等价但非规范 JSON 也按 `non_canonical_payload` 拒绝。
- Token TTL 1800 秒，续签窗口 300 秒。60 秒时钟偏差只容忍签发节点的 `iat/nbf` 最多领先验证节点 60 秒；`exp <= now` 立即视为过期，不额外延长授权。验证返回结构化 `valid|expired|invalid|context_conflict|service_unavailable` 状态；调用方对无效、过期、冲突和服务不可用分别稳定 fail-closed。
- `renew()` 只接受仍然 `valid`、Host/Scope 相同且进入 300 秒窗口或正在轮换 kid 的 Token；当前一次性 bootstrap/Worker Session 不做原地续签。自然过期只能重新加载 storefront 页面，由可信 Host/URI 导航解析重新冻结 Scope 后签发新 Token；篡改、未知 kid 或上下文冲突绝不沿失败请求重签。
- 密钥必须预置为可轮换 keyring：优先读取 `WELINE_SCOPE_TOKEN_KEYRING_B64`，否则读取 `security.scope_token.keyring_file` 或模块 `scope_token.keyring_file` 指定的绝对 owner-only 普通文件。请求路径绝不生成密钥，也不再使用 `var/scope/.token_key`。

## Store 生命周期

- Store 只有 `active|tombstone` 生命周期；墓碑记录保留 code/归属用于拒绝旧 Token 和阻止身份复用，不能被解析为可用 Store。
- 默认 Store 与默认 Channel 是系统不变量，不能删除或改写默认身份；Store 的 `store_mode` 创建后不可变。
- Website 物理删除会先锁定并复核目标行；只要仍有 Store 或 SalesChannel 引用就 fail-closed。现阶段必须先走后续正式 purge/lifecycle 流程，不能直接删除父站留下孤儿。
- Channel 的 `effectiveEnabled` 同时受自身 enabled、父 Store active/enabled 和归属约束。每次 Token 恢复都重新读取 Catalog，停用、墓碑或 context version 变化立即使旧绑定失效。

## Scope Kernel rollout

配置根为 `commerce.rollout.scope_kernel`，含 `mode`、`allowlist` 与 `shadow_sample_bp`：

运行时 `ScopeKernelRolloutPolicy` 只从 `ConfigReader` 读取该配置；需要注入确定配置的控制面校验统一使用 `ScopeKernelRolloutPolicy::forConfiguration()`。不得重新引入可选 `?array $configuration = null` 构造参数，旧 DI 会把缺省数组归一化为 `[]` 并静默关闭已配置 rollout。`allowlist/on` 下配置或 keyring 不可用必须 fail closed，不能退回 `off`。

- `off`：零 keyring、零 Token、零 bootstrap、零 Cookie、零缓存头副作用。
- `shadow`：只按采样比例在服务端观察/比较，不向页面发权威 Token，不改变请求事实。
- `allowlist`：HTTPS 页面全部取得绑定，只有精确命中的 `website_id/store_id/channel_id` 且 Store 为 `dev|test` 的三元组具有权威；非 allowlist 绑定仅用于验证迁移链，不安装为请求事实。撤销已签发的权威三元组返回 409 `scope_authority_revoked` 并要求刷新，绝不降级为未绑定 Session。
- `on`：HTTPS 全量权威；任何缺失、过期或不一致的 binding 均 fail-closed。

`allowlist/on` 的 QueryBin 握手在创建 Session 前就要求 opaque bootstrap；缺失时返回 401 `scope_binding_required` 且不写入 Session 容量。已声明但无法构造的 Scope provider、keyring 或 Worker 状态后端返回 503，不能伪装成普通 401、不能回退未绑定 Session。

页面只能看到唯一的 43 字符 opaque meta ID。完整 Scope Token 放在 `__Host-` 前缀、`Secure + HttpOnly + SameSite=Lax + Path=/` 且无 `Domain` 的 Cookie 中，一次性交换成功后用相同属性清除；Token、签名密钥、完整指纹和 Worker HMAC secret 不进入页面 JavaScript。QueryBin 的二进制输出保护日志也只能记录异常输出的字节数和 SHA-256，不得记录原文片段。

Token 的 Host claim 必须使用 Framework 的 `RequestAuthority`：标准 `HTTP_HOST` 是主事实，冻结的 `WELINE_FULL_REQUEST_URI` 只做一致性校验或缺失 fallback；两者非空但不一致、任一非法或最终缺失均 fail closed。不能读取 parser-derived `input.host`、`SERVER_NAME`、监听器 `SERVER_PORT` 或 `X-Forwarded-Host`。签发页与 `query-bin` 握手必须对同一规范 authority 做精确校验，专用端口不能被忽略或静默归一为默认端口。

最终响应装饰覆盖三个面：WLS 正常响应使用 `App::run_after`，FPM 正常响应使用 `Http::response_ready`，FPC 早期命中使用 `Fpc::cache_hit_response`。FPC 只保存共享 HTML，bootstrap/Cookie 必须命中后生成；allowlist/on 权威路径若无法安全装饰，返回 503 而不是发送可降级页面。

WLS 的匿名首页 FPC prime 是共享缓存构建请求，必须通过框架统一的 `InternalHomepagePrime` 判定跳过 Scope bootstrap；不得给 prime 响应写入一次性 Cookie 或 `private/no-store`。正常公网请求与共享 FPC 命中仍在最终响应边缘逐请求生成 bootstrap，READY 门禁继续严格拒绝任何带 Cookie 的共享预热响应。

## 缓存维度

`Weline\Framework\Cache\KeyBuilder`：

- `storefrontDimensions()` 只读取请求内同一份 frozen `ScopeIdentity`，输出 `website/store/channel/store_mode/context_version`；
- `environmentContext()` 与 router key 在 website 维度启用时复用同一 Scope 和 storefront generation fingerprint；
- `applyDimensionFlags(website: true)` 不允许从缺失身份缩短为 default 键，解析/版本失败时使用 request fence；FPC 直接暂停；
- Store 或 SalesChannel 的真实保存/删除会推进所属 `website/{code}/catalog` generation；若外层业务事务回滚，目录写入与 generation 一起回滚。SalesChannel 删除必须在拥有者事务中、准备 DELETE 之前冻结目标记录和父 Store 校验事实；`delete_before()` 不得再发起数据库查询覆盖事务连接上的待执行 DELETE。未来商品 Catalog 写入口必须复用同一 namespace，不能另造模块私有版本。

## 跨模块只读契约

跨模块只允许依赖 `Websites/Api/Catalog/StoreCatalogInterface`、`SalesChannelCatalogInterface`（v1），不得直接引用 Store/SalesChannel Model。

- `StoreSummary` v1 精确字段为 `store_id, website_id, code, name, store_mode, is_default, enabled, lifecycle_status, tombstoned_at, url`；`SalesChannelSummary` v1 精确字段为 `channel_id, website_id, store_id, code, name, is_default, enabled, parent_store_lifecycle_status, effective_enabled`。DTO 为 final readonly，增加、删除或改名字段必须发布新契约版本。
- QueryProvider 只发布 `getStoreCatalogV1(website_id)` 与 `getSalesChannelCatalogV1(store_id)` 两个 Store/Channel 操作，固定 `v1/read/frontend=false/external=false/graph=false`。参数必须是唯一允许的规范整数键，范围分别为 `0..2147483647` 与 `1..2147483647`；额外参数和越界值 fail-closed。
- Catalog 返回任何 Store 前必须只读确认父 Website 实际存在；返回任何 SalesChannel 前必须确认父 Store 存在且 Website 归属一致。`website_id=0` 与普通站点使用相同存在性校验，不得把 0 当成空值，也不得在缺失时补种。
- Catalog 包含 disabled 与 tombstone 记录用于诊断和身份拒绝；`effective_enabled` 只有在 Channel enabled、父 Store enabled 且 lifecycle=`active` 时为 true。

## Website 后台只读嵌套目录

Website 后台列表与表单通过 `WebsiteStoreChannelDirectory` 组装
`Website -> Store -> SalesChannel` 展示投影：服务只调用两个 v1 Catalog，禁止直接查询
Store/SalesChannel Model，也不会在读取路径补种默认目录。

- 列表路由：`*/admin/website`，沿用 `Weline_Websites::website_list` 路由 ACL。
- 分页 ORM 返回的 Website 模型必须先归一化为数组，再做 `website_id` 存在性与规范非负整数校验；不得把缺失 ID 回退成系统默认站点 0。
- 编辑表单展示同一只读投影；新增表单只说明保存后由升级/站点保存流程建立默认目录。
- Store mode、启停、生命周期及 Channel effective-enabled 都以文本和 badge 同时展示，不能只靠颜色表达。
- 搜索是普通 GET 页面请求；模板不得使用原生 `fetch`、XHR、axios、`$.ajax` 或手写 query-bin URL。
- 搜索框必须有显式可访问名称；目录折叠按钮必须保留键盘 `focus-visible` 指示。
- 该页面没有 Store/Channel 创建、编辑、删除控件。对象 Scope ACL（`ObjectAuthorizationService`）已落地：Catalog 读在后台会话下按 LIST 过滤且无权限返回空列表；写 CRUD 入口仍关闭，须显式对象写授权后才能激活。
- 目录为空时只展示修复提示；读取动作不得调用 `StoreChannelSeedService`。系统不变量缺失应运行 `setup:upgrade` 修复。
