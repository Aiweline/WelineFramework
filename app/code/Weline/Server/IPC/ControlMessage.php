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
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Policy\DispatcherPolicyControl;

class ControlMessage
{
    /** READY→activate→receipt→final 的统一总预算。 */
    public const READY_CONFIRM_TIMEOUT_SEC = 3.0;

    /** 总预算内的幂等 READY/activate 重试间隔。 */
    public const READY_RETRY_INTERVAL_SEC = 0.5;

    /** Drain 仍在等待应用或传输层自然收敛。 */
    public const DRAIN_ACTION_WAIT = 'wait';

    /** Drain 已自然清空。 */
    public const DRAIN_ACTION_COMPLETE = 'complete';

    /** 软截止点仅剩空闲 transport，可安全关闭。 */
    public const DRAIN_ACTION_CLOSE_IDLE = 'close_idle';

    /** 硬截止点已到，必须强制收敛。 */
    public const DRAIN_ACTION_FORCE = 'force';

    public const DRAIN_OUTCOME_NATURAL = 'natural';
    public const DRAIN_OUTCOME_IDLE_CLEANUP = 'idle_cleanup';
    public const DRAIN_OUTCOME_FORCED = 'forced';

    /** RFC 6455 message budget shared by the production HTTP and TLS workers. */
    public const WEBSOCKET_DEFAULT_MAX_MESSAGE_BYTES = 1_048_576;

    /** Bound one event-loop turn so a single WebSocket cannot monopolize a worker. */
    public const WEBSOCKET_MAX_FRAMES_PER_TICK = 64;

    private const DRAIN_REPORT_COUNT_KEYS = [
        'connections',
        'active_requests',
        'long_lived_connections',
        'sse_connections',
        'websocket_connections',
        'http2_connections',
    ];

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

    /** Worker → Master：确认指定缓存代际已在本进程生效。 */
    public const TYPE_CACHE_CLEAR_ACK = 'cache_clear_ack';

    /** Master → Worker：精确推进 namespace generation，不执行全清。 */
    public const TYPE_CACHE_NAMESPACE_INVALIDATE_V1 = 'cache_namespace_invalidate_v1';

    /** Worker → Master：namespace generation 已应用或已覆盖。 */
    public const TYPE_CACHE_NAMESPACE_INVALIDATE_ACK_V1 = 'cache_namespace_invalidate_ack_v1';

    /** Master → Worker：下发驱动路由策略（file-only hijack + 服务端点） */
    public const TYPE_ROUTING_POLICY = 'routing_policy';

    /** Master → Worker/Dispatcher：校验并暂存不可变运行时策略包。 */
    public const TYPE_POLICY_PREPARE = 'policy_prepare';

    /** Worker/Dispatcher → Master：策略包已校验并暂存。 */
    public const TYPE_POLICY_PREPARED_ACK = 'policy_prepared_ack';

    /** Master → Worker/Dispatcher：原子激活已 PREPARE 的策略 digest。 */
    public const TYPE_POLICY_ACTIVATE = 'policy_activate';

    /** Worker/Dispatcher → Master：策略 digest 已激活。 */
    public const TYPE_POLICY_ACTIVATED_ACK = 'policy_activated_ack';

    /** Master → Worker/Dispatcher：全部关键参与者已激活，提交并恢复入口。 */
    public const TYPE_POLICY_COMMIT = 'policy_commit';

    /** Worker/Dispatcher → Master：策略已提交且入口已恢复。 */
    public const TYPE_POLICY_COMMITTED_ACK = 'policy_committed_ack';

    /** Master → Worker/Dispatcher：回滚到前一或指定策略 digest。 */
    public const TYPE_POLICY_ROLLBACK = 'policy_rollback';

    /** Worker/Dispatcher → Master：策略回滚结果。 */
    public const TYPE_POLICY_ROLLBACK_ACK = 'policy_rollback_ack';

    /** Worker/Dispatcher ↔ Master：实例级封禁正缓存增量。 */
    public const TYPE_POLICY_STATE_DELTA = 'policy_state_delta';

    /** Master → Workers: globally consistent Alt-Svc availability epoch. */
    public const TYPE_HTTP3_AVAILABILITY = 'http3_availability';

    /** Master → Dispatcher：将指定端口加入黑名单 */
    public const TYPE_DRAIN = 'drain';

    /** Master → Dispatcher：将指定端口从黑名单移除 */
    public const TYPE_UNDRAIN = 'undrain';

    /** Gateway fallback Worker → Master：精确 listener 转换回执。 */
    public const TYPE_GATEWAY_FALLBACK_LISTENER_ACK = 'gateway_fallback_listener_ack';

    public const GATEWAY_FALLBACK_LISTENER_PROTOCOL = 'wls-gateway-fallback-listener/1';
    public const GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN = 'DRAIN';
    public const GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN = 'UNDRAIN';
    public const GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE = 'ACTIVE';
    public const GATEWAY_FALLBACK_LISTENER_STATE_DRAINING = 'DRAINING';
    public const GATEWAY_FALLBACK_LISTENER_STATE_TERMINAL = 'TERMINAL';

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

    /** Worker → Master：批量上报普通请求的进程内聚合计数。 */
    public const TYPE_TELEMETRY_BATCH = 'telemetry_batch';

    /** Dispatcher → Master：上报后端池全不可用等需自愈异常 */
    public const TYPE_DISPATCHER_ALERT = 'dispatcher_alert';

    /** CLI → Master：CLI 命令 */
    public const TYPE_COMMAND = 'command';

    /** Master → CLI：CLI 命令执行结果 */
    public const TYPE_COMMAND_RESULT = 'command_result';

    /** Master → Worker：确认收到 ready 消息（启动确认协议） */
    public const TYPE_ACK_READY = 'ack_ready';
    /** Master → 子进程：控制会话租约分配 */
    public const TYPE_LEASE_ASSIGN = 'lease_assign';
    /** Master → 子进程：ready 状态确认 */
    public const TYPE_READY_ACK = 'ready_ack';
    /** Worker → Master：Linux HTTP/3 eBPF 路由已完成身份绑定激活。 */
    public const TYPE_HTTP3_ROUTE_ACTIVATED = 'http3_route_activated';
    /** Master → Dispatcher：Worker 池快照确认 */
    public const TYPE_POOL_SNAPSHOT_ACK = 'pool_snapshot_ack';
    /** Master → 子进程：命令已接收 */
    public const TYPE_COMMAND_ACCEPT = 'command_accept';
    /** Master → 子进程：命令已完成 */
    public const TYPE_COMMAND_DONE = 'command_done';
    /** 心跳消息 */
    public const TYPE_HEARTBEAT = 'heartbeat';
    /** Dispatcher → Master：Worker 入池检查回执（闭环确认） */
    public const TYPE_WORKER_POOL_ACK = 'worker_pool_ack';

    /** Master → Dispatcher：版本化全量路由表，是 Dispatcher 唯一路由权威输入。 */
    public const TYPE_SET_ROUTE_TABLE = 'set_route_table';

    /** Dispatcher → Master：路由表版本回执。 */
    public const TYPE_ROUTE_TABLE_ACK = 'route_table_ack';

    /**
     * Worker/Dispatcher → Master：身份/路由观察上报（B-i 阶段引入，仅落观测日志）。
     *
     * 用于后续 slot/lease/generation 校验：进程发现自身 slot/lease/generation 与
     * Master 推送的版本化路由表不一致时上报，Master 可在 B-ii/B-iii 阶段据此触发收敛。
     */
    public const TYPE_ROUTE_OBSERVATION = 'route_observation';

    /** Master → CLI：滚动重启完成事件 */
    public const TYPE_RELOAD_COMPLETED = 'reload_completed';

    /** Master → CLI：滚动重启失败事件 */
    public const TYPE_RELOAD_FAILED = 'reload_failed';

    /** Master → CLI：滚动重启进度更新 */
    public const TYPE_RELOAD_PROGRESS = 'reload_progress';

    /** Master → Worker：整机内存压力档位广播（可重建内存回收） */
    public const TYPE_MEMORY_PRESSURE = 'memory_pressure';

    /** Worker → Master：压力回收回执 */
    public const TYPE_MEMORY_RECLAIM_REPORT = 'memory_reclaim_report';

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
    public const ROLE_GATEWAY_FALLBACK = 'gateway_fallback';
    public const ROLE_GATEWAY_AGENT = 'gateway_agent';
    public const ROLE_GATEWAY_BACKEND = 'gateway_backend';

    // ========== 重载类型 ==========

    public const RELOAD_TYPE_CODE = 'code';
    public const RELOAD_TYPE_CACHE = 'cache';
    /** 强制重载：批量杀死所有 Worker 后重新启动（不排水） */
    public const RELOAD_TYPE_FORCE = 'force';

    // ========== CLI 命令动作 ==========

    public const ACTION_STOP = 'stop';
    /** CLI 诊断：探测 STOP 链路（不实际停机） */
    public const ACTION_STOP_TEST = 'stop_test';
    public const ACTION_RELOAD = 'reload';
    /** 重载并等待完成：Master 滚动重启完成后才返回结果 */
    public const ACTION_RELOAD_WAIT = 'reload_wait';
    public const ACTION_CACHE_CLEAR = 'cache_clear';
    public const ACTION_CACHE_NAMESPACE_INVALIDATE_V1 = 'cache_namespace_invalidate_v1';
    public const ACTION_CACHE_NAMESPACE_STATUS_V1 = 'cache_namespace_invalidation_status_v1';
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
    /** 独立于新 manifest 的安全隔离：停止当前 Master 代际全部 TLS/H3 serving。 */
    public const ACTION_SSL_SERVING_QUARANTINE = 'ssl_serving_quarantine';

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

    /** CLI → Master：清除 Dispatcher 路由缓存 */
    public const ACTION_ROUTING_CACHE_CLEAR = 'routing_cache_clear';

    /** CLI → Master：发布已 staged 的运行时策略包。 */
    public const ACTION_POLICY_PUBLISH = 'policy_publish';

    /** CLI → Master：回滚并发布前一或指定策略包。 */
    public const ACTION_POLICY_ROLLBACK = 'policy_rollback';
    public const ACTION_GATEWAY_FALLBACK_ENABLE = 'gateway_fallback_enable';
    public const ACTION_GATEWAY_FALLBACK_DRAIN = 'gateway_fallback_drain';
    public const ACTION_GATEWAY_FALLBACK_DISABLE = 'gateway_fallback_disable';
    public const ACTION_GATEWAY_BACKEND_ENABLE = 'gateway_backend_enable';
    public const ACTION_GATEWAY_NATIVE_DRAIN = 'gateway_native_drain';
    public const ACTION_GATEWAY_AGENT_ENABLE = 'gateway_agent_enable';
    public const ACTION_GATEWAY_AGENT_STATUS = 'gateway_agent_status';
    public const ACTION_GATEWAY_AGENT_COMMIT = 'gateway_agent_commit';
    public const ACTION_GATEWAY_AGENT_DISABLE = 'gateway_agent_disable';
    /** Host promotion rollback asks the still-running project Master to restore its own Nginx identity. */
    public const ACTION_GATEWAY_LEGACY_NGINX_RESTORE = 'gateway_legacy_nginx_restore';

    /** Master → Worker：热重载 SSL 证书映射（不重启进程） */
    public const TYPE_SSL_CERT_RELOAD = 'ssl_cert_reload';

    /** TLS Worker → Master：精确 serving-manifest 代际已原子切换。 */
    public const TYPE_SSL_CERT_RELOAD_ACK = 'ssl_cert_reload_ack';

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

    /** Master → Worker/Dispatcher：健康检查 ping */
    public const TYPE_PING = 'ping';

    /** Worker/Dispatcher → Master：健康检查 pong 响应 */
    public const TYPE_PONG = 'pong';

    /** Master → Dispatcher：清除路由缓存 */
    public const TYPE_ROUTING_CACHE_CLEAR = 'routing_cache_clear';

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
    public static function extractMessages(string &$buffer, bool $requireType = true, int $maxLinesPerCall = 0): array
    {
        return NdjsonProtocol::extractMessages($buffer, $requireType, $maxLinesPerCall);
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
        string $instanceCode = '',
        string $msgId = '',
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0,
    ): string
    {
        $data = [
            'type'      => self::TYPE_REGISTER,
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
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
        self::appendLeaseIdentity($data, $slotId, $leaseId, $generation);
        return self::encode($data);
    }

    /** @param array<string,int|string> $routeStatus */
    public static function http3RouteActivated(
        int $workerId,
        int $port,
        string $msgId,
        string $slotId,
        string $leaseId,
        int $generation,
        int $ownerEpoch,
        string $activationId,
        string $nativeDigest,
        array $routeStatus,
    ): string {
        return self::encode([
            'type' => self::TYPE_HTTP3_ROUTE_ACTIVATED,
            'worker_id' => $workerId,
            'port' => $port,
            'msg_id' => $msgId,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'owner_epoch' => $ownerEpoch,
            'activation_id' => $activationId,
            'native_digest' => $nativeDigest,
            'route_status' => $routeStatus,
        ]);
    }

    /**
     * 构建 ack 消息
     */
    public static function ack(
        int $resurrectionPriority = self::RESURRECTION_NONE,
        string $msgId = '',
        int $clientId = 0,
    ): string {
        $data = [
            'type'                  => self::TYPE_ACK,
            'resurrection_priority' => $resurrectionPriority,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($clientId > 0) {
            $data['client_id'] = $clientId;
        }

        return self::encode($data);
    }

    /**
     * 构建 ready 消息
     */
    public static function ready(
        string $role,
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = '',
        string $msgId = '',
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0,
        string $hostLeaseId = '',
    ): string
    {
        $data = [
            'type'      => self::TYPE_READY,
            'role'      => $role,
            'worker_id' => $workerId,
            'port'      => $port,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        if ($launchId !== '') {
            $data['launch_id'] = $launchId;
        }
        if (\in_array($role, [
            self::ROLE_WORKER,
            self::ROLE_MAINTENANCE,
            self::ROLE_GATEWAY_FALLBACK,
            self::ROLE_GATEWAY_BACKEND,
        ], true)) {
            $readiness = \Weline\Server\Service\Runtime\WorkerReadinessState::snapshot();
            $data['readiness_protocol_version'] = $readiness['readiness_protocol_version'];
            $data['readiness_capabilities'] = $readiness['readiness_capabilities'];
            $data['topology'] = $readiness['topology'];
            $data['policy_digest'] = $readiness['policy_digest'];
            $data['container_registry_digest'] = $readiness['container_registry_digest'];
            $data['warmup_state'] = $readiness['warmup_state'];
            $data['homepage_fpc'] = $readiness['homepage_fpc'];
            $data['dynamic_first_render'] = $readiness['dynamic_first_render'];
            $data['listen_capabilities'] = $readiness['listen_capabilities'];
            $data['namespace_authority_clock'] = $readiness['namespace_authority_clock'];
            $data['serving_manifest_generation'] = $readiness['serving_manifest_generation'];
            $data['serving_manifest_digest'] = $readiness['serving_manifest_digest'];
            $data['serving_manifest_route_count'] = $readiness['serving_manifest_route_count'];
        } elseif ($role === self::ROLE_DISPATCHER) {
            $data += DispatcherPolicyControl::readinessSnapshot();
        }
        self::appendLeaseIdentity($data, $slotId, $leaseId, $generation);
        if ($hostLeaseId !== '') {
            $data['host_lease_id'] = $hostLeaseId;
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
     * 构建 Master 下发给 Dispatcher 的权威路由表。
     *
     * @param int[] $ports
     * @param array<int, array<string, mixed>> $workers 规范化 worker 描述（role/slot_id/lease_id/generation/port/state）
     */
    public static function setRouteTable(
        array $ports,
        string $role = self::ROLE_WORKER,
        array $workers = [],
        int $routeVersion = 0,
        int $epoch = 0,
        string $traceId = ''
    ): string
    {
        $normalizedPorts = \array_values(\array_map('intval', $ports));
        \sort($normalizedPorts, \SORT_NUMERIC);
        $normalizedWorkers = $workers !== [] ? self::normalizeWorkerDescriptors($workers, $role) : [];

        $checksum = self::computeRouteTableChecksum($role, $routeVersion, $epoch, $normalizedPorts, $normalizedWorkers);

        $data = [
            'type'          => self::TYPE_SET_ROUTE_TABLE,
            'role'          => $role,
            'ports'         => $normalizedPorts,
            'route_version' => $routeVersion,
            'checksum'      => $checksum,
        ];
        if ($normalizedWorkers !== []) {
            $data['workers'] = $normalizedWorkers;
        }
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        self::appendTraceId($data, $traceId);
        return self::encode($data);
    }

    /**
     * 计算路由表内容校验和（B-i：内部使用，亦供单元测试 / Dispatcher 端二次校验）。
     *
     * 输入 ports / workers 必须已规范化（见 setRouteTable 内的预处理）。
     *
     * @param int[] $normalizedPorts
     * @param array<int, array<string, mixed>> $normalizedWorkers
     */
    public static function computeRouteTableChecksum(
        string $role,
        int $routeVersion,
        int $epoch,
        array $normalizedPorts,
        array $normalizedWorkers
    ): string
    {
        $material = [
            'role'          => $role,
            'route_version' => $routeVersion,
            'epoch'         => $epoch,
            'ports'         => $normalizedPorts,
            'workers'       => $normalizedWorkers,
        ];
        return \sha1((string) \json_encode($material, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * 构建 cache_clear 消息
     */
    public static function cacheClear(int $cacheEpoch = 0): string
    {
        $payload = [
            'type' => self::TYPE_CACHE_CLEAR,
        ];
        if ($cacheEpoch > 0) {
            $payload['cache_epoch'] = $cacheEpoch;
        }

        return self::encode($payload);
    }

    /**
     * 构建 cache_clear 代际回执。
     *
     * applied=false 仅表示该代际已经生效，本次为幂等重复请求。
     */
    public static function cacheClearAck(
        int $cacheEpoch,
        bool $success = true,
        string $error = '',
        int $workerId = 0,
        bool $applied = true,
        int $currentEpoch = 0,
    ): string {
        $payload = [
            'type' => self::TYPE_CACHE_CLEAR_ACK,
            'cache_epoch' => \max(0, $cacheEpoch),
            'success' => $success,
            'applied' => $applied,
        ];
        if ($error !== '') {
            $payload['error'] = \substr($error, 0, 512);
        }
        if ($workerId > 0) {
            $payload['worker_id'] = $workerId;
        }
        if ($currentEpoch > 0) {
            $payload['current_epoch'] = $currentEpoch;
        }

        return self::encode($payload);
    }

    /** @param array<string,mixed> $frame */
    public static function cacheNamespaceInvalidationV1(array $frame): string
    {
        return self::encode([
            'type' => self::TYPE_CACHE_NAMESPACE_INVALIDATE_V1,
            'schema_version' => (int)($frame['schema_version'] ?? 0),
            'operation_id' => (string)($frame['operation_id'] ?? ''),
            'authority_clock' => (int)($frame['authority_clock'] ?? 0),
            'changes' => \is_array($frame['changes'] ?? null) ? $frame['changes'] : [],
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $source
     */
    public static function cacheNamespaceInvalidationAckV1(array $result, array $source): string
    {
        $generations = [];
        foreach ((array)($result['generations'] ?? []) as $namespace => $generation) {
            if (!\is_string($namespace) || !\is_int($generation) || $generation < 0) {
                continue;
            }
            $generations[$namespace] = $generation;
        }
        \ksort($generations, \SORT_STRING);

        return self::encode([
            'type' => self::TYPE_CACHE_NAMESPACE_INVALIDATE_ACK_V1,
            'operation_id' => (string)($result['operation_id'] ?? ''),
            'success' => (bool)($result['success'] ?? false),
            'applied' => (bool)($result['applied'] ?? false),
            'authority_clock' => \max(0, (int)($result['authority_clock'] ?? 0)),
            'generations' => $generations,
            'source' => [
                'client_id' => \max(0, (int)($source['client_id'] ?? 0)),
                'role' => (string)($source['role'] ?? ''),
                'worker_id' => \max(0, (int)($source['worker_id'] ?? 0)),
                'slot_id' => (string)($source['slot_id'] ?? ''),
                'lease_id' => (string)($source['lease_id'] ?? ''),
                'slot_generation' => \max(0, (int)($source['slot_generation'] ?? 0)),
                'pid' => \max(0, (int)($source['pid'] ?? 0)),
            ],
            'error_code' => \substr((string)($result['error_code'] ?? ''), 0, 64),
            'error' => \substr((string)($result['error'] ?? ''), 0, 512),
        ]);
    }

    /**
     * 构建 ssl_cert_reload 消息（热重载 SSL 证书映射，不重启 Worker）
     *
     * @param string[]|null $domains 需要清除负缓存并重新加载的域名列表；
     *                               null 或空数组 = 全量重载（仅刷新 map 文件，不清除负缓存）；
     *                               非空 = 只为指定域清除负缓存并刷新内存证书映射。
     */
    public static function sslCertReload(
        ?array $domains = null,
        string $operationId = '',
        int $expectedManifestGeneration = 0,
        string $expectedManifestDigest = '',
        int $expectedTlsRouteCount = -1,
        int $expectedRetiredContextCount = -1,
        string $expectedRetiredContextDigest = '',
    ): string {
        $payload = [
            'type' => self::TYPE_SSL_CERT_RELOAD,
            'operation_id' => \strtolower(\trim($operationId)),
            'expected_manifest_generation' => \max(0, $expectedManifestGeneration),
            'expected_manifest_digest' => \strtolower(\trim($expectedManifestDigest)),
            'expected_tls_route_count' => $expectedTlsRouteCount,
            'expected_retired_context_count' => $expectedRetiredContextCount,
            'expected_retired_context_digest' => \strtolower(\trim(
                $expectedRetiredContextDigest,
            )),
        ];
        if (!empty($domains)) {
            $payload['domains'] = \array_values(\array_unique($domains));
        }
        return self::encode($payload);
    }

    /**
     * Build a fail-closed TLS reload receipt. A success receipt is valid only
     * when the applied immutable manifest exactly matches the requested pair.
     */
    public static function sslCertReloadAck(
        string $operationId,
        bool $success,
        int $appliedManifestGeneration,
        string $appliedManifestDigest,
        int $appliedTlsRouteCount,
        string $servingMode,
        string $tlsContextState,
        int $retiredContextCount,
        string $retiredContextDigest,
        int $workerId = 0,
        string $errorCode = '',
        string $error = '',
    ): string {
        $payload = [
            'type' => self::TYPE_SSL_CERT_RELOAD_ACK,
            'operation_id' => \strtolower(\trim($operationId)),
            'success' => $success,
            'applied_manifest_generation' => \max(0, $appliedManifestGeneration),
            'applied_manifest_digest' => \strtolower(\trim($appliedManifestDigest)),
            'applied_tls_route_count' => \max(0, $appliedTlsRouteCount),
            'serving_mode' => \substr(\strtolower(\trim($servingMode)), 0, 32),
            'tls_context_state' => \substr(\strtolower(\trim($tlsContextState)), 0, 32),
            'retired_context_count' => \max(0, $retiredContextCount),
            'retired_context_digest' => \strtolower(\trim($retiredContextDigest)),
        ];
        if ($workerId > 0) {
            $payload['worker_id'] = $workerId;
        }
        if ($errorCode !== '') {
            $payload['error_code'] = \substr($errorCode, 0, 64);
        }
        if ($error !== '') {
            $payload['error'] = \substr(\str_replace(["\r", "\n"], ' ', $error), 0, 512);
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
     * @param array<string, mixed> $bundle
     */
    public static function policyPrepare(array $bundle): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_PREPARE,
            'digest' => (string)($bundle['digest'] ?? ''),
            'bundle' => $bundle,
        ]);
    }

    /**
     * @param list<string> $capabilities
     */
    public static function policyPreparedAck(
        string $digest,
        bool $success = true,
        string $error = '',
        array $capabilities = [],
    ): string {
        return self::encode([
            'type' => self::TYPE_POLICY_PREPARED_ACK,
            'digest' => $digest,
            'success' => $success,
            'error' => $error,
            'capabilities' => \array_values(\array_unique(\array_map('strval', $capabilities))),
        ]);
    }

    public static function policyActivate(string $digest): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_ACTIVATE,
            'digest' => $digest,
        ]);
    }

    public static function policyActivatedAck(string $digest, bool $success = true, string $error = ''): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_ACTIVATED_ACK,
            'digest' => $digest,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public static function policyCommit(string $digest): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_COMMIT,
            'digest' => $digest,
        ]);
    }

    public static function policyCommittedAck(string $digest, bool $success = true, string $error = ''): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_COMMITTED_ACK,
            'digest' => $digest,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public static function policyRollback(?string $digest = null, bool $abort = false): string
    {
        $payload = ['type' => self::TYPE_POLICY_ROLLBACK];
        if ($digest !== null && $digest !== '') {
            $payload['digest'] = $digest;
        }
        if ($abort) {
            $payload['abort'] = true;
        }
        return self::encode($payload);
    }

    public static function policyRollbackAck(string $digest, bool $success = true, string $error = ''): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_ROLLBACK_ACK,
            'digest' => $digest,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public static function policyStateDelta(string $instance, string $ip, int $expiresAt): string
    {
        return self::encode([
            'type' => self::TYPE_POLICY_STATE_DELTA,
            'version' => 1,
            'state' => 'ban',
            'instance' => \trim($instance),
            'ip' => \trim($ip),
            'expires_at' => $expiresAt,
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
     * 从 Worker 的总排水预算派生 soft/hard 截止点。
     *
     * soft 最多比 hard 早 1 秒：它只允许关闭无应用工作的空闲
     * transport；hard 是 SSE/WebSocket/H2 等所有未收敛连接的最终上限。
     *
     * @return array{soft:float,hard:float}
     */
    public static function drainDeadlines(float $hardDeadlineSeconds): array
    {
        $hard = \max(1.0, \min(7200.0, $hardDeadlineSeconds));
        $soft = $hard > 1.0 ? \max(1.0, $hard - 1.0) : $hard;

        return ['soft' => $soft, 'hard' => $hard];
    }

    /**
     * 统一 HTTP/1.1、SSE、WebSocket 和 HTTP/2 的排水判定。
     */
    public static function drainLifecycleDecision(
        float $elapsedSeconds,
        float $softDeadlineSeconds,
        float $hardDeadlineSeconds,
        int $connectionCount,
        int $activeRequests,
        int $pendingApplicationWork,
        int $longLivedConnections,
        int $http2Connections,
    ): string {
        $connectionCount = \max(0, $connectionCount);
        $hasPendingWork = \max(0, $activeRequests) > 0
            || \max(0, $pendingApplicationWork) > 0
            || \max(0, $longLivedConnections) > 0
            || \max(0, $http2Connections) > 0;

        if ($connectionCount === 0 && !$hasPendingWork) {
            return self::DRAIN_ACTION_COMPLETE;
        }

        $hardDeadlineSeconds = \max(0.0, $hardDeadlineSeconds);
        if (\max(0.0, $elapsedSeconds) >= $hardDeadlineSeconds) {
            return self::DRAIN_ACTION_FORCE;
        }

        $softDeadlineSeconds = \max(0.0, \min($softDeadlineSeconds, $hardDeadlineSeconds));
        if (!$hasPendingWork && \max(0.0, $elapsedSeconds) >= $softDeadlineSeconds) {
            return self::DRAIN_ACTION_CLOSE_IDLE;
        }

        return self::DRAIN_ACTION_WAIT;
    }

    /**
     * 生成可版本化的 drain 完成报告，明确区分观测值和实际终止数。
     *
     * @param array<string,int> $observed
     * @param array<string,int> $terminated
     * @return array{
     *     schema:string,
     *     outcome:string,
     *     forced:bool,
     *     elapsed_ms:int,
     *     soft_deadline_ms:int,
     *     hard_deadline_ms:int,
     *     observed:array<string,int>,
     *     terminated:array<string,int>
     * }
     */
    public static function drainCompletionReport(
        string $outcome,
        float $elapsedSeconds,
        float $softDeadlineSeconds,
        float $hardDeadlineSeconds,
        array $observed = [],
        array $terminated = [],
    ): array {
        if (!\in_array($outcome, [
            self::DRAIN_OUTCOME_NATURAL,
            self::DRAIN_OUTCOME_IDLE_CLEANUP,
            self::DRAIN_OUTCOME_FORCED,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported drain completion outcome: ' . $outcome);
        }

        $normalizeCounts = static function (array $counts): array {
            $normalized = [];
            foreach (self::DRAIN_REPORT_COUNT_KEYS as $key) {
                $normalized[$key] = \max(0, (int)($counts[$key] ?? 0));
            }

            return $normalized;
        };

        return [
            'schema' => 'wls-drain-report/1',
            'outcome' => $outcome,
            'forced' => $outcome === self::DRAIN_OUTCOME_FORCED,
            'elapsed_ms' => (int)\round(\max(0.0, $elapsedSeconds) * 1000),
            'soft_deadline_ms' => (int)\round(\max(0.0, $softDeadlineSeconds) * 1000),
            'hard_deadline_ms' => (int)\round(\max(0.0, $hardDeadlineSeconds) * 1000),
            'observed' => $normalizeCounts($observed),
            'terminated' => $normalizeCounts($terminated),
        ];
    }

    /**
     * Build the per-connection RFC 6455 state used by both production workers.
     *
     * @return array{
     *     buffer:string,
     *     fragment_opcode:?int,
     *     fragment_payload:string,
     *     close_sent:bool,
     *     close_received:bool
     * }
     */
    public static function webSocketInitialState(): array
    {
        return [
            'buffer' => '',
            'fragment_opcode' => null,
            'fragment_payload' => '',
            'close_sent' => false,
            'close_received' => false,
        ];
    }

    /**
     * Only an application-authorized and cryptographically coherent HTTP/1.1
     * upgrade may switch the worker from HTTP parsing to the frame data plane.
     */
    public static function webSocketUpgradeAccepted(string $request, string $response): bool
    {
        $requestHead = self::webSocketParseHttpHead($request);
        $responseHead = self::webSocketParseHttpHead($response);
        if ($requestHead === null || $responseHead === null) {
            return false;
        }
        if (!\preg_match('/^GET\s+\S+\s+HTTP\/1\.1$/i', $requestHead['start_line'])) {
            return false;
        }
        if (!\preg_match('/^HTTP\/1\.1\s+101(?:\s|$)/i', $responseHead['start_line'])) {
            return false;
        }

        $requestHeaders = $requestHead['headers'];
        $responseHeaders = $responseHead['headers'];
        if (!self::webSocketHeaderHasToken($requestHeaders, 'upgrade', 'websocket')
            || !self::webSocketHeaderHasToken($requestHeaders, 'connection', 'upgrade')
            || !self::webSocketHeaderHasToken($responseHeaders, 'upgrade', 'websocket')
            || !self::webSocketHeaderHasToken($responseHeaders, 'connection', 'upgrade')
        ) {
            return false;
        }

        $versions = $requestHeaders['sec-websocket-version'] ?? [];
        $keys = $requestHeaders['sec-websocket-key'] ?? [];
        $accepts = $responseHeaders['sec-websocket-accept'] ?? [];
        if (\count($versions) !== 1
            || \trim((string)$versions[0]) !== '13'
            || \count($keys) !== 1
            || \count($accepts) !== 1
        ) {
            return false;
        }

        // This bounded runtime does not implement extension RSV semantics. An
        // application must not negotiate compression (or another extension)
        // that the production frame parser cannot honor.
        if (isset($responseHeaders['sec-websocket-extensions'])) {
            return false;
        }

        $selectedProtocols = $responseHeaders['sec-websocket-protocol'] ?? [];
        if (\count($selectedProtocols) > 1) {
            return false;
        }
        if ($selectedProtocols !== []) {
            $selectedProtocol = \trim((string)$selectedProtocols[0]);
            if ($selectedProtocol === ''
                || \str_contains($selectedProtocol, ',')
                || \preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/i', $selectedProtocol) !== 1
            ) {
                return false;
            }
            $offeredProtocols = [];
            foreach ($requestHeaders['sec-websocket-protocol'] ?? [] as $offeredHeader) {
                foreach (\explode(',', $offeredHeader) as $offeredProtocol) {
                    $offeredProtocol = \trim($offeredProtocol);
                    if ($offeredProtocol !== '') {
                        $offeredProtocols[] = $offeredProtocol;
                    }
                }
            }
            if (!\in_array($selectedProtocol, $offeredProtocols, true)) {
                return false;
            }
        }

        $key = \trim((string)$keys[0]);
        $decodedKey = \base64_decode($key, true);
        if (!\is_string($decodedKey) || \strlen($decodedKey) !== 16) {
            return false;
        }

        $expectedAccept = \base64_encode(\sha1(
            $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true,
        ));

        return \hash_equals($expectedAccept, \trim((string)$accepts[0]));
    }

    /**
     * Incrementally consume masked client frames and produce unmasked server
     * frames. Complete text/binary messages are echoed because the existing WLS
     * upgrade contract has no separate per-frame application callback.
     *
     * @param array<string,mixed> $state
     * @return array{
     *     state:array<string,mixed>,
     *     outbound:list<string>,
     *     events:list<array<string,mixed>>,
     *     close_transport:bool,
     *     error_code:?int
     * }
     */
    public static function webSocketConsumeClientBytes(
        array $state,
        string $bytes,
        int $maxMessageBytes = self::WEBSOCKET_DEFAULT_MAX_MESSAGE_BYTES,
    ): array {
        $state = self::webSocketNormalizeState($state);
        $maxMessageBytes = \max(1, $maxMessageBytes);
        if ($bytes !== '') {
            $state['buffer'] .= $bytes;
        }

        $events = [];
        $outbound = [];
        if ($state['close_received']) {
            $state['buffer'] = '';

            return self::webSocketResult($state, $outbound, $events, true, null);
        }

        $processedFrames = 0;
        while ($processedFrames < self::WEBSOCKET_MAX_FRAMES_PER_TICK) {
            $bufferLength = \strlen($state['buffer']);
            if ($bufferLength < 2) {
                break;
            }

            $firstByte = \ord($state['buffer'][0]);
            $secondByte = \ord($state['buffer'][1]);
            $fin = ($firstByte & 0x80) !== 0;
            $rsv = $firstByte & 0x70;
            $opcode = $firstByte & 0x0f;
            $masked = ($secondByte & 0x80) !== 0;
            $lengthMarker = $secondByte & 0x7f;

            if ($rsv !== 0
                || !\in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xa], true)
                || !$masked
            ) {
                return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
            }

            $isControl = $opcode >= 0x8;
            if ($isControl && (!$fin || $lengthMarker > 125)) {
                return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
            }

            $headerLength = 2;
            $payloadLength = $lengthMarker;
            if ($lengthMarker === 126) {
                if ($bufferLength < 4) {
                    break;
                }
                $unpacked = \unpack('nlength', \substr($state['buffer'], 2, 2));
                $payloadLength = (int)($unpacked['length'] ?? 0);
                $headerLength = 4;
                if ($payloadLength < 126) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                }
            } elseif ($lengthMarker === 127) {
                if ($bufferLength < 10) {
                    break;
                }
                $unpacked = \unpack('Nhigh/Nlow', \substr($state['buffer'], 2, 8));
                $high = (int)($unpacked['high'] ?? 0);
                $low = (int)($unpacked['low'] ?? 0);
                if (($high & 0x80000000) !== 0) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                }
                if ($high !== 0) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1009);
                }
                $payloadLength = $low;
                $headerLength = 10;
                if ($payloadLength <= 65535) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                }
            }

            $fragmentLength = $opcode === 0x0 ? \strlen($state['fragment_payload']) : 0;
            if (!$isControl
                && ($payloadLength > $maxMessageBytes || $fragmentLength > ($maxMessageBytes - $payloadLength))
            ) {
                return self::webSocketProtocolFailure($state, $outbound, $events, 1009);
            }

            $frameLength = $headerLength + 4 + $payloadLength;
            if ($bufferLength < $frameLength) {
                break;
            }

            $mask = \substr($state['buffer'], $headerLength, 4);
            $maskedPayload = \substr($state['buffer'], $headerLength + 4, $payloadLength);
            $state['buffer'] = (string)\substr($state['buffer'], $frameLength);
            $payload = '';
            for ($index = 0; $index < $payloadLength; ++$index) {
                $payload .= $maskedPayload[$index] ^ $mask[$index % 4];
            }
            ++$processedFrames;

            if ($opcode === 0x8) {
                if ($payloadLength === 1) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                }
                $closeCode = null;
                $closeReason = '';
                if ($payloadLength >= 2) {
                    $unpacked = \unpack('ncode', \substr($payload, 0, 2));
                    $closeCode = (int)($unpacked['code'] ?? 0);
                    $closeReason = (string)\substr($payload, 2);
                    if (!self::webSocketValidCloseCode($closeCode)) {
                        return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                    }
                    if (!self::webSocketValidUtf8($closeReason)) {
                        return self::webSocketProtocolFailure($state, $outbound, $events, 1007);
                    }
                }

                $events[] = ['type' => 'close', 'code' => $closeCode, 'reason' => $closeReason];
                $state['close_received'] = true;
                if (!$state['close_sent']) {
                    $outbound[] = self::webSocketServerFrame(0x8, $payload);
                    $state['close_sent'] = true;
                }
                $state['buffer'] = '';

                return self::webSocketResult($state, $outbound, $events, true, null);
            }

            if ($state['close_sent']) {
                return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
            }

            if ($opcode === 0x9) {
                $events[] = ['type' => 'ping', 'data' => $payload];
                $outbound[] = self::webSocketServerFrame(0xa, $payload);
                continue;
            }
            if ($opcode === 0xa) {
                $events[] = ['type' => 'pong', 'data' => $payload];
                continue;
            }

            if ($opcode === 0x0) {
                if ($state['fragment_opcode'] === null) {
                    return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
                }
                $state['fragment_payload'] .= $payload;
                if (!$fin) {
                    continue;
                }
                $messageOpcode = (int)$state['fragment_opcode'];
                $messagePayload = $state['fragment_payload'];
                $state['fragment_opcode'] = null;
                $state['fragment_payload'] = '';
                $completed = self::webSocketCompleteMessage(
                    $state,
                    $outbound,
                    $events,
                    $messageOpcode,
                    $messagePayload,
                );
                if ($completed !== null) {
                    return $completed;
                }
                continue;
            }

            if ($state['fragment_opcode'] !== null) {
                return self::webSocketProtocolFailure($state, $outbound, $events, 1002);
            }
            if (!$fin) {
                $state['fragment_opcode'] = $opcode;
                $state['fragment_payload'] = $payload;
                continue;
            }

            $completed = self::webSocketCompleteMessage(
                $state,
                $outbound,
                $events,
                $opcode,
                $payload,
            );
            if ($completed !== null) {
                return $completed;
            }
        }

        return self::webSocketResult(
            $state,
            $outbound,
            $events,
            false,
            null,
            $processedFrames >= self::WEBSOCKET_MAX_FRAMES_PER_TICK && $state['buffer'] !== '',
        );
    }

    /**
     * Send one RFC 6455 close frame and keep reading until the peer answers or
     * the worker's existing hard drain deadline forces the transport closed.
     *
     * @param array<string,mixed> $state
     * @return array{
     *     state:array<string,mixed>,outbound:list<string>,events:list<array<string,mixed>>,
     *     close_transport:bool,error_code:?int
     * }
     */
    public static function webSocketInitiateServerClose(
        array $state,
        int $code = 1001,
        string $reason = '',
    ): array {
        $state = self::webSocketNormalizeState($state);
        if (!self::webSocketValidCloseCode($code)) {
            throw new \InvalidArgumentException('Unsupported WebSocket close code: ' . $code);
        }
        if (!self::webSocketValidUtf8($reason)) {
            throw new \InvalidArgumentException('WebSocket close reason must be valid UTF-8.');
        }
        while (\strlen($reason) > 123) {
            $reason = (string)\substr($reason, 0, -1);
            while ($reason !== '' && !self::webSocketValidUtf8($reason)) {
                $reason = (string)\substr($reason, 0, -1);
            }
        }

        $outbound = [];
        if (!$state['close_sent']) {
            $outbound[] = self::webSocketServerFrame(0x8, \pack('n', $code) . $reason);
            $state['close_sent'] = true;
        }

        return self::webSocketResult(
            $state,
            $outbound,
            [],
            $state['close_received'],
            null,
        );
    }

    public static function webSocketServerFrame(int $opcode, string $payload = '', bool $fin = true): string
    {
        if (!\in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xa], true)) {
            throw new \InvalidArgumentException('Unsupported WebSocket opcode: ' . $opcode);
        }
        $payloadLength = \strlen($payload);
        if ($opcode >= 0x8 && (!$fin || $payloadLength > 125)) {
            throw new \InvalidArgumentException('WebSocket control frames must be final and at most 125 bytes.');
        }

        $frame = \chr(($fin ? 0x80 : 0x00) | $opcode);
        if ($payloadLength < 126) {
            return $frame . \chr($payloadLength) . $payload;
        }
        if ($payloadLength <= 65535) {
            return $frame . \chr(126) . \pack('n', $payloadLength) . $payload;
        }

        return $frame . \chr(127) . \pack('NN', 0, $payloadLength) . $payload;
    }

    /**
     * @param array<string,mixed> $state
     * @param list<string> $outbound
     * @param list<array<string,mixed>> $events
     * @return null|array<string,mixed>
     */
    private static function webSocketCompleteMessage(
        array &$state,
        array &$outbound,
        array &$events,
        int $opcode,
        string $payload,
    ): ?array {
        if ($opcode === 0x1 && !self::webSocketValidUtf8($payload)) {
            return self::webSocketProtocolFailure($state, $outbound, $events, 1007);
        }

        $events[] = [
            'type' => $opcode === 0x1 ? 'text' : 'binary',
            'data' => $payload,
        ];
        $outbound[] = self::webSocketServerFrame($opcode, $payload);

        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function webSocketNormalizeState(array $state): array
    {
        $fragmentOpcode = $state['fragment_opcode'] ?? null;
        if (!\in_array($fragmentOpcode, [0x1, 0x2], true)) {
            $fragmentOpcode = null;
        }

        return [
            'buffer' => \is_string($state['buffer'] ?? null) ? $state['buffer'] : '',
            'fragment_opcode' => $fragmentOpcode,
            'fragment_payload' => \is_string($state['fragment_payload'] ?? null)
                ? $state['fragment_payload']
                : '',
            'close_sent' => ($state['close_sent'] ?? false) === true,
            'close_received' => ($state['close_received'] ?? false) === true,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param list<string> $outbound
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private static function webSocketProtocolFailure(
        array $state,
        array $outbound,
        array $events,
        int $code,
    ): array {
        $state = self::webSocketNormalizeState($state);
        $state['buffer'] = '';
        $state['fragment_opcode'] = null;
        $state['fragment_payload'] = '';
        if (!$state['close_sent']) {
            $outbound[] = self::webSocketServerFrame(0x8, \pack('n', $code));
            $state['close_sent'] = true;
        }

        return self::webSocketResult($state, $outbound, $events, true, $code);
    }

    /**
     * @param array<string,mixed> $state
     * @param list<string> $outbound
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private static function webSocketResult(
        array $state,
        array $outbound,
        array $events,
        bool $closeTransport,
        ?int $errorCode,
        bool $frameBudgetExhausted = false,
    ): array {
        return [
            'state' => $state,
            'outbound' => $outbound,
            'events' => $events,
            'close_transport' => $closeTransport,
            'error_code' => $errorCode,
            'frame_budget_exhausted' => $frameBudgetExhausted,
        ];
    }

    private static function webSocketValidUtf8(string $value): bool
    {
        return $value === '' || \preg_match('//u', $value) === 1;
    }

    private static function webSocketValidCloseCode(int $code): bool
    {
        return \in_array($code, [
            1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014,
        ], true) || ($code >= 3000 && $code <= 4999);
    }

    /**
     * @return null|array{start_line:string,headers:array<string,list<string>>}
     */
    private static function webSocketParseHttpHead(string $message): ?array
    {
        $headEnd = \strpos($message, "\r\n\r\n");
        $head = $headEnd === false ? $message : \substr($message, 0, $headEnd);
        $lines = \explode("\r\n", $head);
        $startLine = \trim((string)\array_shift($lines));
        if ($startLine === '') {
            return null;
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || \str_starts_with($line, ' ') || \str_starts_with($line, "\t")) {
                return null;
            }
            $separator = \strpos($line, ':');
            if ($separator === false) {
                return null;
            }
            $name = \strtolower(\trim(\substr($line, 0, $separator)));
            if ($name === '' || \preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/', $name) !== 1) {
                return null;
            }
            $headers[$name][] = \trim(\substr($line, $separator + 1));
        }

        return ['start_line' => $startLine, 'headers' => $headers];
    }

    /**
     * @param array<string,list<string>> $headers
     */
    private static function webSocketHeaderHasToken(array $headers, string $name, string $token): bool
    {
        foreach ($headers[$name] ?? [] as $value) {
            foreach (\explode(',', $value) as $candidate) {
                if (\strcasecmp(\trim($candidate), $token) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 构建 drain 消息
     */
    public static function drain(array $ports, int $drainTimeoutSec = 0): string
    {
        $payload = [
            'type'  => self::TYPE_DRAIN,
            'ports' => $ports,
        ];
        if ($drainTimeoutSec > 0) {
            $payload['drain_timeout_sec'] = $drainTimeoutSec;
        }

        return self::encode($payload);
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
     * Canonical digest for one action inside a reversible fallback-listener
     * transaction. DRAIN and UNDRAIN deliberately share transition_id; the
     * UNDRAIN digest is chained to the acknowledged DRAIN digest.
     *
     * @param array<string,mixed> $identity
     */
    public static function gatewayFallbackListenerActionDigest(
        string $action,
        string $targetListenerState,
        string $transitionId,
        string $predecessorActionDigest,
        array $identity,
    ): string {
        $identity = self::normaliseGatewayFallbackListenerIdentity($identity);
        self::assertGatewayFallbackListenerAction(
            $action,
            $targetListenerState,
            $transitionId,
            $predecessorActionDigest,
        );

        return \hash('sha256', GatewayClient::canonicalJson([
            'protocol' => self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
            'action' => $action,
            'target_listener_state' => $targetListenerState,
            'transition_id' => $transitionId,
            'predecessor_action_digest' => $predecessorActionDigest,
            'identity' => $identity,
        ]));
    }

    /**
     * Build a generation-fenced reversible transition for the one fallback
     * listener. Generic worker/dispatcher drain messages deliberately do not
     * carry this contract.
     *
     * @param array<string,mixed> $identity
     */
    public static function gatewayFallbackListenerTransition(
        string $action,
        string $targetListenerState,
        string $transitionId,
        string $actionDigest,
        string $predecessorActionDigest,
        array $identity,
        int $drainTimeoutSec = 0,
    ): string {
        $identity = self::normaliseGatewayFallbackListenerIdentity($identity);
        $expectedDigest = self::gatewayFallbackListenerActionDigest(
            $action,
            $targetListenerState,
            $transitionId,
            $predecessorActionDigest,
            $identity,
        );
        if (!\hash_equals($expectedDigest, $actionDigest)
            || $drainTimeoutSec < 0
            || $drainTimeoutSec > 3600
            || ($action === self::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN
                && $drainTimeoutSec !== 0)
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener transition action or timeout is invalid.'
            );
        }
        $payload = [
            'type' => $action === self::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN
                ? self::TYPE_DRAIN
                : self::TYPE_UNDRAIN,
            'protocol' => self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
            'action' => $action,
            'target_listener_state' => $targetListenerState,
            'transition_id' => $transitionId,
            'action_digest' => $actionDigest,
            'predecessor_action_digest' => $predecessorActionDigest,
            'identity' => $identity,
        ];
        if ($action === self::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN
            && $drainTimeoutSec > 0
        ) {
            $payload['drain_timeout_sec'] = $drainTimeoutSec;
        }

        return self::encode(self::validateGatewayFallbackListenerTransition($payload));
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    public static function validateGatewayFallbackListenerTransition(array $message): array
    {
        $action = self::requiredGatewayFallbackListenerString($message, 'action');
        $targetListenerState = self::requiredGatewayFallbackListenerString(
            $message,
            'target_listener_state',
        );
        $transitionId = self::requiredGatewayFallbackListenerString(
            $message,
            'transition_id',
        );
        $actionDigest = self::requiredGatewayFallbackListenerString(
            $message,
            'action_digest',
        );
        $predecessorActionDigest = self::requiredGatewayFallbackListenerString(
            $message,
            'predecessor_action_digest',
            true,
        );
        $identity = self::normaliseGatewayFallbackListenerIdentity(
            \is_array($message['identity'] ?? null) ? $message['identity'] : [],
        );
        $expectedType = $action === self::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN
            ? self::TYPE_DRAIN
            : self::TYPE_UNDRAIN;
        $drainTimeoutSec = $message['drain_timeout_sec'] ?? 0;
        $allowedKeys = [
            'type',
            'protocol',
            'action',
            'target_listener_state',
            'transition_id',
            'action_digest',
            'predecessor_action_digest',
            'identity',
        ];
        if (\array_key_exists('drain_timeout_sec', $message)) {
            $allowedKeys[] = 'drain_timeout_sec';
        }
        if (!self::sameGatewayFallbackListenerKeys($message, $allowedKeys)
            || !\hash_equals(
                self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
                (string)($message['protocol'] ?? ''),
            )
            || !\hash_equals($expectedType, (string)($message['type'] ?? ''))
            || !\is_int($drainTimeoutSec)
            || $drainTimeoutSec < 0
            || $drainTimeoutSec > 3600
            || ($action === self::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN
                && ($drainTimeoutSec !== 0
                    || \array_key_exists('drain_timeout_sec', $message)))
            || \preg_match('/\A[a-f0-9]{64}\z/D', $actionDigest) !== 1
            || !\hash_equals(
                self::gatewayFallbackListenerActionDigest(
                    $action,
                    $targetListenerState,
                    $transitionId,
                    $predecessorActionDigest,
                    $identity,
                ),
                $actionDigest,
            )
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener transition envelope is invalid.'
            );
        }

        $validated = [
            'type' => $expectedType,
            'protocol' => self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
            'action' => $action,
            'target_listener_state' => $targetListenerState,
            'transition_id' => $transitionId,
            'action_digest' => $actionDigest,
            'predecessor_action_digest' => $predecessorActionDigest,
            'identity' => $identity,
        ];
        if ($drainTimeoutSec > 0) {
            $validated['drain_timeout_sec'] = $drainTimeoutSec;
        }

        return $validated;
    }

    /** @param array<string,mixed> $identity */
    public static function gatewayFallbackListenerAck(
        string $action,
        string $targetListenerState,
        string $listenerState,
        string $transitionId,
        string $actionDigest,
        string $predecessorActionDigest,
        array $identity,
        bool $success,
        string $reason = '',
    ): string {
        $payload = [
            'type' => self::TYPE_GATEWAY_FALLBACK_LISTENER_ACK,
            'protocol' => self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
            'action' => $action,
            'target_listener_state' => $targetListenerState,
            'listener_state' => $listenerState,
            'transition_id' => $transitionId,
            'action_digest' => $actionDigest,
            'predecessor_action_digest' => $predecessorActionDigest,
            'identity' => self::normaliseGatewayFallbackListenerIdentity($identity),
            'success' => $success,
        ];
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        return self::encode(self::validateGatewayFallbackListenerAck($payload));
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    public static function validateGatewayFallbackListenerAck(array $message): array
    {
        $action = self::requiredGatewayFallbackListenerString($message, 'action');
        $targetState = self::requiredGatewayFallbackListenerString(
            $message,
            'target_listener_state',
        );
        $actualState = self::requiredGatewayFallbackListenerString(
            $message,
            'listener_state',
        );
        $transitionId = self::requiredGatewayFallbackListenerString(
            $message,
            'transition_id',
        );
        $actionDigest = self::requiredGatewayFallbackListenerString(
            $message,
            'action_digest',
        );
        $predecessorActionDigest = self::requiredGatewayFallbackListenerString(
            $message,
            'predecessor_action_digest',
            true,
        );
        $identity = self::normaliseGatewayFallbackListenerIdentity(
            \is_array($message['identity'] ?? null) ? $message['identity'] : [],
        );
        $reason = self::requiredGatewayFallbackListenerString(
            $message,
            'reason',
            true,
        );
        $allowedKeys = [
            'type',
            'protocol',
            'action',
            'target_listener_state',
            'listener_state',
            'transition_id',
            'action_digest',
            'predecessor_action_digest',
            'identity',
            'success',
        ];
        if (\array_key_exists('reason', $message)) {
            $allowedKeys[] = 'reason';
        }
        $success = $message['success'] ?? null;
        if (!self::sameGatewayFallbackListenerKeys($message, $allowedKeys)
            || !\hash_equals(
                self::TYPE_GATEWAY_FALLBACK_LISTENER_ACK,
                (string)($message['type'] ?? ''),
            )
            || !\hash_equals(
                self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
                (string)($message['protocol'] ?? ''),
            )
            || !\in_array($actualState, [
                self::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
                self::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
                self::GATEWAY_FALLBACK_LISTENER_STATE_TERMINAL,
            ], true)
            || !\is_bool($success)
            || (!$success && $reason === '')
            || \strlen($reason) > 256
            || ($success
                && !\hash_equals($targetState, $actualState))
            || \preg_match('/\A[a-f0-9]{64}\z/D', $actionDigest) !== 1
            || !\hash_equals(
                self::gatewayFallbackListenerActionDigest(
                    $action,
                    $targetState,
                    $transitionId,
                    $predecessorActionDigest,
                    $identity,
                ),
                $actionDigest,
            )
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener acknowledgement is invalid.'
            );
        }

        $validated = [
            'type' => self::TYPE_GATEWAY_FALLBACK_LISTENER_ACK,
            'protocol' => self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
            'action' => $action,
            'target_listener_state' => $targetState,
            'listener_state' => $actualState,
            'transition_id' => $transitionId,
            'action_digest' => $actionDigest,
            'predecessor_action_digest' => $predecessorActionDigest,
            'identity' => $identity,
            'success' => $success,
        ];
        if ($reason !== '') {
            $validated['reason'] = $reason;
        }

        return $validated;
    }

    private static function assertGatewayFallbackListenerAction(
        string $action,
        string $listenerState,
        string $transitionId,
        string $predecessorActionDigest,
    ): void {
        $drain = $action === self::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN
            && $listenerState === self::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING
            && $predecessorActionDigest === '';
        $undrain = $action === self::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN
            && $listenerState === self::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE
            && \preg_match('/\A[a-f0-9]{64}\z/D', $predecessorActionDigest) === 1;
        if ((!$drain && !$undrain)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $transitionId) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener action fence is invalid.'
            );
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    private static function normaliseGatewayFallbackListenerIdentity(array $identity): array
    {
        $canonical = [
            'schema' => (string)($identity['schema'] ?? ''),
            'project_uuid' => (string)($identity['project_uuid'] ?? ''),
            'wls_instance' => (string)($identity['wls_instance'] ?? ''),
            'role' => (string)($identity['role'] ?? ''),
            'slot_id' => (string)($identity['slot_id'] ?? ''),
            'service_generation' => $identity['service_generation'] ?? null,
            'service_lease_id' => (string)($identity['service_lease_id'] ?? ''),
            'worker_pid' => $identity['worker_pid'] ?? null,
            'worker_process_birth' => (string)($identity['worker_process_birth'] ?? ''),
            'worker_pid_namespace_id' => (string)($identity['worker_pid_namespace_id'] ?? ''),
            'worker_launch_id' => (string)($identity['worker_launch_id'] ?? ''),
            'master_pid' => $identity['master_pid'] ?? null,
            'master_epoch' => $identity['master_epoch'] ?? null,
            'master_launch_id' => (string)($identity['master_launch_id'] ?? ''),
            'master_process_birth' => (string)($identity['master_process_birth'] ?? ''),
            'master_pid_namespace_id' => (string)($identity['master_pid_namespace_id'] ?? ''),
            'port' => $identity['port'] ?? null,
            'host_lease_instance' => (string)($identity['host_lease_instance'] ?? ''),
            'host_lease_id' => (string)($identity['host_lease_id'] ?? ''),
            'host_boot_id' => (string)($identity['host_boot_id'] ?? ''),
            'bind_host' => (string)($identity['bind_host'] ?? ''),
            'listener_proof_digest' => (string)($identity['listener_proof_digest'] ?? ''),
            'listener_transport' => (string)($identity['listener_transport'] ?? ''),
            'listener_receipt_digest' => (string)($identity['listener_receipt_digest'] ?? ''),
        ];
        $received = $identity;
        \ksort($received, SORT_STRING);
        $expected = $canonical;
        \ksort($expected, SORT_STRING);
        $workerNamespace = (string)$canonical['worker_pid_namespace_id'];
        $masterNamespace = (string)$canonical['master_pid_namespace_id'];
        $namespacesValid = PHP_OS_FAMILY === 'Linux'
            ? (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $workerNamespace) === 1
                && \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $masterNamespace) === 1)
            : ($workerNamespace === '' && $masterNamespace === '');
        $bindHost = (string)$canonical['bind_host'];
        $packedBindHost = @\inet_pton($bindHost);
        $normalisedBindHost = \is_string($packedBindHost)
            ? @\inet_ntop($packedBindHost)
            : false;
        if ($received !== $expected
            || !\hash_equals(
                self::GATEWAY_FALLBACK_LISTENER_PROTOCOL,
                (string)$canonical['schema'],
            )
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                (string)$canonical['project_uuid'],
            ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', (string)$canonical['wls_instance']) !== 1
            || !\hash_equals(self::ROLE_GATEWAY_FALLBACK, (string)$canonical['role'])
            || \preg_match('/\Agateway_fallback#[1-9][0-9]*\z/D', (string)$canonical['slot_id']) !== 1
            || !\is_int($canonical['service_generation'])
            || (int)$canonical['service_generation'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['service_lease_id']) !== 1
            || !\is_int($canonical['worker_pid'])
            || (int)$canonical['worker_pid'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['worker_process_birth']) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['worker_launch_id']) !== 1
            || !\is_int($canonical['master_pid'])
            || (int)$canonical['master_pid'] < 1
            || !\is_int($canonical['master_epoch'])
            || (int)$canonical['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['master_launch_id']) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['master_process_birth']) !== 1
            || !\is_int($canonical['port'])
            || (int)$canonical['port'] < 1
            || (int)$canonical['port'] > 65535
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', (string)$canonical['host_lease_instance']) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['host_lease_id']) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['host_boot_id']) !== 1
            || !\is_string($normalisedBindHost)
            || !\hash_equals(\strtolower($bindHost), \strtolower($normalisedBindHost))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['listener_proof_digest']) !== 1
            || !\in_array((string)$canonical['listener_transport'], [
                'posix_inherited_fd',
                'windows_wsaprotocol_info',
            ], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['listener_receipt_digest']) !== 1
            || !$namespacesValid
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener transition identity is invalid.'
            );
        }

        return $canonical;
    }

    /** @param array<string,mixed> $message */
    private static function requiredGatewayFallbackListenerString(
        array $message,
        string $field,
        bool $emptyAllowed = false,
    ): string {
        $value = $message[$field] ?? ($emptyAllowed ? '' : null);
        if (!\is_string($value)
            || \strlen($value) > 512
            || (!$emptyAllowed && $value === '')
        ) {
            throw new \InvalidArgumentException(
                'Gateway fallback listener field is invalid: ' . $field,
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $message @param list<string> $keys */
    private static function sameGatewayFallbackListenerKeys(array $message, array $keys): bool
    {
        $actual = \array_keys($message);
        \sort($actual, SORT_STRING);
        \sort($keys, SORT_STRING);
        return $actual === $keys;
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
    public static function drainingComplete(
        int $workerId,
        int $port,
        string $msgId = '',
        string $reason = '',
        array $drainReport = [],
    ): string {
        $data = [
            'type'      => self::TYPE_DRAINING_COMPLETE,
            'worker_id' => $workerId,
            'port'      => $port,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($reason !== '') {
            $data['reason'] = $reason;
        }
        if ($drainReport !== []) {
            $data['drain'] = $drainReport;
        }
        return self::encode($data);
    }

    /**
     * Master → Worker：整机内存压力档位。
     */
    public static function memoryPressure(string $level, int $staggerMsPerWorker = 0, array $extra = []): string
    {
        $data = [
            'type' => self::TYPE_MEMORY_PRESSURE,
            'level' => \strtolower(\trim($level)),
            'stagger_ms' => \max(0, $staggerMsPerWorker),
            'ts' => \time(),
        ];
        foreach ($extra as $key => $value) {
            if (!\is_string($key) || \preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $data[$key] = $value;
            }
        }

        return self::encode($data);
    }

    /**
     * Worker → Master：回收回执。
     */
    public static function memoryReclaimReport(int $reclaimBytes, string $hostLevel, array $extra = []): string
    {
        $data = [
            'type' => self::TYPE_MEMORY_RECLAIM_REPORT,
            'reclaim_bytes' => \max(0, $reclaimBytes),
            'host_level_applied' => $hostLevel,
            'ts' => \time(),
        ];
        foreach ($extra as $key => $value) {
            if (!\is_string($key) || \preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $data[$key] = $value;
            }
        }

        return self::encode($data);
    }

    /**
     * 构建 status_report 消息
     */
    public static function statusReport(int $connections, int $memory, int $requests, array $context = []): string
    {
        $data = [
            'type'        => self::TYPE_STATUS_REPORT,
            'connections' => $connections,
            'memory'      => $memory,
            'requests'    => $requests,
        ];

        foreach ($context as $key => $value) {
            if (!\is_string($key) || \preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (\in_array($key, ['type', 'connections', 'memory', 'requests'], true)) {
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $data[$key] = $value;
            }
        }

        return self::encode($data);
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
     * 构建普通请求批量遥测消息（Worker -> Master）。
     *
     * @param list<array<string, int|string>> $samples
     */
    public static function telemetryBatch(string $instance, array $samples): string
    {
        if (\count($samples) > 256) {
            throw new \InvalidArgumentException('Telemetry batch cannot contain more than 256 samples.');
        }
        $normalized = [];
        foreach ($samples as $sample) {
            if (!\is_array($sample)) {
                throw new \InvalidArgumentException('Telemetry batch samples must be arrays.');
            }
            $count = (int)($sample['request_count'] ?? 0);
            if ($count < 1 || $count > 4096) {
                throw new \InvalidArgumentException('Telemetry batch request_count is outside the supported range.');
            }
            $normalized[] = [
                'host' => \substr((string)($sample['host'] ?? 'unknown'), 0, 255),
                'bucket_ts' => (int)($sample['bucket_ts'] ?? \time()),
                'request_count' => $count,
                'error_count' => \max(0, \min($count, (int)($sample['error_count'] ?? 0))),
                'bytes_out' => \max(0, (int)($sample['bytes_out'] ?? 0)),
                'latency_total_ms' => \max(0, (int)($sample['latency_total_ms'] ?? 0)),
                'latency_max_ms' => \max(0, (int)($sample['latency_max_ms'] ?? 0)),
            ];
        }

        return self::encode([
            'type' => self::TYPE_TELEMETRY_BATCH,
            'instance' => \substr(\trim($instance) !== '' ? \trim($instance) : 'default', 0, 128),
            'samples' => $normalized,
            'ts' => \time(),
        ]);
    }

    /**
     * 构建 dispatcher_alert 消息
     *
     * @param string $instance 实例名
     * @param string $reason 告警原因
     * @param array $payload 附加上下文
     * @param string $subjectRole 需要 Master 优先自愈的角色
     * @param int $ts 事件时间戳
     */
    public static function dispatcherAlert(
        string $instance,
        string $reason,
        array $payload = [],
        string $subjectRole = '',
        int $ts = 0
    ): string {
        $data = [
            'type' => self::TYPE_DISPATCHER_ALERT,
            'instance' => $instance,
            'reason' => $reason,
            'ts' => $ts > 0 ? $ts : \time(),
        ];
        if ($subjectRole !== '') {
            $data['subject_role'] = $subjectRole;
        }
        foreach ($payload as $key => $value) {
            $data[$key] = $value;
        }

        return self::encode($data);
    }

    /**
     * 构建 command 消息
     *
     * @param string $action 动作
     * @param string $reloadType 重载类型（仅 reload 时用）
     * @param array $payload 可选载荷（如 security_unblock 时传 ip / clear_all）
     */
    public static function command(string $action, string $reloadType = '', array $payload = [], string $controlToken = ''): string
    {
        $data = [
            'type'   => self::TYPE_COMMAND,
            'action' => $action,
        ];
        if ($reloadType !== '') {
            $data['reload_type'] = $reloadType;
        }
        if ($controlToken !== '' && !isset($payload['control_token'])) {
            $data['control_token'] = $controlToken;
        }
        foreach ($payload as $k => $v) {
            $data[$k] = $v;
        }
        return self::encode($data);
    }

    /**
     * 构建 security_unblock 消息（Master → Worker/Dispatcher）
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

    public static function monotonicSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS monotonic clock is unavailable.');
        }

        return $now;
    }

    /**
     * 构建 ping 消息（Master → Worker/Dispatcher）。
     *
     * `timestamp` / `wall_timestamp` 仅作日志兼容与审计；健康判定只使用
     * 同一 host boot 下的 `monotonic_timestamp`。可选参数仅供可重现单测使用。
     */
    public static function ping(
        float $timestamp = 0.0,
        ?float $monotonicTimestamp = null,
        string $hostBootId = '',
    ): string {
        if (!\is_finite($timestamp) || $timestamp <= 0.0) {
            $timestamp = \microtime(true);
        }
        $monotonicTimestamp = self::normalizePositiveFiniteFloat(
            $monotonicTimestamp ?? self::monotonicSeconds(),
        );
        $hostBootId = self::normalizeHostBootId(
            $hostBootId !== '' ? $hostBootId : self::currentHostBootId(),
        ) ?? '';
        $data = [
            'type' => self::TYPE_PING,
            'timestamp' => $timestamp,
            'wall_timestamp' => $timestamp,
        ];
        if ($monotonicTimestamp !== null && $hostBootId !== '') {
            $data['monotonic_timestamp'] = $monotonicTimestamp;
            $data['host_boot_id'] = $hostBootId;
        }

        return self::encode($data);
    }

    /**
     * 构建 pong 消息（Worker/Dispatcher → Master）
     *
     * @param float $pingTimestamp 原始 ping 消息的时间戳
     * @param array $stats 可选的进程状态信息
     */
    public static function pong(float $pingTimestamp, array $stats = []): string
    {
        $data = [
            'type' => self::TYPE_PONG,
            'ping_timestamp' => $pingTimestamp,
            'pong_timestamp' => \microtime(true),
        ];
        if (!empty($stats)) {
            $data['stats'] = $stats;
        }
        return self::encode($data);
    }

    /**
     * 从完整 ping 信封构建可跨进程比较的 pong。
     *
     * 旧 ping 或不可比的字段仍会得到可解码的 legacy pong，但不会携带
     * monotonic 证据，Master 必须将其视为健康判定失败。
     *
     * @param array<string,mixed> $ping
     * @param array<string,mixed> $stats
     */
    public static function pongForPing(
        array $ping,
        array $stats = [],
        ?float $pongMonotonicTimestamp = null,
        string $pongHostBootId = '',
        ?float $pongWallTimestamp = null,
    ): string {
        $pingWallTimestamp = self::normalizePositiveFiniteFloat($ping['timestamp'] ?? null) ?? 0.0;
        $pongWallTimestamp = self::normalizePositiveFiniteFloat(
            $pongWallTimestamp ?? \microtime(true),
        ) ?? 0.0;
        $data = [
            'type' => self::TYPE_PONG,
            'ping_timestamp' => $pingWallTimestamp,
            'pong_timestamp' => $pongWallTimestamp,
        ];

        $pingMonotonic = self::normalizePositiveFiniteFloat($ping['monotonic_timestamp'] ?? null);
        $pingHostBootId = self::normalizeHostBootId($ping['host_boot_id'] ?? null);
        $pongMonotonic = self::normalizePositiveFiniteFloat(
            $pongMonotonicTimestamp ?? self::monotonicSeconds(),
        );
        $pongHostBootId = self::normalizeHostBootId(
            $pongHostBootId !== '' ? $pongHostBootId : self::currentHostBootId(),
        );
        if ($pingMonotonic !== null
            && $pingHostBootId !== null
            && $pongMonotonic !== null
            && $pongHostBootId !== null
        ) {
            $data['ping_monotonic'] = $pingMonotonic;
            $data['pong_monotonic'] = $pongMonotonic;
            $data['ping_host_boot_id'] = $pingHostBootId;
            $data['pong_host_boot_id'] = $pongHostBootId;
        }
        if ($stats !== []) {
            $data['stats'] = $stats;
        }

        return self::encode($data);
    }

    /**
     * @param array<string,mixed> $pong
     * @return array{ping_monotonic:float,pong_monotonic:float,received_monotonic:float,rtt_seconds:float,host_boot_id:string}|null
     */
    public static function monotonicPongObservation(
        array $pong,
        ?float $receivedMonotonic = null,
        string $currentHostBootId = '',
    ): ?array {
        if (($pong['type'] ?? null) !== self::TYPE_PONG) {
            return null;
        }
        $receivedMonotonic = self::normalizePositiveFiniteFloat(
            $receivedMonotonic ?? self::monotonicSeconds(),
        );
        $currentHostBootId = self::normalizeHostBootId(
            $currentHostBootId !== '' ? $currentHostBootId : self::currentHostBootId(),
        ) ?? '';
        $pingMonotonic = self::normalizePositiveFiniteFloat($pong['ping_monotonic'] ?? null);
        $pongMonotonic = self::normalizePositiveFiniteFloat($pong['pong_monotonic'] ?? null);
        $pingHostBootId = self::normalizeHostBootId($pong['ping_host_boot_id'] ?? null);
        $pongHostBootId = self::normalizeHostBootId($pong['pong_host_boot_id'] ?? null);
        if ($receivedMonotonic === null
            || $currentHostBootId === ''
            || $pingMonotonic === null
            || $pongMonotonic === null
            || $pingHostBootId === null
            || $pongHostBootId === null
            || !\hash_equals($currentHostBootId, $pingHostBootId)
            || !\hash_equals($currentHostBootId, $pongHostBootId)
            || $pongMonotonic < $pingMonotonic
            || $pingMonotonic > $receivedMonotonic
            || $pongMonotonic > $receivedMonotonic
        ) {
            return null;
        }

        return [
            'ping_monotonic' => $pingMonotonic,
            'pong_monotonic' => $pongMonotonic,
            'received_monotonic' => $receivedMonotonic,
            'rtt_seconds' => $pongMonotonic - $pingMonotonic,
            'host_boot_id' => $currentHostBootId,
        ];
    }

    private static function normalizePositiveFiniteFloat(mixed $value): ?float
    {
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = (float)$value;

        return \is_finite($value) && $value > 0.0 ? $value : null;
    }

    private static function normalizeHostBootId(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        try {
            return GatewayHostBootIdentity::validate($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function currentHostBootId(): string
    {
        try {
            return GatewayHostBootIdentity::current();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 构建 command_result 消息
     */
    public static function commandResult(bool $success, array $data = [], string $message = '', string $msgId = ''): string
    {
        $payload = [
            'type'    => self::TYPE_COMMAND_RESULT,
            'success' => $success,
            'data'    => $data,
            'message' => $message,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        return self::encode($payload);
    }

    /**
     * 构建 exit_reason 消息（退出前发送，Master 记录用于决策和排查）
     *
     * @param string $reason 退出原因
     * @param int $code 可选退出码
     */
    public static function exitReason(string $reason, int $code = 0, array $context = []): string
    {
        $data = ['type' => self::TYPE_EXIT_REASON, 'reason' => $reason];
        if ($code !== 0) {
            $data['code'] = $code;
        }
        foreach ($context as $key => $value) {
            if (!\is_string($key) || \preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (\in_array($key, ['type', 'reason', 'code'], true)) {
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $data[$key] = $value;
            }
        }
        return self::encode($data);
    }

    /**
     * 构建 exited 消息（子进程退出前发送）
     */
    public static function exited(string $role, int $pid, int $port = 0, int $workerId = 0, string $msgId = ''): string
    {
        $data = [
            'type'      => self::TYPE_EXITED,
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        return self::encode($data);
    }

    /**
     * 构建 ack_ready 消息（Master → Worker：确认收到 ready）
     *
     * @param int $workerId Worker ID
     * @return string NDJSON 消息
     */
    public static function ackReady(
        int $workerId,
        bool $dispatcherConfirmed = false,
        int $port = 0,
        string $msgId = '',
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0,
        string $readyPhase = 'final',
        array $http3Route = [],
    ): string
    {
        $data = [
            'type'      => self::TYPE_ACK_READY,
            'worker_id' => $workerId,
            'dispatcher_confirmed' => $dispatcherConfirmed,
            'ready_phase' => $readyPhase !== '' ? $readyPhase : 'final',
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($port > 0) {
            $data['port'] = $port;
        }
        self::appendLeaseIdentity($data, $slotId, $leaseId, $generation);
        if ($http3Route !== []) {
            $data['http3_route'] = $http3Route;
        }
        return self::encode($data);
    }

    public static function leaseAssign(string $leaseId, int $generation, string $role, int $workerId = 0, int $port = 0, string $msgId = ''): string
    {
        $data = [
            'type' => self::TYPE_LEASE_ASSIGN,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'role' => $role,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($workerId > 0) {
            $data['worker_id'] = $workerId;
        }
        if ($port > 0) {
            $data['port'] = $port;
        }
        return self::encode($data);
    }

    public static function readyAck(
        string $leaseId,
        int $generation,
        bool $accepted = true,
        string $reason = '',
        int $workerId = 0,
        int $port = 0,
        string $msgId = '',
        string $slotId = '',
        string $readyPhase = 'final',
        string $activationId = '',
    ): string
    {
        $data = [
            'type' => self::TYPE_READY_ACK,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'accepted' => $accepted,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($reason !== '') {
            $data['reason'] = $reason;
        }
        if ($workerId > 0) {
            $data['worker_id'] = $workerId;
        }
        if ($port > 0) {
            $data['port'] = $port;
        }
        if ($slotId !== '') {
            $data['slot_id'] = $slotId;
        }
        $data['ready_phase'] = $readyPhase !== '' ? $readyPhase : 'final';
        if ($activationId !== '') {
            $data['activation_id'] = $activationId;
        }
        return self::encode($data);
    }

    public static function commandAccept(string $msgId, string $command, string $leaseId = '', int $generation = 0, string $phase = ''): string
    {
        $data = [
            'type' => self::TYPE_COMMAND_ACCEPT,
            'msg_id' => $msgId,
            'command' => $command,
            'lease_id' => $leaseId,
            'generation' => $generation,
        ];
        if ($phase !== '') {
            $data['phase'] = $phase;
        }

        return self::encode($data);
    }

    public static function commandDone(string $msgId, string $command, bool $success, string $message = '', array $data = [], string $phase = ''): string
    {
        $payload = [
            'type' => self::TYPE_COMMAND_DONE,
            'msg_id' => $msgId,
            'command' => $command,
            'success' => $success,
        ];
        if ($phase !== '') {
            $payload['phase'] = $phase;
        }
        if ($message !== '') {
            $payload['message'] = $message;
        }
        if ($data !== []) {
            $payload['data'] = $data;
        }
        return self::encode($payload);
    }

    public static function heartbeat(string $leaseId, int $seq, int $generation = 0, string $msgId = '', string $slotId = ''): string
    {
        $data = [
            'type' => self::TYPE_HEARTBEAT,
            'lease_id' => $leaseId,
            'seq' => $seq,
            'generation' => $generation,
            'timestamp' => \time(),
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($slotId !== '') {
            $data['slot_id'] = $slotId;
        }

        return self::encode($data);
    }

    /**
     * 构建 worker_pool_ack 消息（Dispatcher → Master：告知 Worker 是否已在池内）
     */
    public static function workerPoolAck(
        int $port,
        bool $inPool,
        string $role = self::ROLE_WORKER,
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0,
        string $msgId = '',
        string $reason = '',
        bool $retrying = false
    ): string
    {
        $data = [
            'type' => self::TYPE_WORKER_POOL_ACK,
            'port' => $port,
            'role' => $role,
            'in_pool' => $inPool,
        ];
        if ($msgId !== '') {
            $data['msg_id'] = $msgId;
        }
        if ($reason !== '') {
            $data['reason'] = $reason;
        }
        if ($retrying) {
            $data['retrying'] = true;
        }
        self::appendLeaseIdentity($data, $slotId, $leaseId, $generation);
        return self::encode($data);
    }

    /**
     * 构建 route_table_ack 消息（B-i 阶段引入）。
     *
     * Dispatcher → Master：确认已接收并应用（或忽略）某个版本的路由表。
     *
     * @param string $status  applied | duplicate | rejected
     * @param string $reason  当 status != applied 时的简要原因（便于排障，避免增加新消息类型）
     */
    public static function routeTableAck(
        int $routeVersion,
        string $checksum,
        string $status = 'applied',
        string $role = self::ROLE_WORKER,
        int $epoch = 0,
        string $reason = '',
        string $traceId = ''
    ): string
    {
        $data = [
            'type'          => self::TYPE_ROUTE_TABLE_ACK,
            'role'          => $role,
            'route_version' => $routeVersion,
            'checksum'      => $checksum,
            'status'        => $status,
        ];
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        if ($reason !== '') {
            $data['reason'] = $reason;
        }
        self::appendTraceId($data, $traceId);
        return self::encode($data);
    }

    public static function http3Availability(
        int $availabilityEpoch,
        bool $enabled,
        int $port,
        int $ownerEpoch,
        int $routeEpoch,
        string $nativeDigest,
    ): string {
        return self::encode([
            'type' => self::TYPE_HTTP3_AVAILABILITY,
            'availability_epoch' => $availabilityEpoch,
            'enabled' => $enabled,
            'port' => $enabled ? $port : 0,
            'owner_epoch' => $ownerEpoch,
            'route_epoch' => $routeEpoch,
            'native_digest' => \strtolower(\trim($nativeDigest)),
        ]);
    }

    /**
     * 构建 route_observation 消息（B-i 阶段引入：仅观测，不联动 Worker 生死）。
     *
     * 子进程 → Master：上报自身观察到的身份/路由偏差，例如：
     * - 进程被分配的 slot/lease/generation 与版本化路由表不一致；
     * - Dispatcher 出现路由命中率异常等（B-ii 后再补充语义）。
     *
     * 字段约定（最小集，所有字段都可选，缺省时不写入）：
     *   role / slot_id / lease_id / generation / port / event / detail
     */
    public static function routeObservation(
        string $event,
        string $role = self::ROLE_WORKER,
        string $slotId = '',
        string $leaseId = '',
        int $generation = 0,
        int $port = 0,
        string $detail = '',
        string $traceId = ''
    ): string
    {
        $data = [
            'type'  => self::TYPE_ROUTE_OBSERVATION,
            'role'  => $role,
            'event' => $event,
        ];
        if ($port > 0) {
            $data['port'] = $port;
        }
        if ($detail !== '') {
            $data['detail'] = $detail;
        }
        self::appendLeaseIdentity($data, $slotId, $leaseId, $generation);
        self::appendTraceId($data, $traceId);
        return self::encode($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function appendLeaseIdentity(array &$data, string $slotId, string $leaseId, int $generation): void
    {
        if ($slotId !== '') {
            $data['slot_id'] = $slotId;
        }
        if ($leaseId !== '') {
            $data['lease_id'] = $leaseId;
        }
        if ($generation > 0) {
            $data['generation'] = $generation;
        }
    }

    /**
     * 把 traceId 写入消息 payload。
     */
    private static function appendTraceId(array &$data, string $traceId): void
    {
        if ($traceId !== '') {
            $data['trace_id'] = $traceId;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeWorkerDescriptors(array $workers, string $defaultRole = self::ROLE_WORKER): array
    {
        $normalized = [];
        foreach ($workers as $worker) {
            if (!\is_array($worker)) {
                continue;
            }
            $port = (int)($worker['port'] ?? 0);
            if ($port <= 0) {
                continue;
            }
            $role = (string)($worker['role'] ?? $defaultRole);
            $slotId = (string)($worker['slot_id'] ?? '');
            $leaseId = (string)($worker['lease_id'] ?? '');
            $generation = (int)($worker['generation'] ?? 0);
            $state = (string)($worker['state'] ?? 'ready');
            $normalized[] = [
                'role' => $role !== '' ? $role : $defaultRole,
                'slot_id' => $slotId,
                'lease_id' => $leaseId,
                'generation' => $generation,
                'port' => $port,
                'state' => $state !== '' ? $state : 'ready',
            ];
        }

        return $normalized;
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
