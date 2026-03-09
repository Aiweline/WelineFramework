# 常见错误速查表

快速查找常见错误的解决方案。

## 事件系统

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Argument could not be passed by reference` | dispatch 直接传数组字面量 | 先存入变量再传递 |
| 观察者未执行 | 事件未被触发 | 检查 dispatch 调用是否存在 |
| 事件数据为 null | 数据格式错误 | 使用 `['data' => [...]]` 包装 |

## PHP 类型

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Cannot pass by reference` | 引用参数传了字面量 | 使用变量 |
| `Type error` | 参数类型不匹配 | 检查类型声明 |
| `PcController::fetch(): Argument #2 ($data) must be of type array, string given` | 旧代码把布局字符串作为第二参数传给 `fetch('', 'blank')`，而 `fetch()` 第二参数已是 `array $data` | 使用 `$this->layoutType = 'default.blank'; return $this->fetch();`，不要把布局字符串传给 `$data` |
| `executeConcurrentRequests(): Argument #4 ($headers) must be of type array, string given` | `http:req -H` 传入单字符串头，未先归一化为数组/键值对 | 在命令入口统一做 header 归一化，支持 `"Host: a.com"` 与数组形式 |
| `server:status` 显示运行中但 PID 不存在 | 仅依赖实例文件 `state` 或端口弱信号，未以 PID 存活为准 | 状态判定改为 PID 优先（`Processer::processExists`），端口仅作无 PID 回退 |
| `maintenance rolling` 完成后仍显示维护模式启用 | 在滚动标志未清理前调用 disable，被“滚动中”保护拒绝 | 先清 `rollingRestartInProgress`，再执行 `disableMaintenanceMode()` |
| `cache:clear` 提示 WLS 重载失败（命名实例运行中） | IPC 重载只通知 `default` 实例，忽略命名实例 | 遍历运行中的所有实例发送 IPC reload 通知 |
| `获取 DNS 记录失败：...提交修改DNS失败（错误码：-1）`（GName） | DNS 记录接口仍调用历史 `api/domain/dns*`，与当前文档 `api/resolution/*` 不匹配，误命中修改 DNS 错误 | DNS 记录接口切到 `api/resolution/list/add/edit/delete`，并兼容 `api/jiexi/*` + 历史端点兜底 |
| `未注册的查询器：server` | QueryProvider 新增/修改后未重建 extends 注册表，或 WLS 仍在旧内存态 | 执行 `php bin/w extends:rebuild`，随后 `php bin/w server:reload`（必要时 `s:up`） |
| Linux 前台 `Ctrl+C` 看不到和 Windows 一样的停机阶段 | 信号停机走了本地直出旧方案，且子进程未显式忽略 `SIGINT`，导致未统一复用 IPC 停机流程 | Master 信号入口统一改为自连控制端口发送 IPC `STOP`；子进程显式 `pcntl_signal(SIGINT, SIG_IGN)` |
| `pagination(): Argument #1 ($page) must be of type int, string given` | GET/POST 参数为 string | 传参前用 `(int) $this->request->getParam('page', 1)` 等 |
| `Undefined method` | 方法不存在 | 检查类名和方法名 |
| `Undefined property` | 属性未声明或未初始化 | 检查属性声明和构造函数赋值 |
| `htmlspecialchars(): ... array given` | 配置值为数组但直接进入 `htmlspecialchars()` | 先做类型归一化，非 string/number 回退默认值 |
| `Value of type null is not callable` | 模板调用未注入的 `$getConfig` 等闭包 | 在渲染上下文注入闭包，或改用 `$component_config` |

## 数据库

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Unknown column` | 字段名错误 | 使用模型常量 |
| `Integrity constraint` | 外键/唯一约束冲突 | 检查数据完整性 |
| `column "count(*)" does not exist` | AbstractCompiler 将聚合函数当标识符加引号 | `quoteFieldExpression()` 需检测函数调用模式 |
| 删除提示成功但数据还在 | 只调用 `delete()` 未调用 `fetch()` | **必须**使用 `delete()->fetch()` |
| 删除/更新无效 | 准备了 SQL 但未执行 | 所有写操作后必须调用 `fetch()` |
| `Allowed memory size ... exhausted` | 全量扫描/清理一次性加载过多记录 | 使用分页清理与释放临时数组；必要时提高内存限制 |
| `groupBy()` 报错 | ORM 不支持 `groupBy()` 或链路中断 | 改用 `group('a, b')` |
| `HAVING must be type boolean` | having 需要完整布尔表达式 | 使用 `having('COUNT(*) > 1')` |
| 创建后 ID 为 0 | 保存未返回 ID | 保存后校验 `getId()`，必要时再查询一次 |

## ORM 操作规范 ⚠️ 重要

| 操作 | ✅ 正确写法 | ❌ 错误写法 |
|------|-----------|-----------|
| **删除** | `->where()->delete()->fetch()` | `->where()->delete()` (不执行) |
| **查询** | `->where()->select()->fetch()` | `->where()->fetch()` (跳过select) |
| **更新** | `->where()->update()->fetch()` | `->where()->update()` (不执行) |
| **插入** | `->setData()->save()` 或 `->insert()->fetch()` | `->insert()` (不执行) |

**核心规则**: Weline ORM 的 `delete()`, `update()`, `insert()` 只是准备 SQL，**必须调用 `fetch()` 才能执行**！

## 依赖注入

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Undefined property: $modelName` | 模型未在构造函数注入 | 添加构造函数参数和属性赋值 |
| `Call to member function on null` | 使用了未注入的依赖 | 检查构造函数是否包含该依赖 |
| 属性类型不匹配 | 注入类型与声明不符 | 确保参数类型与属性声明一致 |

## 国际化 (i18n) ⚠️ 重要

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| 翻译中显示 `%1` 原文 | 使用了 `%1` 格式（无花括号） | **必须使用 `%{1}` 格式** |
| 占位符未替换 | 占位符格式错误 | 使用 `%{1}`, `%{name}` 或 `%{}` |
| 翻译参数丢失 | 参数数量不匹配 | 确保参数数量与占位符数量一致 |
| i18n CSV 尾部出现乱码或 `?`/NUL | 文件被混入二进制片段或非 UTF-8 尾段 | 先清理损坏尾段并统一 UTF-8，再执行 `php bin/w i18n:collect`；修复后校验 `NUL=0`、UTF-8 strict decode、CSV 两列结构 |

### i18n 占位符格式对照表 ⚠️ 极易出错

| ❌ 错误格式 | ✅ 正确格式 | 说明 |
|------------|-----------|------|
| `%1`, `%2`, `%3` | `%{1}`, `%{2}`, `%{3}` | 数字占位符**必须带花括号** |
| `__('错误：%1', $msg)` | `__('错误：%{1}', $msg)` | 单参数用 `%{1}` |
| `__('第 %1/%2 页', [$p, $t])` | `__('第 %{1}/%{2} 页', [$p, $t])` | 多参数用 `%{1}`, `%{2}` |

**推荐：使用命名占位符更清晰**
```php
// 最佳实践：命名占位符
__('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count])
```

**注意**: 代码库中存在大量历史遗留的 `%1` 格式代码，**不要参考这些错误示例**！

## 框架约定

| 场景 | 正确做法 |
|------|----------|
| 触发事件 | `$data = [...]; dispatch('name', $data);` |
| 获取事件数据 | `$event->getData('data')` |
| Hook 依赖事件 | 确保事件在 Hook 渲染前触发 |
| **模块升级** | 已废弃：Model 的 `upgrade()` 不再使用。表结构用 #[Col]/#[Table]，执行 `php bin/w setup:upgrade` 触发 SchemaDiff；业务初始化放 Setup/Install.php、Upgrade.php |
| **依赖注入** | 控制器/服务使用的模型必须在构造函数注入 |
| **i18n 占位符** | 使用 `%{1}` 或 `%{name}`，**绝对不要用 `%1`** |
| 主题安装报“父主题不存在” | 子主题 `theme register` 先于父主题安装，且未按 `parent` 做依赖排序 | 在 `Weline\Theme\Register\Installer` 中按 `parent` 拓扑排序后安装，父主题先装 |
| `@static` 在 Windows DEV 下输出裸 `/statics/...` | 路径前缀匹配大小写敏感，盘符大小写不一致导致匹配失败 | 路径比较在 Windows 下使用大小写不敏感匹配（`getUrlPath()`） |
| 控制器声明 `: string` 但 `redirect()` 触发 `string|null` 告警 | 静态分析将 `redirect()` 推断为可空返回 | 在 `return` 处显式 `(string)$this->redirect(...)`，确保签名一致 |

## 进程管理

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `Call to undefined function posix_killpg()`（macOS） | PHP 构建未提供 `posix_killpg`，代码直接调用导致 Fatal | 用 `posix_kill(-$pgid, $signal)` 向进程组发信号，失败再降级 `posix_kill($pid, $signal)` |
| `Expected type 'Socket'. Found 'resource'.`（Intelephense） | socket 变量在 PHP 版本/扩展桩下被推断为 resource | 统一用兼容 ID 方法（object→`spl_object_id`，resource→`get_resource_id`），并避免直接依赖单一 socket 类型假设 |
| `server:start` 报“端口 xxx 被占用”（Mac/Linux 直连多 Worker） | 直连 `SO_REUSEPORT` 路径错误地按连续端口范围检查，或 Worker 端口段含非框架占用未自动跳过 | 直连复用模式只检查主端口；非框架占用时自动切换到下一个可用端口/端口段（主端口、Worker 段、HTTP Redirect） |
| `Socket 创建失败 ... Permission denied`（Mac/Linux，Worker 端口 443/444 等） | 1）直连模式端口语义错误导致 Worker 误绑定递增端口；2）非 root 绑定 `<1024` 端口未提前进行 sudo 引导 | 1）直连模式统一使用主端口 + `SO_REUSEPORT`；2）`server:start` 入口检测特权端口并自动 `sudo` 重启（触发密码输入） |
| HTTPS 实例访问 `http://host:port/...` 不跳转（Windows Dispatcher） | Dispatcher 仅透传 TCP，未在入口识别明文 HTTP 并执行重定向 | 在 Dispatcher 入口先识别协议：HTTPS 模式 + 明文 HTTP 时直接返回同端口 `301 Location: https://host:port/...` |
| `Call to undefined function stream_socket_recv()`（Worker SSL） | 在 `worker_ssl.php` 里误用不存在的 stream API | 改为 `stream_socket_recvfrom($conn, ..., STREAM_PEEK)`；修改后重启服务并用 HTTP/HTTPS 各回归一次 |
| WLS 子进程不断累积为孤儿进程 | 1）子进程具备复活 Master 能力导致控制面分散；2）Windows 非阻塞瞬时 PID 不可靠；3）缺少 `epoch + launch_id` 代际隔离 | 收口为 Master 单主控；禁用子进程复活；IPC 增加 `epoch + launch_id` 校验；引入 reconcile + orphan sweeper，旧代际进程统一回收 |
| WLS 启动后频繁 `register_timeout` + 整组重启风暴 | 1）周期 orphan sweeper 在主循环执行重型 kill 扫描，阻塞 IPC poll；2）`register_timeout` 配置过短（低于启动宽限）导致误判 | 周期扫尾默认改为轻量（仅 stale pid 清理）；重型按前缀 kill 仅在 full restart 后执行；`register_timeout` 至少与 `startupGracePeriod` 一致 |
| 停机日志显示“Master 已退出”，但进程实际仍在（假退出） | 提前删除 Master PID 索引，`hasExitedFast()` 仅看索引导致误判；前台文案过早使用“已退出” | 停机阶段不提前删 Master 索引；退出判定改为 `hasExitedFast && !processExists` 双确认；前台文案改为“退出流程已完成（进程即将退出）” |

## 模块升级

| 错误 | 原因 | 解决方案 |
|------|------|----------|
| `upgrade()` 方法不执行 | 已废弃：Model upgrade() 不再被调用 | 表结构用 #[Col] 声明，运行 `php bin/w setup:upgrade`；业务初始化放 Setup/Install.php、Upgrade.php |
| 数据库字段未添加 | 未用声明式 schema 或未执行 setup:upgrade | 在 Model 上增加 #[Col]，执行 `php bin/w setup:upgrade` |
| 升级逻辑被跳过 | 已废弃：不再通过版本号触发 Model upgrade | 数据/种子迁移写在 Setup/Upgrade.php，表结构用 #[Col]+setup:upgrade |
| `setup:upgrade` 在 `UrlManager/Plugin/ModuleUpgradeExecuteAfterPlugin.php` 内存溢出 | 路由文件加载后逐条 `save()` 导致 ORM 中间态与内存峰值持续抬升，128M 容易在 `include` 阶段 OOM | 使用分块批量 upsert：`insert($batchRows, identify)->fetch()`；分段处理后 `unset + gc_collect_cycles()`；必要时仅在 `include` 前临时提升 `memory_limit` 并立即恢复 |
| `UrlManager` 批量导入报 `SQLSTATE[23505] duplicate key ... idx_identify` | 批量写入未先做批内 `identify` 去重，且 Pgsql 批量链路下冲突未被稳定吸收 | `flushBatchRows()` 先按 `identify` 去重，再按 `identify IN (...)` 批量删除旧记录后批量插入（幂等） |
| `Call to undefined method columnExist()` | 方法名错误 | 使用 `$setup->hasField()` 检查字段 |
| `addColumn()` 直接在 setup 调用失败 | 需要先获取 alterTable | 使用 `$setup->alterTable()->addColumn()` |
| `Argument #3 ($type) must be of type string` | alterTable 和 createTable 签名不同 | alterTable: `(name, after, type, len, opt, comment)` |

## alterTable vs createTable 区别

**createTable 签名**（安装时）：
```php
->addColumn(name, type_constant, length, options, comment)
```

**alterTable 签名**（升级时）：
```php
->addColumn(name, after_column, type_string, length, options, comment)
->alter()  // 执行修改
```

---

## 开发技巧

### CLI 命令查找

| 技巧 | 说明 | 示例 |
|------|------|------|
| **命令缩写** | `php bin/w` 支持命令缩写匹配 | `php bin/w c:f` = `cache:flush` |
| **模糊搜索** | 输入部分命令名会列出匹配项 | `php bin/w static` 列出所有 static 相关命令 |
| **帮助信息** | 单独运行列出所有命令 | `php bin/w` |

### 常用命令缩写

| 缩写 | 完整命令 | 用途 |
|------|----------|------|
| `c:f` | `cache:flush` | 清理缓存 |
| `s:up` | `setup:upgrade` / `system:upgrade` | 系统升级 |
| `s:c` | `static:compile` | 编译静态资源 |
| `http:req` | `http:request` | HTTP 请求测试 |

### ORM save() 查询状态叠加导致 NOT NULL / 主键冲突

| 症状 | 原因 | 修复 |
|------|------|------|
| `SQLSTATE[23502]: Not null violation` on save() | `checkUpdateOrInsert()` 未 `clearQuery()`，前序操作（load/find）的 WHERE 叠加导致查不到已有记录，误走 INSERT 分支 | 框架已修复：`AbstractModel::checkUpdateOrInsert()` 三处操作前均加 `clearQuery()` |
| `SQLSTATE[23505]: Unique violation` on save() | 同理，查不到已有记录导致 INSERT 冲突 | 同上 |
| `clone + load() + save()` 偶发插入而非更新 | query 对象共享状态 | 同上；或业务层在 save() 前手动 `$model->getQuery()->clearQuery()` |

### ACL 未定义路由被误拦截

| 症状 | 原因 | 解决方案 |
|---|---|---|
| 后台某个未配置 `#[Acl]` 的路由也被权限系统拦截 | ACL 判定逻辑只看“角色是否命中 route”，没有先判断“该 route 是否存在 ACL 定义” | 先查 `weline_acl.route` 是否存在该 route；不存在则按白色 ACL 放行 |
| 需求是“不在权限内的都属于白色 ACL”，但仍要求登录/角色 | `RouteBefore` 仅检查显式白名单表 `WhiteAclSource`，没有把“未定义 ACL 的 route”视为白名单 | 在拦截前增加 `isRouteProtected()` 判定，未定义 ACL 的 route 直接 return |
| DevToolPanel 攻击统计 404（命中 `pagebuilder/backend/server-monitor/attack-stats`） | Hook/模板中把跨模块 URL 写成 `*/backend/...`，`*` 在当前模块上下文被替换成错误前缀 | 跨模块调用改为固定路由 `server/backend/server-monitor/attack-stats`（及同类链接） |

### PostgreSQL ON CONFLICT 字段不匹配

| 症状 | 原因 | 解决方案 |
|---|---|---|
| `SQLSTATE[25P02]: current transaction is aborted` 出现在一次 `save()`/`insert()` 后 | 前一条 SQL 其实已经因为 `ON CONFLICT (...)` 不匹配真实唯一约束而失败，`25P02` 只是事务中止后的连带报错 | 先检查首个异常；确认 `ON CONFLICT` 字段与数据库唯一索引完全一致 |
| 配置表/字典表 `save()` 在 PostgreSQL 下报错，但 MySQL 正常 | 业务代码把普通字段也当成冲突字段（如 `module`,`name`），生成的冲突键超过真实唯一索引范围 | `setData(..., true)` 仅用于真实唯一键字段，普通字段改为普通 `setData()` |

### WLS 状态泄漏

| 错误 | 原因 | 解决方案 |
|---|---|---|
| 页面标题显示上个请求的标题 | Template 单例 `_data` 跨请求残留 | `Template::resetInstance()` + 注册到 StateManager |
| 模板路径指向上个模块 | Template 单例 `view_dir` 等残留 | 同上 |
| backend/frontend 区域判断错误 | `State::$is_backend` 残留 | `registerStaticReset(State::class, 'is_backend', false)` + 清除 ObjectManager 实例 |
| 单例类 `??` 操作符不覆盖已有值 | WLS 下单例跨请求保留，`??` 跳过赋值 | 重置单例实例，或改用无条件赋值 |
| 后台 REST 请求稳定慢 3-4 秒（连 401/404 都慢） | 将 `EventsManager` 观察者缓存按请求清空，导致每请求重建事件观察者/模块状态 | `resetRequestState()` 仅清请求级 `$events`；保留 `observerCache/eventsObservers/moduleStatusCache` 为进程级热缓存 |
| 登录失败提示越试越多（“登录凭据错误”重复） | 登录场景误用 `MessageManager::error()`（append 语义），连续失败自然累积 | 登录控制器改用 `MessageManager::setSingleError()`；保留 `error()` 给需要多条提示的通用场景 |
| 后台监控页/攻击日志页 500：`setTitle() on null` | 模板直接调用 `$block->setTitle()`，在 WLS + `view/tpl/com_*` 路径下 `$block` 上下文不稳定 | 控制器统一 `assign('title', ...)`；模板移除 `$block->setTitle()`；`Template::ob_file()` 统一注入 `block` 上下文 |
| 后台优化页 500：`OptimizationGuide/com_index.phtml setTitle() on null` | 历史 `view/tpl/com_*` 编译模板链路仍依赖 `$block`，仅 data 注入不足以覆盖全部上下文 | `Template::ob_file()` 强制本地 `$block = $this`，并同步 `setData('block', $this)` 双通道注入 |
| 后台攻击日志页 500：`getBackendUrl() on null` | 模板依赖 `$block->getBackendUrl()`，在 WLS + 历史编译模板上下文下 `$block` 可能为空 | 后台模板 URL 生成统一改为 `$this->getBackendUrl()`，避免运行时依赖 `$block` |
| 内存服务管理显示“不可用”，但进程实际运行中 | 连接池缓存了旧 token；Memory/Session 服务重启后 token 轮换，重连认证仍用旧凭证 | 在 `PooledConnection` 增加 token 文件 mtime 检测；认证失败时强制刷新 token 并重试一次 |
| Session/Memory 管理页偶发“不可用”误报 | 探活仅依赖 `ping()`，重连窗口中的瞬时失败会直接标红 | connected 判定改为 `ping || stats成功`，并补一次轻量重试与 probe 诊断信息 |
| Session/Memory 管理页持续“不可用”，但进程全绿 | `session.server_host` 或 `server.memory_service.host` 配置存在但为空串，探活客户端使用空 host 连接失败 | `SharedStateAdminService` 对 host 做 `trim`，为空时回退 `127.0.0.1` |
| Session 管理列表始终为空（后台已登录） | 协议层内部过滤键 `__domain` 被误用于聚合 payload 字段匹配，真实会话全部被过滤 | `listSessions()` 先清洗 payload 过滤参数，移除 `__*` 内部键后再做业务匹配 |
| Session 管理列表出现 `__ns__:cache:*`（误以为缓存写入 Session） | WLS 统一状态服务下未限定查询域，Session 列表请求拿到了 cache 命名空间数据 | `SharedStateAdminService::listSessions()` 对 `WlsSharedStorage` 强制注入 `__domain=session` |
| `w_query('server','sessionList')` 返回空数组（已登录） | 核心 `ServerQueryProvider::sessionList()` 仅透传共享列表，缺少“当前后台会话可见”兜底 | 在查询器层统一补齐当前后台会话可见性，调用方保持透传，避免模块级分叉补丁 |
| Session 统计有数据但列表返回空数组 | 列表接口存在隐式 domain 过滤，且服务端默认跳过空 payload state | 取消默认 `__domain` 注入；`SessionServer::listSessions()` 按“全量”返回，不再丢弃空 payload |
| 暗色模式过段时间失效（Session 像被重置） | `SessionStore` 读取仅更新 LRU，不刷新 TTL；读多写少场景下 Session 到点过期被 GC 回收 | 在 `SessionStore::get()` 调用 `touch()` 实现滑动过期；并建议对齐 `session_ttl/lifetime` 与 `cookie_lifetime` |
| WLS 下记住我登录后仍循环跳登录页/登录页反复警告 | 自动登录分支未显式 `save + writeClose + Set-Cookie`，下一跳 ACL 仍判未登录；同时把 `sess_id` 校验做成硬拒绝，且在 `admin/login/post` 也抢先执行记住我分支 | 保留 token 认证为主：`sess_id` 无效时降级为新会话登录；并强制落盘+回写 `WELINE_SESSID`；对 `admin/login/post` 跳过记住我逻辑，避免干扰真实登录 |
| 后台 EnvManager 页面 404（`.../backend/framework/env-manager`） | 模块后台路由前缀应为 `weline_framework`，且路径顺序应为 `{backend_router}/backend/{controller}`，手写成了 `backend/framework/...` | 菜单 action 与模板 URL 改为 `weline_framework/backend/env-manager`；执行 `setup:upgrade --route` 刷新路由 |

### 前端交互与层级

| 错误 | 原因 | 解决方案 |
|---|---|---|
| AI 组件工坊点击“开始生成”无响应 | 提交前未做稳健的步骤1参数收集与校验反馈；缺少加载态与异常路径恢复 | 增加参数收集函数、必填项 `is-invalid` 高亮、统一通知封装、开始按钮 loading/disabled 状态管理 |
| 可视化组件弹层层级错乱（按钮/弹窗被遮挡） | modal/backdrop/fullscreen/config 各自写死 z-index，缺少统一号段 | 统一弹层 z-index 体系（工坊、backdrop、全屏、配置弹层分层）并保持同页面内一致 |

### HTTP 请求测试（查看页面源码）

当需要检查页面 HTML 内容时，使用 `http:req` 命令：

```bash
# 基本用法：请求页面
php bin/w http:req "/path/to/page"

# 搜索页面中的特定内容
php bin/w http:req "/path/to/page" "filter=关键词"

# 搜索并显示上下 5 行上下文
php bin/w http:req "/path/to/page" "filter=关键词" -n=5

# 带参数的 URL
php bin/w http:req "/catalog/category?price=100-200" "filter=error"

# 测试后端页面（需登录）
php bin/w http:req "admin/dashboard" -b --login -u=admin -p=123456
```

**使用场景**：
- 检查页面是否包含预期的 HTML 元素
- 搜索页面中的调试信息、错误信息
- 验证模板渲染结果
- 测试 API 响应
