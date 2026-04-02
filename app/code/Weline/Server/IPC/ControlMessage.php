<?php
declare(strict_types=1);

/**
 * WLS IPC 控制通道 - NDJSON 消息协议
 *
 * 所有进程间控制消息均使用 NDJSON（Newline-Delimited JSON）格式：
 * 每条消息为一行 JSON + "\n"
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

use Weline\Framework\System\IPC\NdjsonProtocol;
use Weline\Framework\System\IPC\ProcessKind;

class ControlMessage
{
    // ========== 消息类型常量 ==========

    /** 子进程 → Master：注册身份（角色、PID、端口） */
    public const TYPE_REGISTER = 'register';

    /** Master → 子进程：注册确认，附带复活优先级 */
    public const TYPE_ACK = 'ack';

    /** 子进程 → Master：框架初始化 + 端口监听完成，可接收流量 */
    public const TYPE_READY = 'ready';

    /** Master → 子进程：通知优雅退出（主动终结） */
    public const TYPE_SHUTDOWN = 'shutdown';

    /** Master → Worker：通知代码重载（Worker 需优雅退出后重启） */
    public const TYPE_RELOAD = 'reload';

    /** Master → Worker：通知清缓存（原地执行，不重启） */
    public const TYPE_CACHE_CLEAR = 'cache_clear';

    /** Master → Worker：PageBuilder 单页缓存失效（进程内 handle + ObjectManager，轻量） */
    public const TYPE_PAGEBUILDER_PAGE_INVALIDATE = 'pagebuilder_page_invalidate';

    /** Master → Worker：下发驱动路由策略（file-only hijack + 服务端点） */
    public const TYPE_ROUTING_POLICY = 'routing_policy';

    /** Master → Dispatcher：将指定端口加入黑名单 */
    public const TYPE_DRAIN = 'drain';

    /** Master → Dispatcher：将指定端口从黑名单移除 */
    public const TYPE_UNDRAIN = 'undrain';

    /** Master → Dispatcher：动态添加 Worker 端口到负载均衡池 */
    public const TYPE_ADD_WORKER = 'add_worker';

    /** Master → Dispatcher：从负载均衡池移除端口 */
    public const TYPE_REMOVE_WORKER = 'remove_worker';

    /** Master → Dispatcher：一次性替换负载均衡端口列表（维护模式切换用） */
    public const TYPE_SET_WORKER_POOL = 'set_worker_pool';

    /** Master → Dispatcher：设置 HTTP 重定向端口（用于明文 HTTP 请求转发） */
    public const TYPE_SET_REDIRECT_PORT = 'set_redirect_port';

    /** Worker → Master：所有请求处理完毕，准备退出 */
    public const TYPE_DRAINING_COMPLETE = 'draining_complete';

    /** 子进程 → Master：进程即将退出（Master 可从等待列表移除） */
    public const TYPE_EXITED = 'exited';

    /** 子进程 → Master：退出原因（best-effort，Fatal 时可能缺失） */
    public const TYPE_EXIT_REASON = 'exit_reason';

    /** 子进程 → Master：日志行（开发模式统一汇聚到 Master 控制台） */
    public const TYPE_LOG = 'log';

    /** 子进程 → Master：上报运行状态 */
    public const TYPE_STATUS_REPORT = 'status_report';

    /** Worker → Master：已进入 HTTP 事件循环（Master 记录存活/重启统计） */
    public const TYPE_WORKER_LOOP_STARTED = 'worker_loop_started';
    /** 子进程 → Master：上报请求遥测事件 */
    public const TYPE_TELEMETRY = 'telemetry';

    /** CLI → Master：CLI 命令 */
    public const TYPE_COMMAND = 'command';

    /** Master → CLI：CLI 命令执行结果 */
    public const TYPE_COMMAND_RESULT = 'command_result';

    /** Master → Worker：确认收到 ready 消息（启动确认协议） */
    public const TYPE_ACK_READY = 'ack_ready';

    /** Master → CLI：滚动重启完成事件 */
    public const TYPE_RELOAD_COMPLETED = 'reload_completed';

    /** Master → CLI：滚动重启失败事件 */
    public const TYPE_RELOAD_FAILED = 'reload_failed';

    /** Master → CLI：滚动重启进度更新 */
    public const TYPE_RELOAD_PROGRESS = 'reload_progress';

    // ========== 批量协调消息类型（SOLID: 单一职责，扩展开放）============

    /**
     * Master → 子进程（批量）：批量广播消息
     * - targets: 目标列表（role/instanceIds/launchIds）
     * - message: 要执行的批量消息类型
     * - payload: 消息参数
     * - batch_id: 本次批量操作的唯一 ID（用于聚合响应）
     * - expires_at: 超时截止时间戳
     */
    public const TYPE_BATCH_BROADCAST = 'batch_broadcast';

    /**
     * 子进程 → Master（批量）：批量响应（聚合多个子进程的响应）
     * - batch_id: 对应的批量操作 ID
     * - results: 各子进程的响应结果
     */
    public const TYPE_BATCH_RESPONSE = 'batch_response';

    /**
     * Master → 子进程：批量操作超时，强制取消
     */
    public const TYPE_BATCH_CANCEL = 'batch_cancel';

    /**
     * 子进程 → Master：批量操作已接收确认（子进程告知已收到但不保证执行完成）
     */
    public const TYPE_BATCH_ACK = 'batch_ack';

    /**
     * Master → 子进程（批量）：批量停止（不等排水，直接 SIGTERM）
     * - 优化：批量发送 SIGTERM，不逐个等待
     */
    public const TYPE_BATCH_STOP = 'batch_stop';

    /**
     * Master → 子进程（批量）：批量重载（不等排水，强制重启）
     * - 优化：批量发送重载信号，不逐个等待
     */
    public const TYPE_BATCH_RELOAD = 'batch_reload';

    // ========== 角色常量 ==========

    public const ROLE_WORKER = 'worker';
    public const ROLE_DISPATCHER = 'dispatcher';
    public const ROLE_REDIRECT = 'redirect';
    public const ROLE_MAINTENANCE = 'maintenance';
    public const ROLE_SESSION_SERVER = 'session_server';
    public const ROLE_MEMORY_SERVER = 'memory_server';
    public const ROLE_GATEWAY = 'gateway';

    // ========== 重载类型 ==========

    public const RELOAD_TYPE_CODE = 'code';
    public const RELOAD_TYPE_CACHE = 'cache';
    /** 强制重载：批量杀死所有 Worker 后重新启动（不排水） */
    public const RELOAD_TYPE_FORCE = 'force';

    // ========== CLI 命令动作 ==========

    public const ACTION_STOP = 'stop';
    public const ACTION_RELOAD = 'reload';
    /** 重载并等待完成：Master 滚动重启完成后才返回结果 */
    public const ACTION_RELOAD_WAIT = 'reload_wait';
    public const ACTION_CACHE_CLEAR = 'cache_clear';
    /** CLI → Master：广播 PageBuilder 单页失效到各 Worker */
    public const ACTION_PAGEBUILDER_PAGE_INVALIDATE = 'pagebuilder_page_invalidate';
    public const ACTION_STATUS = 'status';
    /** 启用维护模式：启动维护 Worker，准备滚动重启 */
    public const ACTION_MAINTENANCE_ENABLE = 'maintenance_enable';
    /** 禁用维护模式：停止维护 Worker，恢复正常运行 */
    public const ACTION_MAINTENANCE_DISABLE = 'maintenance_disable';
    /** 滚动重启：逐个重启 Worker，期间由维护 Worker 接管流量 */
    public const ACTION_ROLLING_RESTART = 'rolling_restart';
    /** 解封 IP / 清空封禁列表（Master 转发给 Dispatcher） */
    public const ACTION_SECURITY_UNBLOCK = 'security_unblock';
    /** 获取流量遥测快照 */
    public const ACTION_TELEMETRY_QUERY = 'telemetry_query';
    /** 热重载 SSL 证书映射（不重启进程） */
    public const ACTION_SSL_CERT_RELOAD = 'ssl_cert_reload';

    /** 查询 Fiber 池统计（各 Worker 挂起数、配置等） */
    public const ACTION_FIBER_STATS = 'fiber_stats';

    /** 设置 Fiber 池配置（idle_ttl_sec / max_active），下发到各 Worker */
    public const ACTION_FIBER_SET_CONFIG = 'fiber_set_config';

    /** 立即释放各 Worker 上闲置的 Fiber */
    public const ACTION_FIBER_RELEASE_IDLE = 'fiber_release_idle';

    /** CLI → Master：手动扩缩容 Worker */
    public const ACTION_SCALE_WORKERS = 'scale_workers';

    /** CLI → Master：查询扩缩容状态 */
    public const ACTION_SCALING_STATUS = 'scaling_status';

    /** CLI → Master：应用反向代理配置 */
    public const ACTION_PROXY_APPLY = 'proxy_apply';

    /** Master → Worker：热重载 SSL 证书映射（不重启进程） */
    public const TYPE_SSL_CERT_RELOAD = 'ssl_cert_reload';

    /** Master → Dispatcher：解封指定 IP 或清空全部封禁 */
    public const TYPE_SECURITY_UNBLOCK = 'security_unblock';

    /** Master → Worker：下发 Fiber 池配置（闲置超时、最大活跃数） */
    public const TYPE_FIBER_SET_CONFIG = 'fiber_set_config';

    /** Master → Worker：立即释放闲置 Fiber */
    public const TYPE_FIBER_RELEASE_IDLE = 'fiber_release_idle';

    /** Master → Worker：查询 Fiber 池统计（Worker 回复 TYPE_FIBER_POOL_STATS） */
    public const TYPE_FIBER_POOL_QUERY = 'fiber_pool_query';

    /** Worker → Master：Fiber 池统计上报 */
    public const TYPE_FIBER_POOL_STATS = 'fiber_pool_stats';

    // ========== Gateway 反向代理消息类型 ==========

    /** Master → Gateway：添加反向代理路由 */
    public const TYPE_PROXY_ADD_ROUTE = 'proxy_add_route';

    /** Master → Gateway：移除反向代理路由 */
    public const TYPE_PROXY_REMOVE_ROUTE = 'proxy_remove_route';

    /** Master → Gateway：重载所有反向代理路由 */
    public const TYPE_PROXY_RELOAD = 'proxy_reload';

    /** Worker → Master：长连接饱和上报（主动） */
    public const TYPE_WORKER_SATURATION = 'worker_saturation';

    /** Worker → Master：长连接饱和解除上报 */
    public const TYPE_WORKER_SATURATION_CLEARED = 'worker_saturation_cleared';

    /** Master → Worker：进程内维护页开关（与维护 Worker 池配合，靠 IPC ACK 确认） */
    public const TYPE_SET_MAINTENANCE_MODE = 'set_maintenance_mode';

    /** Worker → Master：已应用维护信号 */
    public const TYPE_MAINTENANCE_MODE_ACK = 'maintenance_mode_ack';

    // ========== Worker 扩缩容消息类型 ==========

    /** CLI/Master → Master：扩缩容命令（设置目标 Worker 数） */
    public const TYPE_SCALE_WORKERS = 'scale_workers';

    /** Master → CLI：扩缩容完成响应 */
    public const TYPE_WORKER_SCALED = 'worker_scaled';

    /** Worker → Master：负载指标上报（CPU、内存、请求队列、响应时间） */
    public const TYPE_LOAD_REPORT = 'load_report';

    /** Master → Worker：优雅关闭（等待请求处理完成后退出） */
    public const TYPE_GRACEFUL_SHUTDOWN = 'graceful_shutdown';

    // ========== 复活优先级 ==========

    /** 不参与复活 */
    public const RESURRECTION_NONE = 0;
    /** HTTP Redirect Worker：延迟 1 秒 */
    public const RESURRECTION_REDIRECT = 1;
    /** Dispatcher：延迟 3 秒 */
    public const RESURRECTION_DISPATCHER = 2;
    /** Worker #1：延迟 6 秒 */
    public const RESURRECTION_WORKER = 3;

    // ========== 编解码方法（委托给框架层 NdjsonProtocol）==========

    /**
     * 编码消息为 NDJSON 行
     *
     * @param array $data 消息数据（必须包含 'type' 键）
     * @return string 以 "\n" 结尾的 JSON 字符串
     */
    public static function encode(array $data): string
    {
        return NdjsonProtocol::encode($data);
    }

    /**
     * 解码一行 NDJSON 消息
     *
     * @param string $line 单行 JSON 字符串（可含尾部换行）
     * @return array|null 解码后的数组，失败返回 null
     */
    public static function decode(string $line): ?array
    {
        return NdjsonProtocol::decodeWithType($line);
    }

    /**
     * 从缓冲区提取所有完整消息（处理粘包/半包）
     *
     * 传入引用缓冲区，提取所有完整的 NDJSON 行，
     * 未完成的半包数据留在缓冲区中等待下次追加。
     *
     * @param string &$buffer 读取缓冲区（引用传递，会被修改）
     * @return array 解码后的消息数组
     */
    public static function extractMessages(string &$buffer): array
    {
        return NdjsonProtocol::extractMessages($buffer);
    }

    // ========== 进程归属类型常量（规范源：ProcessKind，此处作向后兼容别名）==========

    /** 框架内置进程（Worker、Dispatcher、Session Server 等） */
    public const PROCESS_KIND_FRAMEWORK = ProcessKind::FRAMEWORK;
    /** 第三方模块注册的自定义子进程 */
    public const PROCESS_KIND_MODULE    = ProcessKind::MODULE;

    // ========== 消息构建快捷方法 ==========

    /**
     * 构建 register 消息
     *
     * @param string $processKind 进程归属类型：'framework' | 'module'
     * @param string $moduleCode  模块代码（仅 module 类进程需要，格式如 'Weline_Payment'）
     */
    public static function register(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = self::PROCESS_KIND_FRAMEWORK,
        string $moduleCode = '',
        string $instanceCode = ''
    ): string
    {
        $data = [
            'type'      => self::TYPE_REGISTER,
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
        ];
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        if ($launchId !== '') {
            $data['launch_id'] = $launchId;
        }
        if ($processKind !== self::PROCESS_KIND_FRAMEWORK) {
            $data['process_kind'] = $processKind;
        }
        if ($moduleCode !== '') {
            $data['module_code'] = $moduleCode;
        }
        if ($instanceCode !== '') {
            $data['instance_code'] = $instanceCode;
        }
        return self::encode($data);
    }

    /**
     * 构建 ack 消息
     */
    public static function ack(int $resurrectionPriority = self::RESURRECTION_NONE): string
    {
        return self::encode([
            'type'                  => self::TYPE_ACK,
            'resurrection_priority' => $resurrectionPriority,
        ]);
    }

    /**
     * 构建 ready 消息
     */
    public static function ready(
        string $role,
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = ''
    ): string
    {
        $data = [
            'type'      => self::TYPE_READY,
            'role'      => $role,
            'worker_id' => $workerId,
            'port'      => $port,
        ];
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        if ($launchId !== '') {
            $data['launch_id'] = $launchId;
        }
        return self::encode($data);
    }

    /**
     * Worker 进入主事件循环后上报（listen + IPC 就绪之后）
     */
    public static function workerLoopStarted(int $workerId, int $port, int $pid): string
    {
        return self::encode([
            'type'      => self::TYPE_WORKER_LOOP_STARTED,
            'worker_id' => $workerId,
            'port'      => $port,
            'pid'       => $pid,
        ]);
    }

    /**
     * 构建 shutdown 消息
     */
    public static function shutdown(string $reason = ''): string
    {
        return self::encode([
            'type'   => self::TYPE_SHUTDOWN,
            'reason' => $reason,
        ]);
    }

    /**
     * 构建 reload 消息
     */
    public static function reload(string $reloadType = self::RELOAD_TYPE_CODE, int $drainTimeoutSec = 0): string
    {
        $p = [
            'type'        => self::TYPE_RELOAD,
            'reload_type' => $reloadType,
        ];
        if ($drainTimeoutSec > 0) {
            $p['drain_timeout_sec'] = $drainTimeoutSec;
        }

        return self::encode($p);
    }

    /**
     * @param int[] $ports
     */
    public static function setWorkerPool(array $ports): string
    {
        return self::encode([
            'type'  => self::TYPE_SET_WORKER_POOL,
            'ports' => \array_values(\array_map('intval', $ports)),
        ]);
    }

    /**
     * 构建 cache_clear 消息
     */
    public static function cacheClear(): string
    {
        return self::encode([
            'type' => self::TYPE_CACHE_CLEAR,
        ]);
    }

    /**
     * PageBuilder：按站点 + handle 失效进程内路由 handle 缓存（由各 Worker 执行）
     */
    public static function pageBuilderPageInvalidate(int $websiteId, string $handle, bool $isHomePage): string
    {
        return self::encode([
            'type' => self::TYPE_PAGEBUILDER_PAGE_INVALIDATE,
            'website_id' => $websiteId,
            'handle' => $handle,
            'is_home_page' => $isHomePage,
        ]);
    }

    /**
     * 构建 ssl_cert_reload 消息（热重载 SSL 证书映射，不重启 Worker）
     *
     * @param string[]|null $domains 需要清除负缓存并重新加载的域名列表；
     *                               null 或空数组 = 全量重载（仅刷新 map 文件，不清除负缓存）；
     *                               非空 = 只为指定域清除负缓存并刷新内存证书映射。
     */
    public static function sslCertReload(?array $domains = null): string
    {
        $payload = ['type' => self::TYPE_SSL_CERT_RELOAD];
        if (!empty($domains)) {
            $payload['domains'] = \array_values(\array_unique($domains));
        }
        return self::encode($payload);
    }

    /**
     * 构建 routing_policy 消息
     *
     * @param array<string, mixed> $policy
     */
    public static function routingPolicy(array $policy): string
    {
        return self::encode([
            'type' => self::TYPE_ROUTING_POLICY,
            'data' => $policy,
        ]);
    }

    /**
     * @param bool $immediateAckOnEnable 无 Dispatcher 时置 true：立即 ACK（无法切池排水）
     */
    public static function setMaintenanceMode(bool $enabled, string $requestId, bool $immediateAckOnEnable = false): string
    {
        return self::encode([
            'type' => self::TYPE_SET_MAINTENANCE_MODE,
            'enabled' => $enabled,
            'request_id' => $requestId,
            'immediate_ack' => $immediateAckOnEnable,
        ]);
    }

    /**
     * 构建 drain 消息
     */
    public static function drain(array $ports): string
    {
        return self::encode([
            'type'  => self::TYPE_DRAIN,
            'ports' => $ports,
        ]);
    }

    /**
     * 构建 undrain 消息
     */
    public static function undrain(array $ports): string
    {
        return self::encode([
            'type'  => self::TYPE_UNDRAIN,
            'ports' => $ports,
        ]);
    }

    /**
     * 构建 add_worker 消息
     */
    public static function addWorker(array $ports): string
    {
        return self::encode([
            'type'  => self::TYPE_ADD_WORKER,
            'ports' => $ports,
        ]);
    }

    /**
     * 构建 remove_worker 消息
     */
    public static function removeWorker(array $ports): string
    {
        return self::encode([
            'type'  => self::TYPE_REMOVE_WORKER,
            'ports' => $ports,
        ]);
    }

    /**
     * 构建 set_redirect_port 消息（设置 HTTP 重定向端口）
     */
    public static function setRedirectPort(int $port): string
    {
        return self::encode([
            'type' => self::TYPE_SET_REDIRECT_PORT,
            'port' => $port,
        ]);
    }

    /**
     * 构建 draining_complete 消息
     */
    public static function drainingComplete(int $workerId, int $port): string
    {
        return self::encode([
            'type'      => self::TYPE_DRAINING_COMPLETE,
            'worker_id' => $workerId,
            'port'      => $port,
        ]);
    }

    /**
     * 构建 status_report 消息
     */
    public static function statusReport(int $connections, int $memory, int $requests): string
    {
        return self::encode([
            'type'        => self::TYPE_STATUS_REPORT,
            'connections' => $connections,
            'memory'      => $memory,
            'requests'    => $requests,
        ]);
    }

    /**
     * 构建 telemetry 消息（子进程 -> Master）
     */
    public static function telemetry(
        string $instance,
        string $host,
        int $status,
        int $latencyMs,
        int $bytesOut,
        int $ts = 0
    ): string {
        return self::encode([
            'type' => self::TYPE_TELEMETRY,
            'instance' => $instance,
            'host' => $host,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'bytes_out' => $bytesOut,
            'ts' => $ts > 0 ? $ts : \time(),
        ]);
    }

    /**
     * 构建 command 消息
     *
     * @param string $action 动作
     * @param string $reloadType 重载类型（仅 reload 时用）
     * @param array $payload 可选载荷（如 security_unblock 时传 ip / clear_all）
     */
    public static function command(string $action, string $reloadType = '', array $payload = []): string
    {
        $data = [
            'type'   => self::TYPE_COMMAND,
            'action' => $action,
        ];
        if ($reloadType !== '') {
            $data['reload_type'] = $reloadType;
        }
        foreach ($payload as $k => $v) {
            $data[$k] = $v;
        }
        return self::encode($data);
    }

    /**
     * 构建 security_unblock 消息（Master → Dispatcher）
     *
     * @param string|null $ip 解封指定 IP，为 null 且 clear_all 为 true 时清空全部
     * @param bool $clearAll 是否清空全部封禁
     */
    public static function securityUnblock(?string $ip = null, bool $clearAll = false): string
    {
        $data = ['type' => self::TYPE_SECURITY_UNBLOCK, 'clear_all' => $clearAll];
        if ($ip !== null && $ip !== '') {
            $data['ip'] = $ip;
        }
        return self::encode($data);
    }

    /**
     * 构建 command_result 消息
     */
    public static function commandResult(bool $success, array $data = [], string $message = ''): string
    {
        return self::encode([
            'type'    => self::TYPE_COMMAND_RESULT,
            'success' => $success,
            'data'    => $data,
            'message' => $message,
        ]);
    }

    /**
     * 构建 exit_reason 消息（退出前发送，Master 记录用于决策和排查）
     *
     * @param string $reason 退出原因
     * @param int $code 可选退出码
     */
    public static function exitReason(string $reason, int $code = 0): string
    {
        $data = ['type' => self::TYPE_EXIT_REASON, 'reason' => $reason];
        if ($code !== 0) {
            $data['code'] = $code;
        }
        return self::encode($data);
    }

    /**
     * 构建 exited 消息（子进程退出前发送）
     */
    public static function exited(string $role, int $pid, int $port = 0, int $workerId = 0): string
    {
        return self::encode([
            'type'      => self::TYPE_EXITED,
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
        ]);
    }

    /**
     * 构建 ack_ready 消息（Master → Worker：确认收到 ready）
     *
     * @param int $workerId Worker ID
     * @return string NDJSON 消息
     */
    public static function ackReady(int $workerId): string
    {
        return self::encode([
            'type'      => self::TYPE_ACK_READY,
            'worker_id' => $workerId,
        ]);
    }

    /**
     * 构建 reload_completed 消息（Master → CLI：滚动重启完成）
     *
     * @param float $elapsedMs 耗时（毫秒）
     * @param int $workerCount Worker 数量
     * @return string NDJSON 消息
     */
    public static function reloadCompleted(float $elapsedMs, int $workerCount): string
    {
        return self::encode([
            'type'         => self::TYPE_RELOAD_COMPLETED,
            'elapsed_ms'   => $elapsedMs,
            'worker_count' => $workerCount,
        ]);
    }

    /**
     * 构建 reload_failed 消息（Master → CLI：滚动重启失败）
     *
     * @param string $reason 失败原因
     * @param int $workerId 失败的 Worker ID（可选）
     * @return string NDJSON 消息
     */
    public static function reloadFailed(string $reason, int $workerId = 0): string
    {
        return self::encode([
            'type'      => self::TYPE_RELOAD_FAILED,
            'reason'    => $reason,
            'worker_id' => $workerId,
        ]);
    }

    /**
     * 构建 reload_progress 消息（Master → CLI：滚动重启进度）
     *
     * @param int $completed 已完成数量
     * @param int $total 总数量
     * @param int $currentWorkerId 当前正在处理的 Worker ID
     * @param string $stage 当前阶段：draining/starting/waiting_ready
     * @return string NDJSON 消息
     */
    public static function reloadProgress(int $completed, int $total, int $currentWorkerId, string $stage): string
    {
        return self::encode([
            'type'              => self::TYPE_RELOAD_PROGRESS,
            'completed'         => $completed,
            'total'             => $total,
            'current_worker_id' => $currentWorkerId,
            'stage'             => $stage,
        ]);
    }

    /**
     * 构建 log 消息（子进程 → Master：单行日志，开发模式汇聚到 Master 控制台）
     *
     * @param string $line 已格式化的日志行（含时间戳、进程标识、级别、内容）
     * @param string $level 级别
     * @param string $processTag 进程标识
     * @return string NDJSON 消息
     */
    public static function logLine(string $line, string $level, string $processTag): string
    {
        return self::encode([
            'type'        => self::TYPE_LOG,
            'line'        => $line,
            'level'       => $level,
            'process_tag' => $processTag,
        ]);
    }

    /**
     * 构建 fiber_set_config 消息（Master → Worker）
     *
     * @param int $idleTtlSec 挂起超过此秒数视为闲置并可释放，0=不自动释放
     * @param int $maxActive 最大活跃挂起 Fiber 数，0=不限制
     */
    public static function fiberSetConfig(int $idleTtlSec = 0, int $maxActive = 0): string
    {
        return self::encode([
            'type'          => self::TYPE_FIBER_SET_CONFIG,
            'idle_ttl_sec'  => $idleTtlSec,
            'max_active'    => $maxActive,
        ]);
    }

    /**
     * 构建 fiber_release_idle 消息（Master → Worker）
     */
    public static function fiberReleaseIdle(): string
    {
        return self::encode(['type' => self::TYPE_FIBER_RELEASE_IDLE]);
    }

    /**
     * 构建 fiber_pool_query 消息（Master → Worker），Worker 回复 TYPE_FIBER_POOL_STATS
     *
     * @param string $requestId 请求 ID，Worker 回传以便 Master 聚合
     */
    public static function fiberPoolQuery(string $requestId): string
    {
        return self::encode([
            'type'       => self::TYPE_FIBER_POOL_QUERY,
            'request_id' => $requestId,
        ]);
    }

    /**
     * 构建 fiber_pool_stats 消息（Worker → Master）
     *
     * @param string $requestId 对应 query 的 request_id
     * @param int $workerId Worker ID
     * @param int $suspendedCount 当前挂起 Fiber 数
     * @param int $idleTtlSec 当前配置的闲置超时（秒）
     * @param int $maxActive 当前配置的最大活跃数
     * @param int $releasedCount 本次释放数量（仅 release_idle 时可选）
     */
    public static function fiberPoolStats(
        string $requestId,
        int $workerId,
        int $suspendedCount,
        int $idleTtlSec = 0,
        int $maxActive = 0,
        int $releasedCount = 0
    ): string {
        return self::encode([
            'type'           => self::TYPE_FIBER_POOL_STATS,
            'request_id'     => $requestId,
            'worker_id'      => $workerId,
            'suspended'      => $suspendedCount,
            'idle_ttl_sec'   => $idleTtlSec,
            'max_active'     => $maxActive,
            'released_count' => $releasedCount,
        ]);
    }

    /**
     * 构建长连接饱和上报消息（Worker → Master/Dispatcher）
     *
     * 当长连接（ SSE / 长轮询）占用过多 Fiber 槽位时，Worker 主动上报饱和状态，
     * Dispatcher 据此暂缓向该 Worker 分配新请求，同时短请求仍可路由到其他 Worker。
     *
     * @param int $workerId Worker ID
     * @param int $port Worker 监听端口
     * @param int $longLivedCount 当前长连接数
     * @param int $longLivedMax 长连接上限
     * @param int $totalFiberCount 总 Fiber 数（含短请求）
     * @param int $maxActive Fiber 池上限（0=不限制）
     */
    public static function workerSaturation(
        int $workerId,
        int $port,
        int $longLivedCount,
        int $longLivedMax,
        int $totalFiberCount,
        int $maxActive = 0
    ): string {
        return self::encode([
            'type'              => self::TYPE_WORKER_SATURATION,
            'worker_id'         => $workerId,
            'port'              => $port,
            'long_lived_count'  => $longLivedCount,
            'long_lived_max'   => $longLivedMax,
            'total_fiber_count' => $totalFiberCount,
            'max_active'       => $maxActive,
        ]);
    }

    /**
     * 构建长连接饱和解除消息（Worker → Master/Dispatcher）
     */
    public static function workerSaturationCleared(
        int $workerId,
        int $port,
        int $longLivedCount,
        int $longLivedMax
    ): string {
        return self::encode([
            'type'             => self::TYPE_WORKER_SATURATION_CLEARED,
            'worker_id'        => $workerId,
            'port'             => $port,
            'long_lived_count' => $longLivedCount,
            'long_lived_max'   => $longLivedMax,
        ]);
    }

    // ========== 批量协调消息工厂方法（SOLID: 工厂方法模式）============

    /**
     * 构建批量广播消息（Master → 子进程）
     *
     * @param string $batchId 批量操作唯一 ID
     * @param string $messageType 要执行的消息类型（如 TYPE_RELOAD、TYPE_SHUTDOWN）
     * @param array $payload 消息参数
     * @param array $targets 目标描述：['roles' => ['worker', 'session_server'], 'instance_ids' => [1, 2]]
     * @param int $expiresAt 超时截止时间戳
     */
    public static function batchBroadcast(
        string $batchId,
        string $messageType,
        array $payload = [],
        array $targets = [],
        int $expiresAt = 0
    ): string {
        return self::encode([
            'type'       => self::TYPE_BATCH_BROADCAST,
            'batch_id'   => $batchId,
            'message'    => $messageType,
            'payload'    => $payload,
            'targets'    => $targets,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 构建批量响应消息（子进程 → Master）
     *
     * @param string $batchId 对应的批量操作 ID
     * @param array $results 各子进程的响应结果
     */
    public static function batchResponse(string $batchId, array $results = []): string
    {
        return self::encode([
            'type'    => self::TYPE_BATCH_RESPONSE,
            'batch_id' => $batchId,
            'results' => $results,
        ]);
    }

    /**
     * 构建批量操作 ACK 消息（子进程 → Master）
     *
     * @param string $batchId 对应的批量操作 ID
     */
    public static function batchAck(string $batchId): string
    {
        return self::encode([
            'type'    => self::TYPE_BATCH_ACK,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * 构建批量操作超时取消消息（Master → 子进程）
     *
     * @param string $batchId 批量操作 ID
     */
    public static function batchCancel(string $batchId): string
    {
        return self::encode([
            'type'    => self::TYPE_BATCH_CANCEL,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * 构建批量停止消息（Master → 子进程，不等排水直接 SIGTERM）
     *
     * @param string $batchId 批量操作 ID
     * @param array $targets 目标：['roles' => ['worker'], 'instance_ids' => [1, 2, 3]]
     * @param int $expiresAt 超时截止时间戳
     */
    public static function batchStop(string $batchId, array $targets = [], int $expiresAt = 0): string
    {
        return self::encode([
            'type'       => self::TYPE_BATCH_STOP,
            'batch_id'   => $batchId,
            'targets'    => $targets,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 构建批量重载消息（Master → 子进程，强制重载）
     *
     * @param string $batchId 批量操作 ID
     * @param string $reloadType 重载类型：RELOAD_TYPE_CODE | RELOAD_TYPE_FORCE
     * @param array $targets 目标
     * @param int $expiresAt 超时截止时间戳
     */
    public static function batchReload(
        string $batchId,
        string $reloadType = self::RELOAD_TYPE_CODE,
        array $targets = [],
        int $expiresAt = 0
    ): string {
        return self::encode([
            'type'        => self::TYPE_BATCH_RELOAD,
            'batch_id'    => $batchId,
            'reload_type' => $reloadType,
            'targets'     => $targets,
            'expires_at'  => $expiresAt,
        ]);
    }

    // ========== Worker 扩缩容消息工厂方法 ==========

    /**
     * 构建 scale_workers 消息（CLI/Master → Master）
     *
     * @param int $targetWorkers 目标 Worker 数量
     * @param array $options 可选参数：['auto' => bool, 'min' => int, 'max' => int]
     */
    public static function scaleWorkers(int $targetWorkers, array $options = []): string
    {
        return self::encode([
            'type'           => self::TYPE_SCALE_WORKERS,
            'target_workers' => $targetWorkers,
            'options'        => $options,
        ]);
    }

    /**
     * 构建 worker_scaled 消息（Master → CLI）
     *
     * @param bool $success 是否成功
     * @param int $currentWorkers 当前 Worker 数量
     * @param int $targetWorkers 目标 Worker 数量
     * @param array $addedPids 新增的 Worker PID 列表
     * @param array $removedPids 移除的 Worker PID 列表
     * @param string $message 消息
     */
    public static function workerScaled(
        bool $success,
        int $currentWorkers,
        int $targetWorkers,
        array $addedPids = [],
        array $removedPids = [],
        string $message = ''
    ): string {
        return self::encode([
            'type'            => self::TYPE_WORKER_SCALED,
            'success'         => $success,
            'current_workers' => $currentWorkers,
            'target_workers'  => $targetWorkers,
            'added_pids'      => $addedPids,
            'removed_pids'    => $removedPids,
            'message'         => $message,
        ]);
    }

    /**
     * 构建 load_report 消息（Worker → Master）
     *
     * @param int $workerId Worker ID
     * @param float $cpuUsage CPU 使用率（0-100）
     * @param int $memoryUsage 内存使用量（字节）
     * @param int $queueLength 请求队列长度
     * @param float $avgResponseTime 平均响应时间（毫秒）
     * @param int $activeConnections 活跃连接数
     */
    public static function loadReport(
        int $workerId,
        float $cpuUsage,
        int $memoryUsage,
        int $queueLength,
        float $avgResponseTime,
        int $activeConnections
    ): string {
        return self::encode([
            'type'                => self::TYPE_LOAD_REPORT,
            'worker_id'           => $workerId,
            'cpu_usage'           => $cpuUsage,
            'memory_usage'        => $memoryUsage,
            'queue_length'        => $queueLength,
            'avg_response_time'   => $avgResponseTime,
            'active_connections'  => $activeConnections,
            'timestamp'           => \microtime(true),
        ]);
    }

    /**
     * 构建 graceful_shutdown 消息（Master → Worker）
     *
     * @param int $timeoutSec 超时时间（秒），超时后强制 kill
     */
    public static function gracefulShutdown(int $timeoutSec = 30): string
    {
        return self::encode([
            'type'        => self::TYPE_GRACEFUL_SHUTDOWN,
            'timeout_sec' => $timeoutSec,
        ]);
    }

    // ========== Gateway 反向代理消息工厂方法 ==========

    /**
     * 构建 proxy_add_route 消息（Master → Gateway）
     *
     * @param string $domain 域名
     * @param string $backendHost 后端主机
     * @param int $backendPort 后端端口
     * @param bool $backendSsl 后端是否使用SSL
     * @param int $priority 优先级
     */
    public static function proxyAddRoute(
        string $domain,
        string $backendHost,
        int $backendPort,
        bool $backendSsl = true,
        int $priority = 0
    ): string {
        return self::encode([
            'type'         => self::TYPE_PROXY_ADD_ROUTE,
            'domain'       => $domain,
            'backend_host' => $backendHost,
            'backend_port' => $backendPort,
            'backend_ssl'  => $backendSsl,
            'priority'     => $priority,
        ]);
    }

    /**
     * 构建 proxy_remove_route 消息（Master → Gateway）
     *
     * @param string $domain 域名
     */
    public static function proxyRemoveRoute(string $domain): string
    {
        return self::encode([
            'type'   => self::TYPE_PROXY_REMOVE_ROUTE,
            'domain' => $domain,
        ]);
    }

    /**
     * 构建 proxy_reload 消息（Master → Gateway）
     *
     * @param array $routes 路由数组 [['domain' => ..., 'backend_host' => ..., 'backend_port' => ..., 'backend_ssl' => ..., 'priority' => ...], ...]
     */
    public static function proxyReload(array $routes): string
    {
        return self::encode([
            'type'   => self::TYPE_PROXY_RELOAD,
            'routes' => $routes,
        ]);
    }
}
