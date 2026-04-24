<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteAgentTaskPlanQueueRecoveryPorts
 *
 * `AiSiteAgentTaskPlanQueueRecoveryService` 的 **端口集合**（Ports Bag），
 * 将 `AiSiteAgent.php` 控制器原 `autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing`
 * 方法所需的 11 个协作依赖（9 个 helper/service + `w_query` 全局副作用 + 日志）
 * 统一封装为不可变 value object，以 Closure 形式注入。
 *
 * 为什么采用 Ports Bag 而非 11 个独立参数：
 *  - 服务方法签名可以保持 6 个语义参数 + 1 个 Ports（共 7 参），不至于 17 参不可读；
 *  - 每个字段就是调用方"愿意交出的能力"，命名即契约，便于 IDE 导航与 code review；
 *  - 控制器侧只需在构造时一次性打包 Closure，调用点极薄；
 *  - 测试侧可用 `AiSiteAgentTaskPlanQueueRecoveryPorts::forTest(...)` 构造 StubPorts 并精确记录调用轨迹。
 *
 * **约束**：
 *  - 所有字段都是 `\Closure`；传 null 或未定义会触发 `TypeError` —— 强制调用方提供。
 *  - 本类不应增加领域逻辑；所有行为都由承载的 Closure 决定，目的仅是**打包传递**。
 *  - 若未来某个 Port 回收为一等 Service（如统一 QueueWriterPort），则把对应字段改为 typed 属性、
 *    保留同名 getter 即可，向调用方透明。
 */
final class AiSiteAgentTaskPlanQueueRecoveryPorts
{
    public function __construct(
        /** @var \Closure(array $normalized): bool 判定 `task_plan.draft` 是否缺失 */
        public readonly \Closure $isTaskPlanDraftMissing,
        /** @var \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession $session, string $operation, int $queueId=0): ?array 查询 queue row（可指定 queueId 走唯一回溯） */
        public readonly \Closure $findQueueRow,
        /** @var \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession $session, int $adminId, string $operation, string $executionToken, array $extras): int 入队新任务，返回 queue_id（<=0 表示失败） */
        public readonly \Closure $enqueueTask,
        /** @var \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession $session, string $operation, string $executionToken, string $status): array 构造 operation envelope */
        public readonly \Closure $buildOperationEnvelope,
        /** @var \Closure(int $sessionId, int $adminId, array $patch): void 把 `active_operation` 等字段 merge 回 session scope */
        public readonly \Closure $mergeSessionScope,
        /** @var \Closure(int $sessionId, int $adminId): ?\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession 根据 id 重载 session */
        public readonly \Closure $loadSession,
        /** @var \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession $session, int $adminId, string $operation, int $queueId, string $executionToken, bool $force): array ensureAiSiteQueueWorkerDispatched 返回 `{started, attempted, pid, ...}` */
        public readonly \Closure $ensureWorkerDispatched,
        /** @var \Closure(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession $session, array $activeOperation, string $operation): array 构建 stage queue info payload（含 snapshot） */
        public readonly \Closure $buildQueueInfoPayload,
        /** @var \Closure(string $event, array $data, string $level='info'): void 记录 operation SSE 日志 */
        public readonly \Closure $logSse,
        /** @var \Closure(string $operation): string operation→stage 映射 */
        public readonly \Closure $resolveQueueStage,
        /** @var \Closure(int $queueId, array $patch): void 通过 w_query('queue','update',...) 打补丁 */
        public readonly \Closure $updateQueueRow,
    ) {
    }
}
