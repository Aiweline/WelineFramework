<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * WLS 超时分层中心（P1-7）
 *
 * 问题背景：
 *   过去 40+ 处 private const 的超时/心跳/重试常量散落在 Dispatcher、PassthroughCore、
 *   IpcControlGateway、WorkerScaler、ServiceOrchestrator、Stop/Reload 等十余个文件。
 *   单独看每一处都讲得通，但跨文件缺乏协调：
 *     - Worker 连接超时大于 Dispatcher 主循环自旋预算 → 主循环被阻塞
 *     - Master 心跳宽限小于 pid 探测周期 × 阈值 → 误报 Master 死亡
 *     - 控制命令 connect 超时小于 read 超时 → 连接就绪前就超时
 *   这类隐患只有在真实负载偶发串起来时才暴露，事后复盘很难追踪。
 *
 * 本类职责：
 *   1) 集中登记**跨层协调**意义上的公共默认值（不重复搬迁纯局部常量）；
 *   2) 提供 assertInvariants() 做启动期/测试期不变量自检；
 *   3) 为未来组件 opt-in 复用同一套默认值，让调参集中化。
 *
 * **刻意非目标**：
 *   - 不强制替换已有 private const —— 迁移留待增量 PR；
 *   - 不引入可变的 "运行时可调" 超时，保持常量语义；
 *   - 不规避组件自身的 configure() 能力，各组件仍可按需覆盖。
 */
final class Timeouts
{
    // ==================== 客户端路径（Dispatcher ↔ Worker） ====================

    /** TCP keepalive 间隔（秒），与 OS 内核 keepalive 时钟对齐 */
    public const DISPATCHER_CLIENT_KEEPALIVE_INTERVAL_SEC = 8;

    /** connectToWorker 的 socket_select 默认等待时间（秒）—— 主循环最坏一次 tick 延迟 */
    public const WORKER_CONNECT_SELECT_DEFAULT_SEC = 0.1;

    /** worker_connect_select_timeout_sec 配置下限（秒），保护被调参调成 0 */
    public const WORKER_CONNECT_SELECT_MIN_SEC = 0.01;

    /** worker_connect_select_timeout_sec 配置上限（秒），避免长时间阻塞主循环 */
    public const WORKER_CONNECT_SELECT_MAX_SEC = 2.0;

    // ==================== 主循环保护（P0-1 派遣器去阻塞化） ====================

    /** handleNewConnection 同步自旋预算上限（秒）—— 超过这个阈值应转异步 */
    public const HANDLE_NEW_CONNECTION_SPIN_BUDGET_SEC = 0.8;

    /** SSL 启动自旋下限（秒）—— 防止误把 TLS handshake 当作失败 */
    public const MIN_SSL_STARTUP_SPIN_WAIT_SEC = 3.0;

    // ==================== 维护页可靠呈现（P0-5 / P1-4 / P1-5） ====================

    /** healthy==0 且 total>0 需持续多长时间才切回维护页回退（秒） */
    public const HEALTHY_ZERO_MAINTENANCE_THRESHOLD_SEC = 2.0;

    /** 维护页待写队列等首字节的最长时间（秒） */
    public const PENDING_MAINTENANCE_WAIT_TIMEOUT_SEC = 2.0;

    // ==================== Master 心跳（P1-1 / P1-2） ====================

    /** Dispatcher 探测 Master 心跳的周期（秒）—— 与 IPC 连接状态读取保持对齐 */
    public const MASTER_PID_CHECK_INTERVAL_SEC = 5;

    /** 连续多少次探测失败才开始计时进入 dead 宽限 */
    public const MASTER_MISSING_COUNT_THRESHOLD = 1;

    /** 进入 "missing" 态后持续多久才判定 Master 真正死亡（秒） */
    public const MASTER_MISSING_GRACE_SEC = 25.0;

    // ==================== 控制平面（IpcControlGateway） ====================

    /** 控制端口 stream_socket_client 连接超时下限（秒）—— Windows 高负载下建议不低于此值 */
    public const CONTROL_MIN_CONNECT_TIMEOUT_SEC = 12.0;

    /** 控制端口 connect 失败重试次数 */
    public const CONTROL_CONNECT_ATTEMPTS = 3;

    /** 控制端口 connect 失败重试间隔（微秒） */
    public const CONTROL_CONNECT_RETRY_USEC = 350_000;

    /** 默认命令读 ACK 超时（秒），reloadAsync/cacheClear 等通用异步命令默认值 */
    public const CONTROL_CMD_DEFAULT_READ_SEC = 5.0;

    /** getStatus 读 ACK 超时（秒） */
    public const CONTROL_CMD_STATUS_READ_SEC = 4.0;

    /** 维护模式切换读 ACK 超时（秒） */
    public const CONTROL_CMD_MAINT_READ_SEC = 6.0;

    // ==================== 扩缩容（WorkerScaler） ====================

    /** Worker 启动 READY 超时（秒），共享窗口用于并发启动 */
    public const WORKER_START_TIMEOUT_SEC = 10;

    /** Worker 优雅退出超时（秒），共享窗口用于并发缩容 */
    public const WORKER_STOP_TIMEOUT_SEC = 30;

    // ==================== 停机编排（Stop.php） ====================

    public const STOP_LOCK_TIMEOUT_SEC = 5;

    public const STOP_IPC_REGULAR_SEC = 15;

    public const STOP_IPC_FORCE_SEC = 3;

    public const STOP_MASTER_EXIT_WIN_SEC = 5;

    public const STOP_MASTER_EXIT_LINUX_SEC = 15;

    public const STOP_IPC_HARD_WIN_SEC = 45;

    public const STOP_IPC_HARD_LINUX_SEC = 30;

    // ==================== 观测 / 维护 ====================

    public const DEFAULT_STATUS_TIMEOUT_SEC = 2.0;

    public const REQUEST_FIBER_STATUS_TIMEOUT_SEC = 0.05;

    /** 禁止实例化 */
    private function __construct()
    {
    }

    /**
     * 跨层不变量自检。
     *
     * 这些关系必须在任何组件调参时保持：违反意味着某个上/下层的时间窗互相打架。
     *
     * @throws \LogicException 违反任一不变量
     */
    public static function assertInvariants(): void
    {
        // 1. Worker 连接 select 默认值必须落在配置边界内
        if (self::WORKER_CONNECT_SELECT_DEFAULT_SEC < self::WORKER_CONNECT_SELECT_MIN_SEC
            || self::WORKER_CONNECT_SELECT_DEFAULT_SEC > self::WORKER_CONNECT_SELECT_MAX_SEC) {
            throw new \LogicException(
                'WORKER_CONNECT_SELECT_DEFAULT_SEC must stay within [MIN, MAX]'
            );
        }

        // 2. Worker 连接 select 默认值必须 <= 主循环自旋预算，否则单次 tick 就会吃完预算
        if (self::WORKER_CONNECT_SELECT_DEFAULT_SEC > self::HANDLE_NEW_CONNECTION_SPIN_BUDGET_SEC) {
            throw new \LogicException(
                'WORKER_CONNECT_SELECT_DEFAULT_SEC must not exceed HANDLE_NEW_CONNECTION_SPIN_BUDGET_SEC'
            );
        }

        // 3. Master 失联宽限必须显著长于一个探测周期，否则偶发 tasklist 抖动就判死
        $minGrace = self::MASTER_PID_CHECK_INTERVAL_SEC
            * \max(self::MASTER_MISSING_COUNT_THRESHOLD, 3);
        if (self::MASTER_MISSING_GRACE_SEC < $minGrace) {
            throw new \LogicException(
                'MASTER_MISSING_GRACE_SEC should be >= interval × max(threshold,3)'
            );
        }

        // 4. 控制端口连接超时必须 >= 默认读超时，保证连接就绪后才进入读取阶段
        if (self::CONTROL_MIN_CONNECT_TIMEOUT_SEC < self::CONTROL_CMD_DEFAULT_READ_SEC) {
            throw new \LogicException(
                'CONTROL_MIN_CONNECT_TIMEOUT_SEC should be >= CONTROL_CMD_DEFAULT_READ_SEC'
            );
        }

        // 5. Windows 的硬超时必须不短于 Linux，因为 Windows 进程回收普遍更慢
        if (self::STOP_IPC_HARD_WIN_SEC < self::STOP_IPC_HARD_LINUX_SEC) {
            throw new \LogicException(
                'STOP_IPC_HARD_WIN_SEC should be >= STOP_IPC_HARD_LINUX_SEC'
            );
        }

        // 6. 维护页等首字节超时不得超过 healthy_zero 阈值的 2 倍（否则客户端一边等维护页，一边系统才判定真正该走维护态）
        if (self::PENDING_MAINTENANCE_WAIT_TIMEOUT_SEC > self::HEALTHY_ZERO_MAINTENANCE_THRESHOLD_SEC * 2) {
            throw new \LogicException(
                'PENDING_MAINTENANCE_WAIT_TIMEOUT_SEC too long relative to HEALTHY_ZERO_MAINTENANCE_THRESHOLD_SEC'
            );
        }

        // 7. Worker 停止超时必须 >= 启动超时，缩容耗时不得短于扩容耗时（避免旧 Worker 未退而新 Worker 未起的窗口期）
        if (self::WORKER_STOP_TIMEOUT_SEC < self::WORKER_START_TIMEOUT_SEC) {
            throw new \LogicException(
                'WORKER_STOP_TIMEOUT_SEC should be >= WORKER_START_TIMEOUT_SEC'
            );
        }
    }
}
