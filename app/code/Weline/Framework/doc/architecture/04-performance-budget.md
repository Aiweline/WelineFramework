# 性能与稳定性预算

## 测量矩阵

| 维度 | 取值 |
| --- | --- |
| 平台 | Windows、macOS、Linux |
| Runtime | FPM、WLS |
| 状态 | 冷启动、预热后、长时间热运行 |
| 并发 | 1、32、128 |
| 指标 | p50、p95、p99、max、QPS、RSS、连接池、FPC/模板缓存命中率 |

性能结论必须同时提供平台、PHP 版本、运行模式、Worker 数、样本量、冷/热状态和原始数据路径。不使用单次请求作为验收依据。

当前状态：静态架构门禁已经全绿；macOS 与 Linux VM 已取得 WLS Direct、TLS 1.3、HTTP/1.1/2/3、冷启动和长稳证据。Windows 原生 runner、FPM 以及完整业务路由矩阵尚未全部完成；任何单个平台的数据都不得外推为其它平台已经通过。

## 发布门槛

- 16 Worker 批量启动：median `≤ 2s`，p95 `≤ 3s`。
- 预热后动态首页首渲染 `< 70ms`。
- DI、Event、Router、Template、BinQuery 热路径 p95 比 2.0 基线至少降低 30%。
- p99 不高于 p95 的 2 倍；任一阶段相比已批准基线不得回退超过 5%。
- 请求内部阶段没有无 deadline 的等待；不允许原生 `sleep/usleep`。
- 10 万请求后数据库连接数不超过池上限，事务不跨请求残留。
- Worker RSS 在预热后进入平台；不得出现请求态或用户态串数据。

### QueryProvider / BinQuery 热路径门禁

- `framework:compile` 输出 format v2，`deferred=0`，provider/descriptor/operation 数量必须与当前模块一致。
- PROD/WLS 执行 descriptor、help 或 BinQuery 准入查询时，不得读 Provider 源文件、反射 Attribute 或实例化全部 Provider。
- BinQuery `call/graph/exists` 只允许使用 `area -> provider -> operation` 哈希索引；不得调用 `getAllDescriptors()` 后线性扫描。
- 索引缺失、格式过期、provider/descriptor 名不一致、operation 重名或 descriptor 含对象时，编译/生产启动必须明确失败。

### 编译容器门禁

- `framework:compile` 必须生成 format v1 容器索引；相同代码连续编译两次，
  产物 SHA-256 必须一致。
- PROD/WLS 启动不得在产物缺失、格式错误或服务未收录时回退
  ObjectManager。
- 编译服务的 `get/create` 热路径不得执行 Reflection、目录扫描、源码解析或
  运行时工厂生成。
- `process` 只允许无用户态的对象；`request/fiber` 必须在 cleanup 后归零；
  `prototype_retained` 始终为 0。
- 容器迁移以单服务为单位记录前后 p95；未完成的 ObjectManager 调用点必须
  保留在替换清单，不得仅因索引已生成就宣布热路径已全量迁移。
- modules/query/runtime-policy/template/container 注册表不得向现有 target
  原地写入；发布失败后旧 target 必须仍可完整 `require`，临时文件必须清理。
- 并发 `framework:compile` 必须按输出目录串行，锁等待上限 `10s`；超时不得
  继续发布或静默降级。每次成功发布后必须 invalidate target 的 OPcache。
- `server:start` 必须先在私有 staging 目录完成 5 个注册表、container
  digest 和 runtime-policy 预检；预检失败时 final 5 文件的 SHA-256 必须
  全部不变。promotion 以 `container.php` 收尾，第 N 项失败后必须恢复
  全部原始字节、验证 hash 并释放编译锁。
- PROD FPM/WLS 的严格 container preflight 必须早于 `App::init()`。WLS READY
  协议版本为 v3，必须携带 `compiled_container_digest_v1` 能力和 64 位
  SHA-256；Master 期望值、Worker 上报值或能力任一缺失/不匹配都不得
  进入 READY。

## 连接和总预算

连接获取、SQL 执行、重试和响应传输共享一个请求总 deadline。不允许每层重置完整超时，也不允许在连接池饱和后创建未计数临时连接。结构化指标至少区分：

- pool wait
- connect/reconnect
- query execution
- transaction rollback/cleanup
- response encode/write

### ConnectionLease 所有权门禁

- Framework 内置 Connector 只能通过 `ConnectionPool::acquire()` 持有
  `ConnectionLease + PDO`；裸 `getConnection()/releaseConnection()` 仅作为旧集成兼容桥。
- Lease 不可 clone、不可序列化，`release/discard/destruct` 幂等。Lease token 是进程内
  逻辑 checkout 标识，不得再以 PDO object id 作为唯一 Lease 键。
- 同一 request/Fiber owner 在同一物理池内采用可重入、引用计数的逻辑 Lease：
  Connector clone 必须清空本地 Lease/PDO 后重新 acquire，但多个 clone 复用 owner 的
  同一 PDO；最后一个 token 释放或请求清理时才能物理归还。
- 不同 Fiber owner 禁止共享 PDO。非 Fiber 的 FPM/CLI/同步 Runtime 使用单请求进程
  owner；裸连接兼容桥也必须记录 owner，跨 request/Fiber 归还必须明确失败。
- `close()`、析构、断线重连和连接初始化异常必须先清空 Connector 本地引用，再由
  Lease 原子归还或丢弃；初始化完成前的版本校验、schema 绑定或包装器异常不得泄漏
  `in_use`。
- `requestEndCleanup()` 只回滚并归还当前 owner 的物理连接，不得遍历并清理
  进程内其他挂起 Fiber 的 `in_use`。复用的 Connector 下一次访问必须重新
  acquire；旧 Lease 随后 close 不得重复入队或改变池计数。
- 任一逻辑 Lease 发现 PDO 不健康时，必须使同 owner/同 PDO 的全部兄弟 token
  失效并且只丢弃一次物理连接，禁止其他 clone 继续使用已断开的 PDO。
- 池统计必须始终满足 `available + in_use = current_size <= max_size`。默认获取等待
  预算仍为 `150ms`；耗尽后抛出 `ConnectionPoolExhaustedException`，禁止池外临时连接。
- `ConnectionPoolExhaustedException::getContext()` 只暴露无凭证的结构化诊断：
  `reason/pool_id/timeout_ms/max_size/current_size/available/in_use/owner_count/lease_count/raw_owner_count/owners`。
  owner id 必须是不可逆指纹，不得包含 host、database、username、password 或请求私密数据。
- 饱和等待期间若归还的是断线/不可用连接，必须先扣减 `current_size`，再在同一个剩余
  deadline 内补建受池管理的连接；不得因为坏连接空出的槽位继续空等到 150ms。

### SQLite busy 重试预算

SQLite 的 `SQLITE_BUSY/SQLITE_LOCKED` 使用 `RetryBudget` 的单一、不可延长 deadline。PDO 建立后的连接引导、查询准备与执行都遵守该规则；每次退避只能取得剩余预算，禁止每次 attempt 重新获得完整超时。

- 动态请求默认预算为 `50ms`，配置 `db.retry.sqlite.request_budget_ms` 后仍强制限制在 `1–150ms`。
- CLI/setup 默认沿用动态预算；只有显式配置 `db.retry.sqlite.cli_budget_ms` 才可扩大，硬上限为 `30000ms`，仍然只有一个 deadline。
- deadline 是终止重试的唯一正常条件；32 次仅作为时钟/调度器异常的保险上限。退避基数为 `2ms`，按指数增长并加入不超过 10% 的抖动，实际等待永远不超过剩余预算。默认 `50ms` 下通常在第 6 次尝试前耗尽；显式 CLI 大预算可以实际获得更长但仍有界的等待。
- Connector 在 PDO 打开后、任何可访问数据库文件的引导 SQL 之前立即设置 `PRAGMA busy_timeout = 0`。`case_sensitive_like`、`foreign_keys`、`pre_sql` 中的 journal/schema 引导以及 `sqlite_version()` 校验共享一个连接 deadline；不允许每个 PRAGMA 重置预算。
- 原生 SQLite busy handler 会同步阻塞 PHP/WLS 线程，因此旧 `busy_timeout` 连接字段不再设置 native wait；`pre_sql` 中的 `PRAGMA busy_timeout=N` 保留原位置但强制收口为 `0`。需要等待时必须配置框架 retry budget，不能与 native timeout 叠加。
- WLS 只有在 Scheduler 已激活、当前存在 Fiber 且输出缓冲允许安全让出时才等待；否则立即抛出 `DatabaseRetryTimeoutException`，`reason=cooperative_wait_unavailable`，不得退化成原生 `sleep/usleep`。
- 只有 SQLite busy/locked 可重试。其他 PDO 异常保持原类型、错误码和调用栈；busy 表示当前 statement 尚未提交，因此不会重放已完成的写操作，也不改变现有事务边界。
- 结构化异常提供 `driver/reason/sql_state/driver_code/attempts/budget_ms/elapsed_ms/cooperative_wait_available`，同时保留原 PDO `errorInfo`；日志和遥测不得依赖解析异常字符串。

示例配置：

```php
'db' => [
    'retry' => [
        'sqlite' => [
            'request_budget_ms' => 50,
            // 仅 CLI/setup 确有长锁等待需要时显式设置。
            'cli_budget_ms' => 2000,
        ],
    ],
],
```

## WLS 预热和 keep-hot

FPM/WLS 共用 `RequestPipeline` 应用阶段。WLS 复用进程级 Pipeline 对象；每个动态请求只创建一个不可变 Result 和固定大小 timing 数组，不允许按阶段创建 Closure。URL 和 Router 阶段结束后通过进程级 listener 尝试执行有压才触发的内存收缩；只有 `WlsConcurrency` 确认没有 peer 请求 Fiber 时才能清理 MemoryStore/模板/路由等进程缓存，否则当前阶段直接延后。最底层 `compactRuntimeCaches()` 同样必须复核该事实源，防止溢出等旁路清掉 peer 正在读取的对象。

`RequestLifecycleTrace` 的 enabled cache、span/紧凑字典、start/parent 栈与 request id 必须按当前 Fiber/Context/请求隔离；请求 cleanup 只删除当前 scope，不得重置挂起 peer Fiber 的 Trace。FPM 保持单请求 Context/main 语义。

FPC/early response 必须发生在普通 `run_before`、Session 和 Router 前；只有维护模式等强制规则可注册 `pre_route_gate` 并早于 URL/FPC。FPM/WLS 使用同一个 request-scoped early-response key，旧 `wls.fpc.cached_response` 仅保留一版兼容桥。

1. Master 发布 READY 前，至少 `min_ready` Worker 完成必要引导。
2. 首页共享 FPC 只有一个 owner 生成并原子发布。
3. 每个 Worker 至少执行一次进程内首页热路径，不能只依赖共享缓存。
4. 空闲 keep-hot 由单 owner 触发，有 deadline、有频率上限，不与前台请求抢占。
5. 预热失败不得隐藏；健康状态记录 owner、耗时、阶段、失败原因和下次 deadline。

首页 warmup 的 owner pool 不得包含每槽不同的 generation。owner 在 Process FPC 发布成功处捕获不可变 receipt；Follower、READY gate 与 keep-hot 必须使用同一 `full_uri + variant + unified cache key + identity digest` 证明，禁止发布后从可变 Context 重算。

### WLS 路由级翻译缓存预算

WLS 禁止在 Worker READY 或普通请求阶段加载某个 locale 的全模块词典。翻译热路径固定为：

1. 先查 Worker 进程内 `locale + 当前模块集合 + word` 的最终译文哈希，O(1) 返回；该表只随本 Worker 实际遇到的词增长，上限 32,768 项，达到上限时按插入顺序有界裁剪 4,096 项。
2. 路由涉及的模块词典按模块分别维护 Worker L1。L1 尚未建立时才读取 `phrase` Shared Memory 的模块 CSV 快照；Shared miss 才解析该模块自己的 `i18n/{locale}.csv` 并回填。文件 mtime/size 参与版本，其他 Worker 不重复解析同一 CSV。
3. 模块 CSV miss 后，旧词典表和无 `source_module` 归属的历史词条按“精确单词”回源：先查 Worker 单词 L1，再查 Shared Memory 的单词级记录，最后才通过 `GlobalDictionaryProviderInterface::word()` 按 `md5(word + locale)` 索引查询一行并回填。该路径不得构造 locale 级数组。
4. CLI/维护命令可以显式调用 `words(locale, modules)` 读取模块范围或全词典；该批量路径不得进入持久 Worker。

请求 cleanup 只清当前请求的翻译状态，不清 Worker 常驻词哈希。翻译发布或 cache epoch 才清 Worker L1；已知缺失可以缓存为 `null`，Shared/DB 短暂错误则不得写入进程级负缓存。任何共享记录都必须是模块 CSV 快照或单词级小记录，禁止重新发送曾达到约 1.67MB 的 21,055 词全量 locale 字典。

## 测试实例约束

自动验证必须使用独立 WLS 实例，端口从 9502 开始且实例名唯一；禁止触碰生产 9501。自动验证后停止实例；需要人工验收时，必须交付 URL、实例名、端口和精确停止命令。

## 2026-07-10 macOS P0 与稳定性实测

环境：macOS、PHP 8.4.22、16 HTTP Worker、Dispatcher + `stream_select`（未安装 event 扩展）、首页共享 FPC 热命中，专用端口 9512。

| 并发 / 样本 | HTTP 200 | p50 | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| 64 / 1,000 | 1,000 | 269ms | 603ms | 705ms | 802ms |
| 128 / 1,000 | 1,000 | 529ms | 859ms | 953ms | 988ms |
| 64 / 1,000（重复） | 1,000 | 193ms | 412ms | 489ms | 673ms |
| 128 / 10,000（作用域泄漏修复后） | 10,000 | 409ms | 537ms | 602ms | 728ms |
| 128 / 60,000（稳定性回归） | 60,000 | 430ms | 592ms | 776ms | 4,499ms* |
| 128 / 100,000（最终门槛） | 100,000 | 408ms | 548ms | 667ms | 2,652ms* |

累计 3,000 请求后 Dispatcher FD 为 62，只保留监听和控制连接；修复前同一测试每完成约 488 个请求后达到 1,018 FD，并持续返回 503。

长压测还暴露了第二个故障：`RequestContext` 过早清理，加上并发 omit 名单跳过 Template reset，使 `Template::$scopedInstances` 每请求增长一项。修复前 60,000 请求只成功 54,176 次，16 Worker 在约 1,300 请求/Worker 后同时达到 88% 内存排水阈值。修复后最终 100,000 次全部成功，16 个原 Worker PID 全程存活：

- 每 Worker 处理 6,160–6,354 个首页请求；
- PHP used memory 为 7–14MB，allocated 为 22MB，进程 RSS 约 77MB；
- `Template::$scopedInstances = 0`、`ThemeData::$scopedStates = 0`；
- MemoryService RSS 约 56MB，未 OOM；Master/Dispatcher 无新增错误；
- 服务端采样 p99 208ms，最慢 263ms，无秒级内部阶段。

`*` 压测客户端 max 包含单进程本机调度停顿；同时段服务端 Trace 最慢为 263ms，因此不属于 WLS 内部秒级处理。

该结果只完成当时的 macOS/WLS/热运行/128 并发门禁，不替代当前更严格的 Direct 对照门槛，也不替代 Windows、Linux、FPM 与冷启动矩阵。

## 2026-07-11 macOS Direct TLS 1.3 rolling reload

环境：macOS、PHP 8.4.22、Event 3.1.4、4 Direct Worker、Master-owned shared listener FD、stream SSL + ext-event、首页 Process FPC HIT，专用端口 9834。

| 模式 / 并发 / 样本 | 成功 | QPS | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| keep-alive + rolling reload / 32 / 100,000 | 100,000 | 9,562.78 | 6.230ms | 8.132ms | 28.194ms |
| fresh TLS 1.3 + rolling reload / 32 / 100,000 | 100,000 | 1,776.06 | 21.725ms | 25.199ms | 71.907ms |
| reload 后当前代 fresh TLS 1.3 / 32 / 20,000 | 20,000 | 1,657.62 | 24.479ms | 41.880ms | 98.584ms |
| 生命周期收口后 keep-alive / 128 / 100,000 | 100,000 | 10,313.37 | 18.669ms | 22.211ms | 87.271ms |
| 生命周期收口后 fresh TLS 1.3 / 32 / 20,000 | 20,000 | 1,869.76 | 21.201ms | 31.553ms | 56.240ms |
| 生命周期收口后 fresh TLS 1.3 + rolling reload / 32 / 20,000 | 20,000 | 1,549.87 | 28.330ms | 35.528ms | 105.782ms |

六组均为 0 错误。reload 压测真实命中旧代、surge、新代；最终结束状态为 1 Master + 4/4 canonical Worker，无 launcher/surge 残留。最终当前代 fresh connection 分布 `max/min=1.036`。空闲 TLS preconnect 下 maintenance enable/disable 也完成全量 ACK；完整 restart 为约 3.94–4.01s，所有 Worker 均完成首页 Process FPC READY 证明。该结果关闭本机 TLS 排水、代际索引与 new-first reload 回归，但不替代 Linux/Windows 原生平台门禁。

## 2026-07-11 macOS Direct 编译模板策略回归

环境：macOS、PHP 8.4.22、Event 3.1.4、4 Direct Worker、Master-owned shared listener FD、stream SSL + ext-event、TLS 1.3、首页 Process FPC HIT，专用端口 9842。运行时使用编译后的 Template Cache Policy Registry，Framework 对具体模块保持零反向引用。

| 模式 / 并发 / 样本 | 成功 | QPS | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| 首页 keep-alive / 32 / 10,000 | 10,000 | 8,943.63 | 6.027ms | 13.280ms | 37.839ms |
| 首页 keep-alive / 128 / 100,000 | 100,000 | 10,388.08 | 19.375ms | 23.383ms | 70.522ms |
| health fresh TLS 1.3 / 32 / 5,000 | 5,000 | 1,944.02 | 21.434ms | 37.131ms | 46.776ms |

三组均为 0 错误。100,000 请求后 4/4 Worker 保持同一 PID、无异常重启，fresh connection 的 Worker `max/min=1.256`。首页响应为 `FPC HIT + source=process`，总阶段约 0.13ms；动态首页首渲染 35.84ms、带 backend key 的后台动态首渲染 56.93ms，均低于 70ms。OpenSSL 实际协商 `TLSv1.3 + TLS_AES_256_GCM_SHA384 + X25519`。报告：`var/log/wls/benchmark_report_20260711_124513_501699_root_pid51300.json`。

内置 Browser 同时验证首页标题/H1、随机 backend key 入口和已登录 Dashboard 可见，首页与后台控制台均为 0 error/0 warning。裸 `/admin/login` 的应用层结果由真实 HTTP 证明为 404；Browser 客户端自身先返回 `ERR_BLOCKED_BY_CLIENT`，不将客户端拦截冒充服务端结论。自动验证后已停止实例，9842 无监听，Master/Worker 均为 stopped。

## 2026-07-12 macOS Direct 项目 Host 与业务预热解耦

环境：macOS、PHP 8.4.22、4 Direct Worker、`shared_fd + ext-event + stream TLS`，专用端口 9855，目标 Host `p05113ef3.weline.test`。默认 warmup 路径从硬编码的演示商品/分类列表收敛为仅 `/`；业务模块通过 `etc/module.php` 声明的 `ViewWarmupContributionProviderInterface` 编译 Provider 提交 `fpcPaths`，或使用显式 `wls.worker.fpc_warmup_paths` 配置。Worker 启动热路径不再通过运行期 warmup event 发现模块路由。

| 模式 / 并发 / 样本 | 成功 | QPS | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| 首页 keep-alive / 32 / 20,000（5 轮中位） | 20,000 | 10,289.99 | 5.539ms | 7.287ms | 26.524ms |
| 首页 keep-alive / 128 / 100,000 | 100,000 | 12,009.53 | 15.681ms | 19.051ms | 74.69ms |
| 首页 fresh TLS 1.3 / 32 / 400 | 400 | 1,781.41 | 26.181ms | 29.237ms | 32.108ms |

五轮表格中的 QPS/p95/p99/max 分别独立取中位数，五轮全部 0 错误。100,000 请求全部为 Process FPC HIT，Worker `max/min=1.028` 且 PID 不变。删除硬编码业务路径前，自动首渲染会访问并不存在的演示商品页，产生 1.88–2.38s 假长尾并与首页压测争用资源；删除后自动发现仅返回 `/`。

热态动态首页 bypass 为 23.83ms，带 backend key 后台真实首渲染为 53.9ms；reload 后某 Worker 的第一次动态 bypass 仍为 87.31ms，超过 70ms 冷首渲染门槛，因此本轮不能把动态首渲染标记为全部通过。

## 2026-07-12 macOS Direct / Dispatcher 当前阶段对照

环境：macOS、PHP 8.4.22、4 Worker、ext-event loop、stream TLS、同一项目 Host 与等价策略内容。Direct 使用 POSIX `shared_fd` 直连，Dispatcher 使用显式 TCP 透传。正式数据均先丢弃预热轮，再交替执行 5 轮并取各指标中位数。

| 路径 / 拓扑 / 并发 / 每轮样本 | 成功 | QPS | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| 静态 L1 HIT / Direct / 32 / 100,000 | 500,000 | 16,510.53 | 3.516ms | 5.596ms | 21.429ms |
| 静态 L1 HIT / Dispatcher / 32 / 100,000 | 500,000 | 11,391.96 | 5.145ms | 8.543ms | 140.189ms |
| 首页 Process FPC HIT / Direct / 32 / 20,000 | 100,000 | 6,456.36 | 10.112ms | 14.248ms | 31.759ms |
| 首页 Process FPC HIT / Dispatcher / 32 / 20,000 | 100,000 | 5,396.05 | 11.607ms | 18.806ms | 147.614ms |

四组均为 0 错误。静态 Direct 相比 Dispatcher 的 QPS 提升 44.9%，p95 降低 31.7%，p99 降低 34.5%，阶段性通过“QPS 至少高 20%、p95 至少低 20%”的拓扑对照门槛。首页 Direct 的 QPS 提升 19.65%、p95 降低 12.9%、p99 降低 24.2%；QPS 已高于当前参考机约 4,740 QPS 的绝对目标，但 10.112ms 的 p95 仍高于 5.5ms 目标，且相对 Dispatcher 的 QPS 和 p95 尚未同时达到 20%，因此首页拓扑性能门槛仍是待办，不能标记为通过。

原始报告位于 `var/log/wls/benchmark_report_20260712_*.json`。本组静态报告时间戳为 Direct `145125/145350/145415/145443/145519`、Dispatcher `145145/145359/145424/145453/145529`；首页报告时间戳为 Direct `145607/145616/145624/145632/145640`、Dispatcher `145612/145620/145629/145637/145645`。

## 2026-07-13 匿名首页 READY receipt 快路径

冷重载后发现，首页虽显示 Process FPC HIT，但匿名请求不携带默认语言/货币 Cookie，Worker 重构的身份与 READY 发布 receipt 不同，因此每次仍进入 Framework FPC 管线，QPS 只有 300–500。修复后仅同 scheme/host 的无 Cookie 根路径复用已验证 receipt；两拓扑的外部响应均显示 `UrlParser=0 / UrlParserApply=0`。

| 拓扑 / 并发 / 请求 | 成功 | QPS | p95 | p99 | max |
| --- | ---: | ---: | ---: | ---: | ---: |
| Direct / 32 / 10,000 | 10,000 | 4,726.77 | 16.775ms | 30.804ms | 66.262ms |
| Dispatcher / 32 / 10,000 | 10,000 | 3,930.59 | 16.449ms | 37.335ms | 148.435ms |

Direct QPS 相对提升 20.26%，两组 0 错误。默认策略恢复后，裸 `/admin/login` 两拓扑均为 404，正确 backend key 为 200，语言/货币两种顺序都进入规范化 302。当时主机 load average 超过 9，所以这组证据用于确认快路径和拓扑相对 QPS，不替代低噪声五轮中位数。

## 2026-07-13 h3/h2/h1 协议边缘与 READY 热链复验

环境：macOS、PHP 8.4.22、4 Worker、ext-event、TLS 1.3、WLS-owned Caddy 2.11.4 协议边缘；Direct 使用私有 Worker 连接池，Dispatcher 对照多一层内部字节派发。公开端口强制请求实际得到 HTTP/1.1、HTTP/2、HTTP/3；普通客户端首连经 ALPN 使用 HTTP/2，接收 Alt-Svc 后自动升级 HTTP/3。OpenSSL 同一 SNI 首连为 `New`、带 session 的二连为 `Reused`；HTTP/2 与 HTTP/3 各 16 路并行请求都只建立 1 条公开连接。

| 首页 Process FPC / 拓扑 / 并发 / 每轮样本 | 成功 | QPS 中位 | p95 中位 | 相对 Dispatcher |
| --- | ---: | ---: | ---: | ---: |
| Direct / 32 / 10,000（5 轮） | 50,000 | 7,853.61 | 6.082ms | QPS +35.98%，p95 -32.81% |
| Dispatcher / 32 / 10,000（5 轮） | 50,000 | 5,775.71 | 9.052ms | 基线 |

两组五轮均为 0 错误。性能轮只对专用实例的 `127.0.0.1` 临时启用白名单，避免把默认 `3000/60s/IP` 的正确 429 当成性能失败；完成后已恢复 `enabled=false` 并重新发布正式策略。

此前 READY 直接接受第一次 `ready:slow`，当前代 Direct 3 个冷 Worker 为 196.82–209.79ms，Dispatcher 3 个为 180.62–195.10ms。现在第一次动态 FPC-bypass 渲染只负责填充进程级 Router/Controller/Template 缓存；若超过目标，会在同一个最多 3 次的有界事务中立即复验热链。修复后的 Direct 完整停启约 2 秒，4 个 Worker 的最终 READY 回执均为 `attempts=2`、16.48–18.70ms；Dispatcher rolling reload 为 12.08–21.24ms。既不把冷填充耗时冒充可路由性能，也不把偶发主机抖动转化为无限重启。

当前代码代继续完成故障中压与长稳：c128×100,000 首页压测中终止 Worker #2，100,000 请求全部成功；替补 Worker 约 1.389 秒 READY，并在同轮接管 7,327 个请求。随后 c128×1,000,000 为 1,000,000/1,000,000、0 错误、6,066.32 QPS、p95 28.861ms、p99 51.702ms、max 518.477ms，四 Worker 分布 24.73%–25.45%，PID/READY 数保持稳定。长稳后追加 100,000 请求，四个 Worker RSS 与长稳结束采样逐字节相同，确认进入平台期而非持续线性增长。报告：`var/log/wls/benchmark_report_20260713_143152_810454_root_pid11391.json`、`var/log/wls/benchmark_report_20260713_143457_712351_root_pid23270.json`、`var/log/wls/benchmark_report_20260713_143544_134094_root_pid84869.json`。

2026-07-14 的 16 Worker 词级缓存回归在最终 rolling reload 后，16 个动态首页 READY 回执为 10.81–21.77ms；正式策略首页 c32×2,500 为 2,500/2,500、0 错误、7,090.55 QPS、p95 8.963ms、p99 13.747ms、max 14.088ms，health c128×100,000 为 100,000/100,000、0 错误、12,286.74 QPS、p95 18.541ms、p99 30.351ms、max 119.856ms，全部 16 Worker PID 保持运行。报告：`var/log/wls/benchmark_report_20260713_165208_619313_root_pid4416.json`、`var/log/wls/benchmark_report_20260713_165226_865387_wls-health_pid5879.json`。

仍需原生完成的发布矩阵：

- Windows：`auto -> dispatcher`、Direct/independent 启动前拒绝、event DLL ABI 匹配、批量启动与长稳性能。
- 跨 Runtime：FPM 对照、DI/Event/Router/Template/BinQuery 分项基线，以及完整业务路由矩阵。

## 2026-07-14 macOS / Linux 协议边缘最终复核

Linux 证据来自 Colima Ubuntu 24.04.4、Linux 6.8 arm64、PHP 8.4.23。启动前自动安装 ext-event 3.1.4，并写入排在 sockets 之后的 `30-event.ini`；安装后由新的同一 PHP 二进制重新验证。系统 Caddy 2.6.2 虽没有完整 build-info，真实有界 HTTP/3 listener probe 仍证明 QUIC 可用，避免把裁剪过的发行包误判为不支持。

16 Worker 共执行 10 次冷启动，CLI 耗时为 4.231–5.810s；`batchCreate` 为 120–383ms，内部全部 READY 为 2.827–3.752s。曾尝试把动态预热复验默认次数从 3 降为 1，五轮反而退化到约 11–16s；该实验已回退，不计入通过数据。回退后的三轮为 4.511/6.233/4.403s，`batchCreate` 为 123/297/136ms。

Linux 最终实例实际返回 HTTP/1.1、HTTP/2、HTTP/3，TLS 1.3 首连为 `New`、二连为 `Reused`；HTTP/2 和 HTTP/3 各 16 路请求均复用一条公开连接。动态首页首渲染 1.60ms，首页为 Process FPC HIT。持续 1,000,000 请求为 0 错误、11,237.76 QPS、p95 21.118ms、p99 26.373ms、max 50.927ms；追加 100,000 请求后 PID/RSS 保持稳定。压测中单 Worker 恢复 READY 为 856ms，裸/带 Key 后台登录为 404/200。

macOS 最终代码代使用 PHP 8.4.22、4 Worker、`auto -> direct/shared_fd/event/stream` 和 Caddy 2.11.4。强制 h1/h2/h3 均为 200 且实际版本为 1.1/2/3；Alt-Svc 首次为 h2、后续自动升级 h3。TLS 1.3 ticket 跨 rolling reload 仍为 `Reused`，HTTP/2 与 HTTP/3 各 16 路请求均只建立一条公开连接。首页 c32×2,500 为 0 错误、11,093.33 QPS、p95 4.225ms、p99 11.871ms、max 11.949ms；health c128×100,000 为 0 错误、8,371.54 QPS、p95 24.677ms、p99 31.880ms、max 48.858ms；fresh TLS 1.3 c32×2,000 为 0 错误、3,186.85 QPS、p95 12.114ms、p99 13.135ms、max 15.248ms。完整停启约 2.424s READY，动态首页首渲染 2.05ms，后台 Key 继续为 404/200。

本轮还确认 `var/` 必须是节点本地运行目录，不能由两个内核或主机并发共享：跨节点共享会让 Session/Memory sidecar 竞争同一 token 文件。该部署约束不属于数据面降级，也不通过扩大共享服务符号的高风险改动绕过。Windows 原生与 FPM 仍未取得本轮权威证据，因此总计划继续保持未完全发布状态。

## 2026-07-14 macOS FPM / WLS 同机对照

环境：macOS、PHP 8.4.22、同一代码与 Host。FPM 使用 4 个 static PHP-FPM child + Caddy HTTP/1.1/HTTP/2 cleartext；WLS 使用 4 Worker `auto -> direct/shared_fd/event/stream`。同一默认站点、语言和币种 Cookie 下，两端首页除 `data-request-id` 外归一后 SHA-256 均为 `9d3ca35b12559fe68be8c3a4505d551d3accc164355c45f54532c8385fd99638`；静态 SVG 均为 `f431101ec4cd3812fb2450bf364d952c052bba4e97eb0a158e888efef50913dc`。裸 `/admin/login` 两端均 404，合法 backend key 登录页两端均 200。

| Runtime / 并发 / 每轮样本 | 轮次 | 错误 / 非 2xx | QPS 中位 | p95 中位 | p99 中位 | max 中位 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| FPM / 1 / 200 | 1 | 0 / 0 | 23.20 | 44ms | 45ms | 46ms |
| FPM / 32 / 1,000 | 5 | 0 / 0 | 73.09 | 472ms | 496ms | 500ms |
| FPM / 128 / 1,000 | 1 | 0 / 0 | 77.32 | 1,690ms | 1,701ms | 1,712ms |
| WLS / 1 / 200 | 1 | 0 / 0 | 4,535.87 | 0ms | 0ms | 1ms |
| WLS / 32 / 400 | 5 | 0 / 0 | 15,784.70 | 3ms | 6ms | 7ms |
| WLS / 128 / 500 | 1 | 0 / 0 | 13,987.19 | 32ms | 33ms | 34ms |

WLS 正式轮严格控制在当前 `3000/60s/IP` 实例限流窗口内。早先用 ApacheBench 连续超出预算的轮次虽然 `Failed requests=0`，但存在 429 `Non-2xx responses`，已全部作废，不计入性能证据。Browser 实际打开 FPM `:9940` 和 WLS `:9941`，两端标题、H1、7 个主体区块、18 个入口链接完全一致，Console error/warn 均为 0。

该结果关闭 FPM 对照缺口，但不代替 Windows 原生矩阵。当前唯一剩余的跨平台发布证据为 Windows `auto -> dispatcher`、Direct/independent 拒绝、event DLL ABI 与启动/长稳验收。
