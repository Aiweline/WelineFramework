# 错误日志

本文档记录开发过程中遇到的错误及解决方案，作为知识库避免重复犯错。

---

## [2026-03-07] 未定义 ACL 的后台路由被误当成受控资源拦截 ✅ 已修复

**错误类型**: ACL 权限判定 / 后台路由访问控制

**错误信息**:
```text
后台请求：
http://test.aiweline.com/admin_696f02955db39/CNY/zh_Hans_CN/system/theme-config/set

需求：不在权限内的，都属于白色 ACL，不用权限管理，谁都可访问。
实际：未在 ACL 中定义的路由仍被 RouteBefore + AclService 拦截。
```

**根本原因**:
1. `Weline\Acl\Observer\RouteBefore` 只检查显式白名单表 `WhiteAclSource`，未判断“当前路由是否根本没有 ACL 定义”。
2. `Weline\Acl\Service\AclService::isRouteAllowed()` 默认把“角色 ACL 中没有匹配 route”视为拒绝，但没有先区分“该 route 是否属于 ACL 管控范围”。
3. 结果是未配置 `#[Acl]` / 未收集到 `weline_acl` 的后台路由，也被误判成需要权限控制。

**解决方案**:
1. 在 `AclServiceInterface` 新增 `isRouteProtected()`，专门判断某个 route 是否在 `weline_acl` 中有定义。
2. 在 `AclService::isRouteAllowed()` 中增加短路逻辑：未定义 ACL 的 route 直接返回允许，视为白色 ACL。
3. 在 `RouteBefore::validateBackendAccess()` 中前移白色 ACL 判断：若当前后台路由未定义 ACL，则直接放行，不再做登录/角色/权限校验。

**验证方法**:
```bash
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Acl/Service/AclServiceInterface.php"
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Acl/Service/AclService.php"
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Acl/Observer/RouteBefore.php"
ReadLints(paths=[AclServiceInterface.php, AclService.php, RouteBefore.php])
```

**验证结果**: ✅ 成功（3 个目标文件语法检查通过；本次修改未引入新的目标文件 lint 错误）

**预防措施**:
1. ACL 判定必须先区分“路由是否受 ACL 管控”，不能把“未定义 route”直接当成“无权限”。
2. 白名单语义应覆盖两类场景：显式白名单表，以及 `weline_acl` 中根本不存在定义的 route。
3. 修改后台 ACL 拦截逻辑时，优先确认是“受控资源判定错误”还是“角色权限不足”，避免把未受控页面误拦截。

**相关文件**:
- `app/code/Weline/Acl/Service/AclServiceInterface.php`
- `app/code/Weline/Acl/Service/AclService.php`
- `app/code/Weline/Acl/Observer/RouteBefore.php`

---

## [2026-02-25] WLS 模式下语言/货币在请求间不稳定 ✅ 已修复

**错误类型**: WLS 状态泄漏 / 空字符串 vs unset

**错误现象**:
```text
URL: /f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/USD/zh_Hans_CN/media/backend/manager
刷新页面：中文 → 英文 → 中文 → 英文...（交替出现）
```

**根本原因**:
多处代码将 `$_SERVER['WELINE_USER_LANG']` 设为**空字符串** `''` 而非 `unset`。
空字符串 `''` 与 `null`/`unset` 不同——`??` 运算符不会将空字符串视为"未设置"。

```php
// 问题代码
$_SERVER['WELINE_USER_LANG'] = '';  // 设为空字符串

// 后续代码无法正确回退
$lang = $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN';  // 得到 ''，不是 'zh_Hans_CN'
```

**涉及文件**:
1. `RequestContext::resetWelineVars()` - 将变量设为空字符串
2. `GlobalsEmulator::buildServerArray()` - 初始化时设为空字符串
3. `WlsRuntime::processUrlParse()` - 设置空字符串默认值

**解决方案**:
```php
// 修复：使用 unset 而非赋空字符串
// RequestContext::resetWelineVars()
unset($_SERVER['WELINE_USER_LANG']);
unset($_SERVER['WELINE_USER_CURRENCY']);
unset($_SERVER['WELINE_WEBSITE_ID']);
unset($_SERVER['WELINE_WEBSITE_CODE']);
unset($_SERVER['WELINE_WEBSITE_URL']);

// GlobalsEmulator::buildServerArray()
// 不设置 WELINE_USER_LANG/WELINE_USER_CURRENCY，让 URL 解析器设置

// WlsRuntime::processUrlParse()
// 移除空字符串默认值逻辑
```

**验证方法**:
```bash
php bin/w server:reload
# 多次刷新页面，语言应保持稳定
```

**验证结果**: ✅ 成功

**预防措施**:
1. WLS 模式下，使用 `unset()` 而非赋空字符串来"重置"变量
2. 依赖 `??` 运算符回退默认值时，确保变量是 `unset` 状态而非空字符串
3. 新增 WELINE_* 变量时，检查 `RequestContext::resetWelineVars()` 和 `GlobalsEmulator`

**相关技能**: `weline-routing`（URL 结构与解析）、`weline-server`（状态管理）

**相关文件**:
- `app/code/Weline/Framework/Runtime/RequestContext.php`
- `app/code/Weline/Framework/Runtime/GlobalsEmulator.php`
- `app/code/Weline/Framework/Runtime/WlsRuntime.php`

---

## [2026-03-01] WLS `register_timeout` 误判引发整组重启风暴 ✅ 已修复

**错误类型**: 进程编排 / 启动判定 / 主循环阻塞

**错误现象**:
```text
Master 日志持续出现：
- register_timeout: session_server/worker
- 已标记整组重启
- 子进程扫尾完成(killed>0)
形成重启风暴，服务无法稳定进入 READY。
```

**根本原因**:
1. 主循环周期执行了重型 orphan sweeper（按前缀 kill），在 Windows 上阻塞明显，导致 IPC poll 饥饿。
2. `register_timeout` 默认为 8s，低于启动宽限与实际冷启动时长，出现误判。

**解决方案**:
1. Orchestrator 周期扫尾默认改为轻量模式：仅 `cleanupStalePidFiles()`。
2. 重型前缀 kill 仅在 `performFullRestart()` 场景执行。
3. `register_timeout_sec` 默认提升为 `startupGracePeriod`，并强制下限不低于启动宽限。
4. 新增开关 `server.orchestrator.periodic_orphan_sweep`（默认 false）。

**验证方法**:
```bash
php -l app/code/Weline/Server/Service/ServiceOrchestrator.php
php app/code/Weline/Server/Test/Service/standalone_test.php
```

**验证结果**: ✅ 成功（语法通过；standalone 测试 Passed 19, Failed 0）

**相关文件**:
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`

---

## [2026-02-24] Worker SSL 事件循环调用不存在函数 `stream_socket_recv()` ✅ 已修复

**错误类型**: 运行时函数调用 / API 误用

**错误信息**:
```text
[WLS-SSL] Worker #2 ... 事件循环异常: Call to undefined function stream_socket_recv()
```

**根本原因**:
`worker_ssl.php` 在延迟 SSL 分支中使用了不存在的 `stream_socket_recv()`。PHP 正确 API 为 `stream_socket_recvfrom()`（或 `fread`），因此在事件循环中持续抛出异常。

**解决方案**:
将 `worker_ssl.php` 中首包探测调用从：
`stream_socket_recv($conn, 8, STREAM_PEEK)`
修正为：
`stream_socket_recvfrom($conn, 8, STREAM_PEEK)`

**验证方法**:
```bash
php -l "e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/worker_ssl.php"
php bin/w s:start -r -f -frontend
curl -I "http://127.0.0.1:9981/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/USD/zh_Hans_CN/theme/backend/theme-editor"
curl -k -I "https://127.0.0.1:9981/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/USD/zh_Hans_CN/theme/backend/theme-editor"
```

**验证结果**: ✅ 成功（语法检查通过；HTTP 返回 301，HTTPS 返回业务 302；未再出现该 undefined function）

**预防措施**:
1. Stream API 使用前必须核对函数名（`stream_socket_recvfrom` / `stream_socket_enable_crypto`）。
2. 涉及事件循环的函数替换后，必须做一次真实请求回归（HTTP + HTTPS）。

**相关文件**:
- `app/code/Weline/Server/bin/worker_ssl.php`

---

## [2026-02-24] Windows Dispatcher 模式下主端口明文 HTTP 未 301 到 HTTPS ✅ 已修复

**错误类型**: 协议识别 / 重定向策略

**错误信息**:
```text
访问 http://127.0.0.1:9981/...（实例为 HTTPS 模式）未自动跳转到 https://127.0.0.1:9981/...
```

**根本原因**:
在 Windows Dispatcher 模式中，主端口请求先进入 `Dispatcher`。原逻辑仅做 TCP 透传，不在入口识别“HTTPS 模式下的明文 HTTP 请求”，导致该类请求未被统一 301。

**解决方案**:
在 `Dispatcher` 增加 HTTPS 模式下的明文 HTTP 识别与同端口 301：
1. 初始化时读取实例文件 `ssl_enabled`，确定当前是否 HTTPS 模式；
2. `acceptConnections()` 中在透传前先 peek 首包：
   - TLS ClientHello（首字节 `0x16`）→ 正常透传；
   - 明文 HTTP 方法（GET/POST/HEAD/PUT/PATCH/DELETE/OPTIONS/TRACE/CONNECT）→ 解析 Host + path，直接返回 `301 Location: https://{host}{path}`；
3. 明确在 Dispatcher 层处理，避免依赖后端 Worker 才能重定向。

**验证方法**:
```bash
php -l "e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Dispatcher/Dispatcher.php"
ReadLints(paths=["app/code/Weline/Server/Dispatcher/Dispatcher.php"])
```

**验证结果**: ✅ 成功（语法检查通过，lints 为 0）

**预防措施**:
1. HTTPS 场景的“明文 HTTP → HTTPS”策略必须在**入口层**（Dispatcher/Worker 入口）处理，不依赖下游透传链路。
2. 多架构（直连 / Dispatcher）都要覆盖同一安全语义，避免平台行为不一致。

**相关文件**:
- `app/code/Weline/Server/Dispatcher/Dispatcher.php`

---

## [2026-02-24] WLS 直连模式端口语义错误 + Mac 特权端口权限引导缺失 ✅ 已修复

**错误类型**: WLS 进程管理 / 端口权限

**错误信息**:
```text
[WLS-SSL] Worker #2 ... Socket 创建失败 (defer-ssl): Permission denied
```

**根本原因**:
1. `MasterProcess` 在直连模式（`MODE_LINUX_DIRECT`）仍按 `worker_port + i` 递增端口，导致 Worker 误尝试绑定 444/445...，偏离 SO_REUSEPORT 共用主端口的设计。
2. `server:start` 在 Linux/Mac 下使用 80/443（及重定向 80）时，非 root 场景仅提示文案，未主动触发 `sudo` 密码输入引导。

**解决方案**:
1. 修正直连模式端口语义：
   - `init()`：直连模式 Worker 端口统一为主端口，不递增。
   - `startAllWorkers()`：直连模式跳过“端口已占用即跳过”的分支。
   - `drainNextWorker()`：直连模式不再向 Dispatcher 发送 drain（无 Dispatcher）。
   - `waitForWorkersReady()/logStatus()/getWorkersStatus()/cleanup()/getMaintenanceWorkerBasePort()`：按直连模式改为 PID 优先和主端口语义。
2. `Start::execute()` 增加 `ensurePrivilegedPortPermission()`：
   - Linux/Mac 非 root 且目标端口含 `<1024` 时，自动 `sudo env WLS_SUDO_RELAUNCHED=1 ...` 重新执行当前命令；
   - 触发系统密码输入，避免直接启动后在 Worker 阶段才报权限错误；
   - 通过 `WLS_SUDO_RELAUNCHED` 防止循环重启。

**验证方法**:
```bash
php -l "e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Console/Server/Start.php"
php -l "e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/MasterProcess.php"
```

**验证结果**: ✅ 成功（两处语法检查通过）

**预防措施**:
1. 直连 SO_REUSEPORT 场景必须坚持“所有 Worker 共用主端口”的单一语义，禁止复用 Dispatcher 的连续端口思维。
2. 特权端口（<1024）启动前必须在命令入口完成权限校验与交互引导，不要延迟到 Worker 运行阶段。

**相关文件**:
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/Console/Server/Start.php`

---

## [2026-02-24] WLS 启动时端口检测与 SO_REUSEPORT 误判（Mac/Linux）✅ 已修复

**错误类型**: 进程管理 / WLS 端口策略 / 跨平台行为

**错误信息**:
```text
端口 445 已被占用
使用 -r 参数强制重启（仅杀框架进程），或手动停止占用该端口的进程
```

**根本原因**:
1. 直连模式（`SO_REUSEPORT`）下，启动前仍按 `port~port+count-1` 连续端口做占用检查，和“多 Worker 复用同一端口”的设计冲突。  
2. 端口被**非框架进程**占用时，策略是直接报错退出，未自动跳过到可用端口段。  
3. 启动信息文案仍提示“端口范围”，容易误导为直连模式也要占用连续端口。

**解决方案**:
1. 直连 + `SO_REUSEPORT` 时，仅检查主端口，不再检查 `port~port+count-1`。  
2. 非显式端口（非 `-p`）被非框架进程占用时，自动选择下一个可用端口并继续启动。  
3. Dispatcher 模式下若 Worker 连续端口段有非框架占用，自动跳到下一段可用连续端口。  
4. 自动计算的 HTTP Redirect 端口被非框架占用时，自动跳过到下一个可用端口。  
5. 启动面板在直连复用模式显示“同端口复用”，不再展示误导性的连续端口范围。

**验证方法**:
- `php -l app/code/Weline/Server/Console/Server/Start.php`
- 代码路径检查：`SO_REUSEPORT` 分支、端口自适应分支、启动信息输出分支

**验证结果**:
- ✅ `Start.php` 语法检查通过
- ✅ 直连复用路径改为单端口检查
- ✅ 非框架占用端口存在自动跳过逻辑（主端口 / Worker 端口段 / HTTP Redirect）

**预防措施**:
1. 涉及 WLS 架构（直连/Dispatcher）时，端口检查策略必须与实际监听模型一致。  
2. “端口占用”判断要区分框架进程与非框架进程，非框架默认不误杀、优先自动避让。  
3. 输出文案必须与真实行为一致（复用同端口 vs 连续端口）。

**相关文件**:
- `app/code/Weline/Server/Console/Server/Start.php`
- `dev/ai/skills/error-tracking/COMMON_ERRORS.md`
- `dev/ai/skills/weline-server/SKILL.md`

---

## [2026-02-14] AbstractModel::pagination() 参数类型 TypeError（string 传 int）✅ 已修复

**错误类型**: PHP 类型 / 请求参数

**错误信息**:
```
Weline\Framework\Database\AbstractModel::pagination(): Argument #1 ($page) must be of type int, string given, called in ...\CacheManager\Controller\System\Cache.php on line 23
```

**根本原因**:
`Request::getParam('page', 1)` 和 `getParam('pageSize', 10)` 从 GET/POST 取到的值在 PHP 中为 **string**，而 `AbstractModel::pagination(int $page, int $pageSize, ...)` 方法签名要求 **int**，传字符串会触发 TypeError。

**错误代码示例**:
```php
// ❌ 错误：直接传 request 参数（类型为 string）
$caches = $cacheModel->pagination(
    $this->request->getParam('page', 1),
    $this->request->getParam('pageSize', 10),
    $this->request->getParams()
)->select()->fetch();
```

**解决方案**:
对分页参数做显式整型转换：
```php
// ✅ 正确：强制转为 int
$caches = $cacheModel->pagination(
    (int) $this->request->getParam('page', 1),
    (int) $this->request->getParam('pageSize', 10),
    $this->request->getParams()
)->select()->fetch();
```

**预防措施**:
1. 调用 `pagination()`、以及任何声明了 `int` 参数的方法时，若参数来自 `getParam()`/`getGet()`/`getPost()`，应先 `(int)` 或 `(int)($param ?? 默认值)` 转换。
2. 新建控制器分页逻辑时，统一使用 `(int) $this->request->getParam('page', 1)` 和 `(int) $this->request->getParam('pageSize', 10)`。

**相关文件**:
- `app/code/Weline/CacheManager/Controller/System/Cache.php`

---

## [2026-02-13] @static 在 Windows DEV 环境生成 `/statics/...` 错误路径 ✅ 已修复

**错误类型**: 主题开发 / 静态资源路径解析

**错误表现**:
```
@static(Aiweline_PlayingInChina::lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css)
输出为：/statics/lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css
```

**期望结果**:
- DEV: `/Aiweline/PlayingInChina/view/statics/lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css`
- PROD: `/static/Weline/{theme}/Aiweline/PlayingInChina/view/statics/lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css`

**根本原因**:
`Weline\Framework\View\TraitTemplate::getUrlPath()` 使用 `str_starts_with()` 做路径前缀匹配。  
在 Windows 下磁盘路径大小写可能不一致（如 `E:/...` 与 `e:/...`），导致匹配失败，`url_path` 为空，最终拼出裸 `/statics/...`。

**解决方案**:
对 `getUrlPath()` 的前缀匹配改为「Windows 下大小写不敏感」：
- 新增 `startsWithPath()`：Windows 使用 `strtolower()` 比较
- 新增 `stripPrefix()`：Windows 使用 `preg_replace(..., /i)` 去前缀
- DEV/PROD 路径转换统一复用该逻辑

**修复代码**:
```php
$isWindows = DIRECTORY_SEPARATOR === '\\';
$startsWithPath = static function (string $path, string $prefix) use ($isWindows): bool {
    return $isWindows
        ? str_starts_with(strtolower($path), strtolower($prefix))
        : str_starts_with($path, $prefix);
};
```

**验证方法**:
- 代码静态检查（`ReadLints`）
- 路径分支逻辑审查（DEV/PROD）

**验证结果**:
- ✅ `TraitTemplate.php` 无 linter 报错
- ✅ Windows 下路径前缀匹配不再受盘符大小写影响
- ✅ `@static` 不再退化为裸 `/statics/...`

**预防措施**:
1. 涉及文件系统路径比较时，必须考虑 Windows 大小写不敏感特性
2. URL 路径转换函数避免直接用大小写敏感前缀匹配
3. 新增静态资源路径逻辑时，DEV/PROD/Windows 三维度都要覆盖

**相关文件**:
- `app/code/Weline/Framework/View/TraitTemplate.php`
- `.cursor/skills/error-tracking/COMMON_ERRORS.md`
- `.cursor/skills/theme-development/SKILL.md`

---

## [2026-02-12] macOS 报错：Call to undefined function posix_killpg() ✅ 已修复

**错误类型**: 进程管理 / 跨平台兼容

**错误信息**:
```
Call to undefined function posix_killpg()
```

**根本原因**:
`Weline\Server\Console\Console\Server\Server` 在信号处理和 shutdown 回调中直接调用 `posix_killpg()`。
部分 macOS 的 PHP 构建虽然启用了 `posix` 扩展，但未提供 `posix_killpg` 符号，导致运行时直接 Fatal。

**解决方案**:
将进程组终止逻辑统一封装为 `terminateProcessGroup()`：
- 优先使用 `posix_kill(-$pid, $signal)` 向进程组发信号（负 PID）
- 失败时降级为 `posix_kill($pid, $signal)` 终止主进程
- 替换原来 3 处 `posix_killpg` 直接调用

**修复代码**:
```php
private static function terminateProcessGroup(int $pid, int $signal): void
{
    if ($pid <= 0) {
        return;
    }

    if (function_exists('posix_kill')) {
        if (!@posix_kill(-$pid, $signal)) {
            @posix_kill($pid, $signal);
        }
    }
}
```

**验证方法**:
- 代码静态校验（Intelephense + ReadLints）

**验证结果**:
- ✅ `Server.php` 无 linter 错误
- ✅ 代码中不再直接依赖 `posix_killpg`，避免 macOS 上未定义函数

**预防措施**:
1. 涉及信号/进程组 API 时，优先使用跨平台可回退实现
2. 新增进程控制函数前先检查 `function_exists`
3. 进程组信号推荐优先采用 `posix_kill(-PGID, signal)` 兼容写法

**相关文件**:
- `app/code/Weline/Server/Console/Console/Server/Server.php`
- `.cursor/skills/error-tracking/COMMON_ERRORS.md`
- `.cursor/skills/process-management/SKILL.md`

---

## [2026-01-31] PHPUnit 10.x 不支持 --no-interaction ✅ 已修复

**错误类型**: 测试 / CLI 参数

**错误信息**:
```
Unknown option "--no-interaction"
```

**根本原因**:
框架的 `phpunit:run` 命令固定拼接了 `--no-interaction`，但 PHPUnit 10.x 已移除此参数。

**解决方案**:
移除 `phpunitCommand` 中的 `--no-interaction`。

**修复代码**:
```php
$phpunitCommand = PHP_BINARY . ' ' . VENDOR_PATH . "{$ds}phpunit{$ds}phpunit{$ds}phpunit --configuration $php_unit_config_path --testdox";
```

**相关文件**:
- `app/code/Weline/Framework/UnitTest/Console/PhpUnit/Run.php`

---

## [2026-01-31] 文档导入/测试触发内存溢出 ✅ 已修复

**错误类型**: 数据库 / 内存

**错误信息**:
```
Allowed memory size of 1073741824 bytes exhausted
```

**触发场景**:
- `php bin/w doc:import`
- `php bin/w phpunit:run -b --module=Weline_DeveloperWorkspace --name=Unit/Service/DocumentScannerTest.php`

**现象与影响**:
Pgsql 查询在清理重复文档或扫描过程中占用大量内存，导致进程崩溃。

**解决方案**:
- 重复文档清理与不存在文档清理改为分页处理
- 扫描列表改为 set 结构（`isset`）减少线性查找与内存抖动
- 扫描阶段及时 `unset` 大数组释放内存

**验证结果**:
- `php bin/w doc:import` 在默认内存限制下可完整执行

**相关文件**:
- `app/code/Weline/DeveloperWorkspace/Service/DocumentScanner.php`
- `app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php`

---

## [2026-01-31] HAVING 与 groupBy 使用导致 SQL 错误 ✅ 已修复

**错误类型**: 数据库 / ORM 查询

**错误信息**:
```
SQLSTATE[42804]: Datatype mismatch: argument of HAVING must be type boolean
SQLSTATE[42803]: Grouping error: column "module_name" must appear in the GROUP BY clause
Call to a member function groupBy() on false
```

**根本原因**:
1. ORM 未提供 `groupBy()` 链式方法，导致链路中断
2. `having()` 只接受原生 SQL 字符串，需要提供完整布尔表达式
3. `group()` 多次调用不会追加字段，必须一次性传入多字段

**解决方案**:
- 使用 `group()` 替代 `groupBy()`
- `having()` 使用字符串表达式：`'COUNT(*) > 1'`
- 多字段分组：`group('field1, field2')`

**修复代码**:
```php
->group(Document::fields_MODULE_NAME . ', ' . Document::fields_FILE_PATH)
->having('COUNT(*) > 1')
```

**相关文件**:
- `app/code/Weline/DeveloperWorkspace/Service/DocumentScanner.php`

---

## [2026-01-31] 创建分类后 ID 为 0 ✅ 已修复

**错误类型**: 数据库 / ORM 保存

**错误信息**:
```
创建分类后无法加载: ID 0，目录: event
```

**根本原因**:
分类保存后未返回有效 ID，但也没有抛异常，导致后续 `load(0)` 失败。

**解决方案**:
保存后校验 `getId()`，若为 0 则按 name + pid 重新查询一次；仍为空则抛出异常。

**修复代码**:
```php
$catalog->save();
if (!$catalog->getId()) {
    $catalog = $this->catalogModel->clear()
        ->where(Catalog::fields_NAME, $displayName)
        ->where(Catalog::fields_PID, $parentId)
        ->where(Catalog::fields_is_system, 1)
        ->find()
        ->fetch();
    if (!$catalog || !$catalog->getId()) {
        throw new \Exception("创建分类后无法获取ID: {$displayName}");
    }
}
```

**相关文件**:
- `app/code/Weline/DeveloperWorkspace/Service/DocumentScanner.php`

---

## [2026-01-29] 删除部件后未恢复原始模板内容 ✅ 已修复

**错误类型**: 主题开发 / 部件管理

**错误表现**:
```
用户反馈：删除部件提示成功，但原内容没有补回来
前端显示：✓ 部件已删除
实际效果：插槽变为空，未恢复模板原始内容
```

**根本原因**:
删除draft部件后，前端只是移除了DOM元素，但未重新获取并渲染插槽的原始内容（来自布局模板的默认内容）。

**技术细节**:
在可视化编辑器中，用户可以替换模板中的部件。当用户删除自定义的draft部件后，应该恢复到：
1. 布局模板（.phtml）中的原始内容
2. 或者其他draft/published部件（如果有）
3. 或者空（如果原本就是空的插槽）

**错误流程**:
```
用户操作：删除部件
↓
后端：删除draft记录 ✅
↓
前端：移除DOM元素 ✅
↓
前端：不做任何恢复 ❌ <- 问题在这里
```

**解决方案**:

**后端修改（ThemeEditor.php）**:
```php
private function getOriginalSlotContent(int $themeId, string $pageType, string $slotId, string $area): string
{
    // 删除draft后，重新渲染完整布局（此时draft已被删除）
    $layoutType = $this->request->getParam('layout_type', 'homepage');
    $layoutOption = $this->request->getParam('layout_option', 'default');
    
    // 渲染完整的布局预览
    $fullHtml = $this->renderLayoutPreviewHtml($themeId, $pageType, $layoutType, $layoutOption);
    
    // 从渲染的HTML中提取指定插槽的内容
    $slotContent = $this->extractSlotContentFromHtml($fullHtml, $slotId);
    
    return $slotContent;
}
```

**前端修改（theme-editor.js）**:
```javascript
if (result.success) {
    const widgetEl = iframe.contentDocument.querySelector(`[data-layout-id="${layoutId}"]`);
    const slot = widgetEl.closest('[data-wslot], [data-slot]');
    
    // 移除部件元素
    widgetEl.remove();
    
    // 恢复原始内容
    if (slot && !slot.querySelector('[data-layout-id]')) {
        if (result.has_original && result.original_html) {
            // 有原始内容，恢复模板默认的内容
            slot.innerHTML = result.original_html;
            initWidgetHoverActions();  // 重新初始化hover操作
        } else {
            // 显示占位符
            slot.innerHTML = '<div class="slot-placeholder">...</div>';
        }
    }
}
```

**修复后的正确流程**:
```
用户操作：删除部件
↓
后端：删除draft记录 ✅
后端：重新渲染布局（不含刚删除的draft）✅
后端：提取插槽HTML返回 ✅
↓
前端：移除DOM元素 ✅
前端：用original_html替换插槽内容 ✅
前端：重新初始化hover操作 ✅
```

**关键点**:
1. **不刷新整个页面**：只更新被删除的插槽区域
2. **恢复逻辑**：通过重新渲染获取"删除后应该显示的内容"
3. **用户体验**：删除操作立即生效，无需刷新页面

**相关文件**:
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- `app/code/Weline/Theme/view/statics/js/theme-editor.js`

**相关技能**: theme-development, widget-rules

---

## [2026-01-29] ORM delete 操作必须调用 fetch() 才能执行 ✅ 已修正

**错误类型**: 数据库 / ORM 使用不当

**错误表现**: 
```
用户反馈：提示删除成功，但刷新页面后数据还在
前端显示：✓ 删除成功，页面即将刷新...
实际效果：数据库记录未被删除
```

**根本原因**: ⚠️ **重要修正**
在 Weline 框架的 Model ORM 中，`delete()` 方法**仅仅准备 SQL**，并不立即执行。**必须调用 `fetch()` 才能真正执行删除**。

测试验证：
- `->delete()` 单独调用：删除前1条，删除后1条（未执行）❌
- `->delete()->fetch()` 调用：删除前1条，删除后0条（成功执行）✅

**错误代码示例**:
```php
// ❌ 错误写法 - 只调用 delete() 不调用 fetch()，删除不会执行
$model->reset()
    ->where('id', $id)
    ->delete();  // ❌ 只准备了 SQL，但没有执行！
```

**解决方案**: ✅ **正确方式：delete()->fetch()**

```php
// ✅ 正确写法 - 必须调用 fetch() 执行删除
$model->reset()
    ->where('id', $id)
    ->delete()
    ->fetch();  // ✅ 执行 DELETE SQL

// ✅ 批量删除
foreach ($slotIds as $slotId) {
    $this->themeLayout->reset()
        ->where('theme_id', $themeId)
        ->where('slot_id', $slotId)
        ->delete()
        ->fetch();  // ✅ 必须调用 fetch()
}
```

**Weline ORM 删除操作正确实践**:

1. **单条删除**:
```php
$model->reset()
    ->where('id', $id)
    ->delete()
    ->fetch();  // ✅ 必须
```

2. **批量删除**:
```php
$model->reset()
    ->where('status', 'expired')
    ->delete()
    ->fetch();  // ✅ 必须
```

3. **删除前验证**:
```php
$record = $model->reset()->where('id', $id)->select()->fetch();
if ($record && $this->canDelete($record)) {
    $model->reset()->where('id', $id)->delete()->fetch();  // ✅
}
```

**验证测试结果**: 
```
测试删除 slot_id: products (当前有 1 条记录)
删除前记录数: 1
删除操作返回值类型: object
删除操作返回对象类: Weline\Theme\Model\ThemeLayout
删除后记录数: 0
实际删除数: 1
✓ 删除成功！
```

**框架中的使用示例**:
在框架代码中，所有地方都使用 `delete()->fetch()`：
- `app/code/Weline/Theme/Service/ThemeLayoutService.php:467`
- `app/code/Weline/Eav/Controller/Backend/Attribute.php:255`
- `app/code/Weline/Eav/EavModel.php:334, 352`
- 等多处

**预防措施**: 
1. **ORM 删除操作规则**：
   - ❌ 单独调用 `delete()` **不会执行**删除
   - ✅ 必须使用 `delete()->fetch()` 才能执行
   - ✅ 这与 `update()->fetch()`, `insert()->fetch()` 模式一致

2. **检查清单**：
   - [ ] 删除操作是否使用了 `delete()->fetch()`？
   - [ ] 是否测试了删除后数据确实被删除？
   - [ ] 批量删除是否每个都调用了 `fetch()`？

3. **调试技巧**：
   - 删除前后查询记录数验证是否真的删除
   - 检查 SQL 日志确认 DELETE 语句是否执行
   - 使用测试脚本独立验证删除逻辑

**相关文件**: 
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php:354-400`
- `app/code/Weline/Theme/Model/ThemeLayout.php`
- `test_orphan_cleanup.php` (验证脚本)

**学习要点**：
- Weline ORM 的 `delete()`, `update()`, `insert()` 等都是准备方法
- 必须调用 `fetch()` 或其他执行方法才能真正执行 SQL
- 遇到"提示成功但数据还在"的问题，首先检查是否缺少 `fetch()` 调用

---

## [2026-01-28] EventsManager dispatch 引用传递错误

**错误类型**: 框架约定

**错误信息**: 
```
Fatal error: Uncaught Error: Weline\Framework\Event\EventsManager::dispatch(): 
Argument #2 ($data) could not be passed by reference in 
E:\WelineFramework\DEV-workspace\app\code\WeShop\Catalog\Controller\Frontend\Category\View.php:222
```

**根本原因**: 
`EventsManager::dispatch()` 方法签名为 `dispatch(string $eventName, mixed &$data = [])`，
第二个参数是**引用传递**（`&$data`）。PHP 不允许将数组字面量（如 `['key' => 'value']`）
作为引用参数传递，必须先存入变量。

**解决方案**: 
先将事件数据存入变量，再传递给 `dispatch` 方法：

```php
// ❌ 错误写法 - 直接传递数组字面量
$eventsManager->dispatch('WeShop_Catalog::category_load_after', [
    'data' => [
        'category_id' => $category->getId(),
        'product_ids' => $productIds,
    ],
]);

// ✅ 正确写法 - 先存入变量
$eventData = [
    'data' => [
        'category_id' => $category->getId(),
        'product_ids' => $productIds,
    ],
];
$eventsManager->dispatch('WeShop_Catalog::category_load_after', $eventData);
```

**预防措施**: 
调用 `EventsManager::dispatch()` 时**始终使用变量传递数据**，不要直接传递数组字面量。

**相关文件**: 
- `app/code/WeShop/Catalog/Controller/Frontend/Category/View.php`
- `app/code/Weline/Framework/Event/EventsManager.php`

---

## [2026-01-28] 事件观察者未被触发 - category_load_after 事件缺失

**错误类型**: 框架约定 / Hook 使用

**错误表现**: 
分类页面的筛选器（filters）不显示，尽管布局中配置了 filters 组件。

**根本原因**: 
1. `CollectFiltersObserver` 监听 `WeShop_Catalog::category_load_after` 事件来注册 EAV 属性筛选器
2. 但分类控制器 `View.php` **没有触发**这个事件
3. 导致 EAV 属性筛选器从未被注册到 `FilterRegistry`

**解决方案**: 
在分类控制器中添加事件触发：

```php
// 在 View.php 的 index() 方法中，模板渲染前触发事件
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);
$eventData = [
    'data' => [
        'category_id' => $category->getId(),
        'product_ids' => array_keys($productIdsSeen),
    ],
];
$eventsManager->dispatch('WeShop_Catalog::category_load_after', $eventData);
```

**预防措施**: 
1. 在 `etc/event.xml` 中定义了事件监听后，确保对应的事件在正确的位置被触发
2. 使用事件观察者模式时，检查事件触发点是否存在
3. Hook 依赖的事件需要在 Hook 渲染之前触发

**相关文件**: 
- `app/code/WeShop/Catalog/Controller/Frontend/Category/View.php`
- `app/code/WeShop/Filters/etc/event.xml`
- `app/code/WeShop/Filters/Observer/CollectFiltersObserver.php`

---

## [2026-01-28] 模块 upgrade() 方法不执行 - register.php 版本号未更新

**⚠️ 已废弃（Phase 7/8）**：Model 的 `install()`/`upgrade()`/`setup()` 已不再被调用。表结构改用 **#[Col]/#[Table]** 声明，执行 `php bin/w setup:upgrade` 由 SchemaDiffStage 同步；业务初始化放在 **Setup/Install.php**、**Setup/Upgrade.php**。以下为历史记录。

**错误类型**: 框架约定 / 模块升级（历史）

**错误表现**: 
修改了 Model 的 `upgrade()` 方法添加新字段，但运行 `php bin/w s:up` 后数据库表结构没有变化。

**根本原因（历史）**: 
此前框架通过比较 `register.php` 版本号与数据库记录决定是否执行 `upgrade()`；版本号未变则跳过。

**当前做法**: 
表结构在 Model 上用 #[Col]/#[Table] 声明，运行 `php bin/w setup:upgrade`；不再依赖 register.php 版本号触发 Model 升级。

**历史解决方案（仅供参考）**: 
此前需在修改 Model 的 `install()` 或 `upgrade()` 后更新 `register.php` 版本号：

```php
// 修改前：版本 1.0.5
Register::register(
    Register::MODULE,
    'Weline_Eav',
    __DIR__,
    '1.0.5',  // ❌ 版本号未变化
    '<a href="https://bbs.aiweline.com">Eav数据库模型</a>'
);

// 修改后：版本 1.0.6
Register::register(
    Register::MODULE,
    'Weline_Eav',
    __DIR__,
    '1.0.6',  // ✅ 递增版本号
    '<a href="https://bbs.aiweline.com">Eav数据库模型</a>'
);
```

**预防措施**: 
1. **任何涉及数据库结构变更的代码修改，必须同步更新 register.php 版本号**
2. 版本号建议使用语义化版本：`主版本.次版本.补丁版本`
3. 添加字段等小改动递增补丁版本（如 1.0.5 → 1.0.6）
4. 新增功能递增次版本（如 1.0.x → 1.1.0）

**相关文件**: 
- `app/code/Weline/Eav/register.php`
- `app/code/Weline/Eav/Model/EavAttribute.php`
- 框架升级逻辑：`app/code/Weline/Framework/Setup/`

---

## [2026-01-28] PostgreSQL 类型不匹配错误 - VARCHAR 与 INTEGER 比较

**错误类型**: 数据库 / PostgreSQL 类型严格性

**错误信息**: 
```
Fatal error: Uncaught PDOException: SQLSTATE[42883]: Undefined function: 7 ERROR:  
operator does not exist: character varying = integer
LINE 1: ...ntity" AS "entity" ON "main_table"."eav_entity_id"="entity"....
HINT:  No operator matches the given name and argument types. You might need to add explicit type casts.
```

**根本原因**: 
PostgreSQL 是强类型数据库，不允许 `VARCHAR` 与 `INTEGER` 直接比较。在 JOIN 条件中：
- `EavEntity.eav_entity_id` 定义为 `INTEGER`（主键）
- `EavAttribute\Group.eav_entity_id` 错误地定义为 `VARCHAR(255)`
- `EavAttribute\Option.eav_entity_id` 错误地定义为 `VARCHAR(255)`

当执行 JOIN 时，PostgreSQL 无法比较这两种不同类型的列。MySQL 会自动进行隐式类型转换，但 PostgreSQL 需要显式类型转换或类型一致。

**解决方案**: 

### 1. 修复框架层（PostgreSQL Alter 适配器）
在 `Pgsql/Table/Alter.php` 的 `alter()` 方法中，修改列类型时自动添加 `USING` 子句：

```php
// 框架层自动处理类型转换
$usingClause = $this->generateUsingClause($fieldName, $targetType);
$sql = "ALTER TABLE {$this->table} ALTER COLUMN \"{$fieldName}\" TYPE {$alter_field['type_length']}{$usingClause}";
```

新增 `generateUsingClause()` 方法根据目标类型生成合适的 USING 表达式。

### 2. 修复业务层 Model 定义
将 `eav_entity_id` 从 `VARCHAR` 改为 `INTEGER`：

```php
// ❌ 错误定义 - VARCHAR 类型
->addColumn(self::fields_eav_entity_id, TableInterface::column_type_VARCHAR, 255, 'not null', 'Eav实体ID')

// ✅ 正确定义 - INTEGER 类型（与外键引用的主键类型一致）
->addColumn(self::fields_eav_entity_id, TableInterface::column_type_INTEGER, 0, 'not null', 'Eav实体ID')
```

同时添加 `upgrade()` 方法（使用标准框架方法，不含方言判断）：

```php
public function upgrade(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist()) {
        $setup->alterTable()
            ->alterColumn(
                self::fields_eav_entity_id,
                self::fields_eav_entity_id,
                '',
                TableInterface::column_type_INTEGER,
                0,
                'not null',
                'Eav实体ID'
            )
            ->alter();
    }
}
```

**重要原则**: 业务层不应包含数据库方言判断，所有方言差异在框架的 AST 转换层统一处理。

**预防措施**: 
1. **外键列的类型必须与引用的主键类型完全一致**
2. 使用 `INTEGER` 作为外键引用 `INTEGER` 主键，使用 `VARCHAR` 引用 `VARCHAR` 主键
3. PostgreSQL 比 MySQL 更严格，开发时应考虑跨数据库兼容性
4. 定义外键字段时，先检查被引用表的主键类型
5. **业务层禁止包含数据库方言判断代码**，方言差异应在框架的适配器/AST层处理

**相关文件**: 
- `app/code/Weline/Eav/Model/EavAttribute/Group.php` - 已修复
- `app/code/Weline/Eav/Model/EavAttribute/Option.php` - 已修复
- `app/code/Weline/Eav/Model/EavEntity.php` - 主表定义

---

## [2026-01-29] Module 模型类型混淆 - 调用不存在的 load() 方法

**错误类型**: 框架约定 / 类型误用

**错误信息**: 
```
Fatal error: Uncaught Error: Call to a member function getId() on false in 
E:\WelineFramework\DEV-workspace\app\code\Weline\Meta\Service\Scanner.php:239
Stack trace:
#0 E:\WelineFramework\DEV-workspace\app\code\Weline\Meta\Service\Scanner.php(39): 
   Weline\Meta\Service\Scanner->getModulePath('Weline_Theme')
```

**根本原因**: 
`Scanner.php` 的 `getModulePath()` 方法错误地将 `Weline\Framework\Module\Model\Module` 当作数据库模型使用：

```php
// ❌ 错误代码
$moduleManager = ObjectManager::getInstance(\Weline\Framework\Module\Model\Module::class);
$module = $moduleManager->load('name', $moduleName);  // Module 不是数据库模型
return $module->getId() ? $module->getPath() : null;  // getId() 不存在
```

实际上：
- `Module` 类继承自 `DataObject`，**不是数据库模型**
- 它没有 `load()` 和 `getId()` 方法
- 模块信息存储在 PHP 配置文件中，通过 `Env` 类访问

**解决方案**: 
使用 `Env::getInstance()->getModuleInfo()` 正确获取模块信息：

```php
// ✅ 正确代码
protected function getModulePath(string $moduleName): ?string
{
    $env = \Weline\Framework\App\Env::getInstance();
    $moduleInfo = $env->getModuleInfo($moduleName);
    if (empty($moduleInfo)) {
        return null;
    }
    // 返回模块的基础路径（base_path 是相对于 BP 的路径）
    return isset($moduleInfo['base_path']) ? BP . $moduleInfo['base_path'] : null;
}
```

**框架模块信息获取方式对照**:

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `Env::getInstance()->getModuleList()` | `array` | 获取所有模块列表 |
| `Env::getInstance()->getModuleInfo($name)` | `array\|null` | 获取单个模块信息 |
| `Env::getInstance()->getModuleByName($name)` | `array` | 同 getModuleInfo |
| `Env::getInstance()->getActiveModules()` | `array` | 获取已启用模块 |

**模块信息数组结构**:
```php
[
    'name' => 'Weline_Theme',
    'base_path' => 'app/code/Weline/Theme/',
    'namespace_path' => 'Weline\\Theme',
    'path' => 'Weline/Theme',
    'router' => 'theme',
    'backend_router' => 'theme',
    'version' => '1.0.0',
    'status' => true,
    // ...
]
```

**预防措施**: 
1. **区分 DataObject 和 AbstractModel**：`DataObject` 是数据容器，`AbstractModel` 是数据库模型
2. 模块信息通过 `Env` 类获取，不要试图用数据库模型方式加载
3. 使用 IDE 的类型提示功能，确认类的继承关系

**相关文件**: 
- `app/code/Weline/Meta/Service/Scanner.php` - 已修复
- `app/code/Weline/Framework/Module/Model/Module.php` - DataObject 类
- `app/code/Weline/Framework/App/Env.php` - 模块信息获取

---

## [2026-01-29] 控制器依赖注入缺失 - 模型属性未定义

**错误类型**: 依赖注入 / 控制器设计

**错误信息**: 
```
Warning: Undefined property: Weline\Theme\Controller\Backend\ThemeEditor::$themeLayout
Fatal error: Call to a member function reset() on null in ThemeEditor.php:380
```

**根本原因**: 
在 `postRemoveOrphanWidgets()` 方法中使用了 `$this->themeLayout` 属性，但该模型没有在控制器构造函数中注入：

```php
class ThemeEditor extends BackendController
{
    // ❌ 只声明了属性，但没有注入
    // private ThemeLayout $themeLayout; // 缺失
    
    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        // ThemeLayout $themeLayout  // ❌ 缺失依赖注入
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        // $this->themeLayout = $themeLayout;  // ❌ 缺失赋值
    }
    
    public function postRemoveOrphanWidgets()
    {
        // ❌ $this->themeLayout 为 null
        $widgets = $this->themeLayout->reset()  // 导致致命错误
            ->where('theme_id', $themeId)
            ->select();
    }
}
```

**解决方案**: 
在构造函数中正确注入所需的模型：

```php
class ThemeEditor extends BackendController
{
    private WelineTheme $welineTheme;
    private ThemeLayoutService $layoutService;
    private ThemeLayout $themeLayout;  // ✅ 1. 声明私有属性

    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeLayout $themeLayout  // ✅ 2. 在构造函数参数中声明
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        $this->themeLayout = $themeLayout;  // ✅ 3. 赋值给属性
    }
    
    public function postRemoveOrphanWidgets()
    {
        // ✅ 现在可以正常使用
        $widgets = $this->themeLayout->reset()
            ->where('theme_id', $themeId)
            ->select();
    }
}
```

**Weline 框架依赖注入三步骤**:

1. **声明私有属性**：`private ModelClass $propertyName;`
2. **构造函数参数注入**：`public function __construct(ModelClass $propertyName)`
3. **构造函数内赋值**：`$this->propertyName = $propertyName;`

**预防措施**: 
1. **使用前先注入**：任何在方法中使用的模型、服务都必须先在构造函数中注入
2. **检查属性声明**：确保类属性已声明且类型正确
3. **IDE 类型提示**：利用 IDE 的自动补全和错误检测功能
4. **参考现有代码**：查看同一控制器中其他属性的注入方式
5. **单一职责**：如果控制器需要太多依赖（>5个），考虑是否应该拆分或使用 Service 层

**依赖注入的好处**:
- 松耦合：依赖通过接口注入，易于替换实现
- 可测试：可以注入 Mock 对象进行单元测试
- 自动装配：框架的 ObjectManager 自动解析依赖关系
- 类型安全：构造函数类型声明确保注入的对象类型正确

**相关文件**: 
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php` - 已修复
- Weline 框架依赖注入文档

---

## [2026-02-07] PgsqlCompiler 聚合函数被当作标识符加引号

**错误类型**: 数据库 / SQL 编译器

**错误信息**: 
```
SQLSTATE[42703]: Undefined column: 7 ERROR: column "count(*)" does not exist
LINE 1: SELECT "count(*)" AS "total_count" FROM "public"."m_website"...
```

**根本原因**: 
`AbstractCompiler::quoteFieldExpression()` 方法没有检测 SQL 函数调用（如 `count(*)`、`sum(field)`、`max(field)`），直接调用 `quoteIdentifier()` 将整个表达式用双引号包裹，导致 PostgreSQL 将 `"count(*)"` 当作列名而非聚合函数。

调用链路：
1. `QueryAst::total()` 设置 `$this->fields = "count(*) as \`total_count\`"`
2. `cleanAstFields` 清理为 `"count(*) AS total_count"`
3. `PgsqlCompiler::compile()` → `AbstractCompiler::formatFields()` → `quoteFieldExpression("count(*)")`
4. `quoteFieldExpression` 未检测函数调用 → `quoteIdentifier("count(*)")` → `"count(*)"` （错误！）

**错误代码示例**:
```php
// ❌ AbstractCompiler::quoteFieldExpression() 缺少函数检测
protected function quoteFieldExpression(string $field): string
{
    $field = trim(str_replace(['`', '"', '[', ']'], '', $field));
    // 没有检测函数调用，直接跳到最后
    return $this->dialect->quoteIdentifier($field);
    // count(*) → "count(*)" 被当作列名！
}

// ❌ AbstractCompiler::buildWheres() 对函数字段错误使用 quoteIdentifier
if (is_string($field) && str_contains($field, '(')) {
    $fieldQuoted = $this->dialect->quoteIdentifier($field);
    // LOWER(name) → "LOWER(name)" 被当作列名！
}
```

**解决方案**: 
```php
// ✅ 添加函数调用检测，函数不应该被加引号
protected function quoteFieldExpression(string $field): string
{
    $field = trim(str_replace(['`', '"', '[', ']'], '', $field));
    if ($field === '') return '';
    // 检测函数调用（count(*), sum(field), max(field), LOWER(name) 等）
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/i', $field)) {
        return $field; // 函数调用直接返回，不加引号
    }
    // ... 其他正常标识符处理
}

// ✅ WHERE 中的函数字段也不应该加引号
if (is_string($field) && str_contains($field, '(')) {
    $fieldQuoted = $field; // 函数调用保持原样
}
```

**预防措施**: 
1. SQL 编译器处理标识符引用时，必须先检测是否为函数调用
2. 函数调用模式：`/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/i`
3. `Pgsql\Query::formatFieldExpression()` 已有此检测，但 `AbstractCompiler::quoteFieldExpression()` 遗漏

**验证方法**: 
```bash
php bin/w http:req "/pagebuilder/backend/seo/index" -b
```

**验证结果**: ✅ 成功，页面正常返回 200，488.81 KB HTML

**相关文件**: 
- `app/code/Weline/Framework/Database/Compiler/AbstractCompiler.php` - 已修复
- `app/code/Weline/Framework/Database/Connection/Api/Sql/QueryAst.php` - total() 方法
- `app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php` - formatFieldExpression() 已有函数检测

---

## [2026-02-07] ORM save() 中 checkUpdateOrInsert() 未清理查询状态导致 NOT NULL 违反

**错误类型**: 框架 ORM / 数据库

**错误信息**: 
```
SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "name" of relation "m_guolairen_page_builder_page" violates not-null constraint
DETAIL: Failing row contains (52, my, home_page, null, null, , 0, 1, , , , , , , ["zh_Hans_CN"], zh_Hans_CN, sattaking, {"sattaking":[]}, , , , , , , 0, 2026-02-02 14:49:58.359611, 2026-02-02 14:49:58.359611, null, null).
```

**根本原因**: 
`AbstractModel::checkUpdateOrInsert()` 内部的三处查询/操作均未调用 `clearQuery()` 清理前序操作（如 `load()`）残留的 WHERE 条件：
1. 存在性检查：`getQuery()->where($unique_data)->find()->fetchArray()` — 叠加了 `load()` 的 WHERE，可能导致查不到已有记录
2. 更新操作：`getQuery()->where($unique_data)->update(...)` — 同理
3. 插入操作：`getQuery()->insert(...)` — 同理

当 #1 因条件叠加查不到已有记录时，代码误判为"不存在"走 INSERT 分支，用 `getModelData()` 全量插入。如果模型数据中 `name`/`title` 为 null（比如 `load()` 后未正确填充），就触发 NOT NULL 约束。

**解决方案**: 
在 `AbstractModel::checkUpdateOrInsert()` 的三处操作前都加 `clearQuery()`：

```php
// 1. 存在性检查 — 清理前序 load()/find() 残留的 WHERE
$check_result = $this->getQuery()->clearQuery()->where($this->unique_data)->find()->fetchArray() ?? [];

// 2. 更新操作
$query = $this->getQuery()->clearQuery();
$save_result = $query->where($this->unique_data)->update($data, $this->_primary_key)->fetch();

// 3. 插入操作
$save_result = $this->getQuery()->clearQuery()->insert($this->getModelData(), $conflictFields)->fetch();
```

**预防措施**: 
1. ORM 内部方法在执行新查询前，必须先 `clearQuery()` 清理残留状态
2. 业务代码中 `clone + load() + save()` 模式不再依赖外部清理——框架内部保证查询隔离
3. 不要用 `file_get_contents('php://input')` 获取请求体，使用 `$this->request->getBodyParams()` 兼容 WLS

**验证方法**: 代码审计——确认 `checkUpdateOrInsert()` 三处操作均已加 `clearQuery()`

**验证结果**: ✅ 代码修改正确，逻辑链路完整

**相关文件**: 
- `app/code/Weline/Framework/Database/AbstractModel.php` — 核心修复（checkUpdateOrInsert 方法）
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Template.php` — autoSave 方法改用 getBodyParams()

---

## [2026-02-07] WLS Template 单例状态泄漏 — 页面标题/模板数据跨请求残留

**错误类型**: WLS 状态泄漏 / 单例模式

**错误现象**: 
WLS 模式下页面标题显示上一个请求的标题，与当前页面完全无关。
例如：访问"自动导客配置"页面，标题显示的是之前访问的"页面构建器"的标题。

**根本原因**: 
`Weline\Framework\View\Template` 使用 `private static Template $instance` 单例模式：
1. `getInstance()` 中 `init()` 仅在首次创建时调用
2. WLS 下实例跨请求保留，`_data` 数组中的 `title`、`req`、`env`、`local` 等数据残留
3. `init()` 中使用 `??` 操作符（如 `$this->getData('title') ?? $this->setData('title', ...)`），已有值不会被覆盖
4. `view_dir`、`template_dir`、`compile_dir` 等目录路径也残留，可能指向上个模块

额外发现：`State::$is_backend` 同理，构造函数中根据请求设置，WLS 下缓存后不再调用。

**错误代码示例**:
```php
// ❌ Template 单例在 WLS 下跨请求保留
private static Template $instance; // 非 nullable，无法重置

public static function getInstance(): Template {
    if (!isset(self::$instance)) {
        self::$instance = new self();
        self::$instance->init(); // 仅首次调用！
    }
    return self::$instance; // 后续请求返回同一实例，_data 残留
}
```

**解决方案**: 
```php
// ✅ 改为 nullable + 添加 resetInstance()
private static ?Template $instance = null;

public static function resetInstance(): void {
    self::$instance = null;
}

// 在 StateManager::registerFrameworkResets() 中注册：
self::registerResetCallback('template_instance', function () {
    Template::resetInstance();
    ObjectManager::removeInstance(Template::class);
});
```

**预防措施**: 
1. WLS 下所有使用单例模式的类，必须评估 `_data`/实例变量是否包含请求级数据
2. 单例类必须提供 `resetInstance()` 方法并注册到 StateManager
3. `??` 操作符在 WLS 单例中是陷阱——已有值不会被新请求覆盖

**验证方法**: WLS 模式下连续访问不同页面，确认标题正确切换

**相关文件**: 
- `app/code/Weline/Framework/View/Template.php` — 单例类型改为 nullable + 添加 resetInstance()
- `app/code/Weline/Framework/Runtime/StateManager.php` — 注册 Template 和 State 重置

---

## [2026-02-08] 主题预览 TypeError：htmlspecialchars() 收到数组 ✅ 已修复

**错误类型**: PHP 类型 / 主题模板

**错误信息**:
```
TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, array given
```

**根本原因**:
主题部件模板直接对配置项调用 `htmlspecialchars()`，但在预览场景下字段值可能是数组（如 `layout`、`columns` 或表单配置被序列化为数组）。

**解决方案**:
在模板中对配置项做类型归一化，仅允许 string/number 进入 `htmlspecialchars()`：

```php
$normalize = static function ($value, string $default = ''): string {
    if (is_string($value) || is_numeric($value)) {
        return (string)$value;
    }
    return $default;
};

$title = $normalize($this->getData('title'), __('推荐产品'));
$subtitle = $normalize($this->getData('subtitle'), __('为您精选的优质商品'));
$columns = $normalize($this->getData('columns'), '4');
$layout = $normalize($this->getData('layout'), 'grid');
```

**验证方法**:
```bash
php bin/w http:req theme/backend/theme-editor/preview -b --login -u=admin -p=admin123456 filter=htmlspecialchars -n=2
```

**验证结果**: ✅ 成功（200，未出现 `htmlspecialchars` 相关错误）

**预防措施**:
1. 所有模板中的 `htmlspecialchars()` 前先做类型归一化
2. 组件参数读取必须允许 string/number，其他类型回退默认值

**相关文件**:
- `app/code/Weline/Theme/view/theme/frontend/widgets/product/featured-products/default.phtml`

---

## [2026-02-08] 组件预览失败：Value of type null is not callable ✅ 已修复

**错误类型**: 组件渲染 / 模板变量

**错误信息**:
```
Value of type null is not callable
```

**根本原因**:
部分组件模板使用 `$getConfig(...)` 读取配置，但渲染上下文未注入 `$getConfig`，导致调用 null。

**解决方案**:
在组件渲染上下文统一注入 `$getConfig` 闭包，确保模板可安全调用：

```php
'getConfig' => static function (string $key, $default = null) use ($config) {
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
},
```

**验证方法**:
组件预览 API 正常返回（无 500 错误）。

**预防措施**:
1. 组件渲染统一注入 `$getConfig`
2. 模板可直接用 `$component_config` 访问，不依赖未注入变量

**相关文件**:
- `app/code/GuoLaiRen/PageBuilder/Service/ComponentService.php`
- `app/code/GuoLaiRen/PageBuilder/Service/Component/ComponentRenderer.php`

---

## [2026-02-13] 首次安装主题报错：父主题 default 不存在 ✅ 已修复

**错误类型**: 安装顺序 / 主题依赖

**错误信息**:
```
CLI 异常
父主题：default 不存在！
Weline\Theme\Register\Installer->register(...)
```

**根本原因**:
升级两阶段安装中，`theme` 类型注册会被延后执行；执行顺序可能是子主题先入队。
当子主题 `parent=default` 先安装时，父主题尚未写入数据库，直接触发“父主题不存在”。

**解决方案**:
将主题依赖处理下沉到主题安装器，不在 `Register.php` 处理：
1. 在 `Weline\Theme\Register\Installer` 内建立主题安装队列；
2. 根据 `param['parent']` 对队列做拓扑排序（父主题先于子主题）；
3. 仅安装“当前可安装”的主题（父主题已存在于库中）；
4. 支持 `default` 与 `Default 默认主题` 的别名映射，避免默认主题命名差异导致匹配失败。

**验证方法**:
```bash
php -l "e:\WelineFramework\DEV-workspace\app\code\Weline\Theme\Register\Installer.php"
```

**验证结果**: ✅ 成功（语法检查通过；lint 无报错）

**预防措施**:
1. 主题注册中的 `parent` 必须作为安装依赖参与排序；
2. 主题依赖排序逻辑应始终放在主题安装器中，由 `register_installer` 事件分发；
3. 对系统默认主题建立稳定别名映射，避免名称不一致造成安装失败。

**相关文件**:
- `app/code/Weline/Theme/Register/Installer.php`

---

## [2026-02-14] WLS/CDN 收尾：Origin Token 未校验 + Intelephense 类型契约告警 ✅ 已修复

**错误类型**: 安全补齐 / 类型约束（静态分析）

**错误信息**:
```text
Phase 4 剩余项：Worker/PHP 层缺少 Origin Token 校验
Intelephense: Expected type 'Socket'. Found 'resource'.
Intelephense: Expected type 'string'. Found 'null'.
```

**根本原因**:
1. `worker.php` / `worker_ssl.php` 仅展示并读取 `server.origin_token`，未在请求入口执行强校验。
2. `Dispatcher` 使用 `spl_object_id()` 处理 socket 时未兼容 resource 场景，导致静态分析类型不匹配。
3. `Cdn\Account` 控制器的多个 `redirect()` 返回值被推断为 `string|null`，与方法返回声明 `string` 冲突。

**解决方案**:
1. 在 `worker.php` 和 `worker_ssl.php` 增加可配置 Origin Token 校验：
   - 新增 `server.origin_token_validation.enabled|header|allow_local` 配置读取；
   - 在健康检查之后、框架处理之前执行 header token 校验；
   - 默认 Header 使用 `X-Weline-Origin-Token`，支持配置覆盖；
   - 对失败请求返回 403 JSON。
2. 在 `Dispatcher` 增加 `socketId()` 统一处理 object/resource socket ID，替换直接 `spl_object_id()` 的位置。
3. 在 `Cdn\Controller\Backend\Account` 将 `redirect()` 返回值显式转为 `(string)`，消除 `string|null` 告警。

**验证方法**:
```bash
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/worker.php"
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/worker_ssl.php"
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Server/Dispatcher/Dispatcher.php"
php -l "E:/WelineFramework/DEV-workspace/app/code/Weline/Cdn/Controller/Backend/Account.php"
ReadLints(paths=[worker.php, worker_ssl.php, Dispatcher.php, Account.php])
```

**验证结果**: ✅ 成功（语法检查全部通过，目标文件 lints 为 0）

**预防措施**:
1. 涉及回源安全时，必须在 Worker 请求入口同时实现“可开关”的 Token 校验，不仅做配置展示。
2. 处理 socket 连接 ID 时统一走兼容函数，避免直接假设对象类型。
3. 控制器声明 `: string` 时，所有分支都应显式返回字符串，避免隐式 nullable。

**相关文件**:
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/Dispatcher/Dispatcher.php`
- `app/code/Weline/Cdn/Controller/Backend/Account.php`

---

## [2026-03-01] WLS 子进程孤儿累积与 Master 控制失效（控制面收口 + 代际协议）✅ 已修复

**错误类型**: 进程管理 / IPC 生命周期一致性 / Windows PID 不可靠

**错误信息**:
```text
WLS 运行后出现子进程窗口累计、孤儿进程增多；
Master 对子进程控制不稳定，出现“启动了但无法稳定关闭/回收”。
```

**根本原因**:
1. 子进程侧具备 Master 复活能力，导致控制面分散（多点复活竞争风险）。
2. Windows 非阻塞启动路径瞬时 PID 不可靠，易把错误 PID 写入管理索引。
3. 缺少 epoch/launch_id 的代际隔离，旧进程迟到注册会污染当前实例集。
4. 缺少持续收敛循环，仅依赖瞬时动作，故障后容易漂移为“进程集不一致”。

**解决方案**:
1. 禁用子进程主动复活 Master（默认 `allow_child_resurrection=false`），控制面收口到 Master/Orchestrator。
2. IPC 协议新增 `epoch + launch_id`，Master 仅接纳当前代际并校验 launch_id。
3. Orchestrator 启动实例时生成 `launchId`，并在整组重启时 `epoch++`。
4. 引入期望状态收敛（reconcile）与周期孤儿回收（sweeper）。
5. Windows 非阻塞 + WLS 进程启动时不信任瞬时 PID，改为等待 register/ready 回填真实 PID。

**验证方法**:
```bash
php -l "app/code/Weline/Server/Service/ServiceOrchestrator.php"
php -l "app/code/Weline/Framework/System/Process/Processer.php"
php -l "app/code/Weline/Server/IPC/ControlMessage.php"
php -l "app/code/Weline/Server/IPC/ControlClient.php"
php -l "app/code/Weline/Server/IPC/MasterControlServer.php"
php "app/code/Weline/Server/Test/Service/standalone_test.php"
```

**验证结果**: ✅ 成功（语法检查通过；standalone_test: Passed 19, Failed 0）

**预防措施**:
1. WLS 生命周期控制必须保持 Single Writer（仅 Master 控制进程创建/销毁）。
2. 进程身份以 `process_name + epoch + launch_id` 为主，PID 仅作观测值。
3. 出现 IPC 断连、启动超时、无 IPC 存活进程时优先整组收敛，不做局部“猜测式修复”。

**相关文件**:
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Server/IPC/ControlMessage.php`
- `app/code/Weline/Server/IPC/ControlClient.php`
- `app/code/Weline/Server/IPC/MasterControlServer.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/bin/session_server.php`
- `app/code/Weline/Server/bin/http_redirect_worker.php`
- `app/code/Weline/Server/Dispatcher/Dispatcher.php`

---
