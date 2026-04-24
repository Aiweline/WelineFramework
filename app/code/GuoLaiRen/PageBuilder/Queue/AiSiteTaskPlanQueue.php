<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSiteTaskPlanQueue implements QueueInterface
{
    public function name(): string
    {
        return 'PageBuilder AI 第二阶段任务方案生成队列';
    }

    public function tip(): string
    {
        return '异步执行 PageBuilder 第二阶段任务方案 AI 生成任务，并通过 SSE 同步阶段二进度。';
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content)) {
            return false;
        }

        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            return false;
        }

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $session = $sessionService->loadByPublicId($publicId, $adminId);

        return $session instanceof AiSiteAgentSession;
    }

    public function execute(Queue &$queue): string
    {
        $content = \json_decode((string)$queue->getContent(), true);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $forceRebuild = (int)($content['_force_rebuild'] ?? 0) === 1;
        $effectiveExecutionToken = $executionToken;
        if ($forceRebuild) {
            $effectiveExecutionToken = \sprintf(
                '%s-force-%s',
                $executionToken !== '' ? $executionToken : 'queue',
                \substr(\sha1((string)\microtime(true) . ':' . (string)\mt_rand()), 0, 10)
            );
        }
        $queueId = (int)$queue->getId();

        $sse = null;
        $previousSseContextExists = false;
        $previousSseContext = null;
        $sseContextRegistered = false;
        try {
            $this->appendQueueLifecycleLine($queue, '开始执行 queue_id=' . $queueId . ' public_id=' . $publicId . ' admin_id=' . $adminId);

            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('会话不存在或无权访问。');
            }
            $this->appendQueueLifecycleLine($queue, '已加载会话 session_id=' . (int)$session->getId());
            if ($forceRebuild) {
                $session = $this->applyForceTaskPlanRebuildPreset($sessionService, $scopeService, $session, $adminId);
                $this->appendQueueLifecycleLine($queue, '检测到 _force_rebuild=1，已切换为 rebuild_task_plan 强制重建，execution_token=' . \substr($effectiveExecutionToken, 0, 20) . '…');
            }

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                'task_plan',
                $effectiveExecutionToken
            );
            $this->appendQueueLifecycleLine($queue, '已同步 active_operation=queued operation=task_plan execution_token=' . \substr($effectiveExecutionToken, 0, 12) . '…');

            $scope = $scopeService->normalizeScope($session->getScopeArray());
            $normalizedScope = $scopeService->normalizeConfirmedPlanFlag($scope);
            $scopePatch = [];
            if ((int)($normalizedScope['plan_confirmed'] ?? 0) !== (int)($scope['plan_confirmed'] ?? 0)
            ) {
                $scopePatch['plan_confirmed'] = (int)($normalizedScope['plan_confirmed'] ?? 0);
            }
            if ((string)($normalizedScope['plan_confirmed_at'] ?? '') !== (string)($scope['plan_confirmed_at'] ?? '')) {
                $scopePatch['plan_confirmed_at'] = (string)($normalizedScope['plan_confirmed_at'] ?? '');
            }
            if ($scopePatch !== []) {
                $sessionService->mergeScope((int)$session->getId(), $adminId, $scopePatch);
            }
            $scope = $normalizedScope;
            if (!$scopeService->hasConfirmedStageOnePlanForTaskPlan($scope)) {
                throw new \RuntimeException('请先确认第一阶段方案，再生成第二阶段任务方案。');
            }
            $this->appendQueueLifecycleLine($queue, '已校验 plan_confirmed=1');

            $sse = new QueueDbWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'task_plan'
            );
            $previousSseContextExists = RequestContext::has(RequestContext::SSE_WRITER_KEY);
            $previousSseContext = RequestContext::get(RequestContext::SSE_WRITER_KEY);
            RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
            $sseContextRegistered = true;
            $this->queueTrace($sse, 'QueueDbWriter 已创建，后续步骤将写入队列 result 与会话事件');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, 'task_plan']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, '认领跳过：duplicate_stream（重复第二阶段生成）');

                    return '检测到重复第二阶段生成任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }
            $this->queueTrace($sse, '认领成功 claimActiveOperationExecution ok，进入 runTaskPlanOperation');

            $this->invokePrivate($controller, 'runTaskPlanOperation', [$sse, $session, $adminId]);
            $this->queueTrace($sse, 'runTaskPlanOperation 已返回');

            $this->ensureTaskPlanDraftPersisted($sessionService, $scopeService, $session, $adminId, $sse);
            $this->queueTrace($sse, 'ensureTaskPlanDraftPersisted 已完成（草案已就绪或已补全）');

            $this->queueTrace($sse, '队列执行成功：第二阶段任务方案生成完成');

            return '第二阶段任务方案生成完成。';
        } catch (\Throwable $throwable) {
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '异常：' . $throwable->getMessage());
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（SSE 未初始化）：' . $throwable->getMessage());
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $throwable->getMessage());
            throw new \RuntimeException('第二阶段任务方案生成失败：' . $throwable->getMessage(), 0, $throwable);
        } finally {
            if ($sseContextRegistered) {
                if ($previousSseContextExists) {
                    RequestContext::set(RequestContext::SSE_WRITER_KEY, $previousSseContext);
                } else {
                    RequestContext::remove(RequestContext::SSE_WRITER_KEY);
                }
            }
            if ($sse instanceof QueueDbWriter) {
                $sse->complete();
            }
        }
    }

    private function updateSessionError(string $publicId, int $adminId, string $executionToken, string $message): void
    {
        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                return;
            }

            $scope = $scopeService->normalizeScope($session->getScopeArray());
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $active['status'] = 'error';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        } catch (\Throwable) {
        }
    }

    private function ensureQueuedActiveOperation(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        int $queueId,
        string $operation,
        string $executionToken
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope($fresh->getScopeArray());
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (
            (string)($active['operation'] ?? '') === $operation
            && (string)($active['execution_token'] ?? '') === $executionToken
        ) {
            return $fresh;
        }

        $scope['active_operation'] = \array_replace($active, [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'queued',
            'queue_id' => $queueId,
            'message' => '等待开始',
            'started_at' => (string)($active['started_at'] ?? \date('Y-m-d H:i:s')),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $scope['active_operation'];
        $scope['active_operations'] = $activeOperations;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    private function ensureTaskPlanDraftPersisted(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        ?QueueDbWriter $sse = null
    ): void {
        if ($sse instanceof QueueDbWriter) {
            $this->queueTrace($sse, '开始校验任务方案草案是否已落库');
        }
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope($fresh->getScopeArray());
        $draft = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
        $draftMarkdown = \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? ''));
        $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
        $taskPlanGeneratedAt = \trim((string)($scope['task_plan_generated_at'] ?? ''));
        $needsRepair = false;

        if ($taskPlanStructured === [] && $draft !== []) {
            $taskPlanStructured = $draft;
            $needsRepair = true;
        }
        if ($taskPlanMarkdown === '' && $draftMarkdown !== '') {
            $taskPlanMarkdown = $draftMarkdown;
            $needsRepair = true;
        }
        if ($taskPlanGeneratedAt === '' && $draftMarkdown !== '') {
            $taskPlanGeneratedAt = \date('Y-m-d H:i:s');
            $needsRepair = true;
        }

        if ($needsRepair) {
            $summary = \is_array($scope['task_plan_summary'] ?? null) ? $scope['task_plan_summary'] : [];
            if (!isset($summary['signature']) || \trim((string)$summary['signature']) === '') {
                $summary['signature'] = (string)($draft['signature'] ?? $scope['virtual_theme_plan']['plan_signature'] ?? '');
            }
            if (!isset($summary['source']) || \trim((string)$summary['source']) === '') {
                $summary['source'] = 'task_plan_queue_repair';
            }
            if (!isset($summary['generation_source']) || \trim((string)$summary['generation_source']) === '') {
                $summary['generation_source'] = 'ai';
            }
            if (!isset($summary['shared_task_count'])) {
                $summary['shared_task_count'] = \count(\is_array($taskPlanStructured['shared_tasks'] ?? null) ? $taskPlanStructured['shared_tasks'] : []);
            }
            if (!isset($summary['page_task_count'])) {
                $summary['page_task_count'] = \array_sum(\array_map(
                    static fn($items): int => \is_array($items) ? \count($items) : 0,
                    \is_array($taskPlanStructured['page_tasks'] ?? null) ? $taskPlanStructured['page_tasks'] : []
                ));
            }

            $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
                'task_plan_structured' => $taskPlanStructured,
                'task_plan_markdown' => $taskPlanMarkdown,
                'task_plan_generated_at' => $taskPlanGeneratedAt,
                'task_plan_summary' => $summary,
            ]);

            $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $scope = $scopeService->normalizeScope($fresh->getScopeArray());
            $draft = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
            $draftMarkdown = \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? ''));
            $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
            $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '已回填 task_plan_* 会话字段（structured/markdown/generated_at/summary）');
            }
        }

        if ($draft !== [] && $draftMarkdown !== '') {
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '草案校验通过：virtual_theme_plan.draft + draft_markdown 均存在');
            }
            return;
        }

        $hint = 'runTaskPlanOperation 已返回但草案字段缺失：'
            . 'draft=' . ($draft === [] ? 'empty' : 'ok')
            . ', draft_markdown=' . ($draftMarkdown === '' ? 'empty' : 'ok')
            . ', task_plan_markdown=' . ($taskPlanMarkdown === '' ? 'empty' : 'ok')
            . ', task_plan_structured=' . ($taskPlanStructured === [] ? 'empty' : 'ok');
        if ($sse instanceof QueueDbWriter) {
            $this->queueTrace($sse, '草案校验失败：' . $hint);
        }
        $queueHint = ($sse instanceof QueueDbWriter && $sse->getQueueId() > 0)
            ? 'queue:run --id=' . $sse->getQueueId() . ' -f'
            : 'queue:run --id=<队列ID> -f';
        throw new \RuntimeException(
            '任务方案草案校验失败，未检测到完整 draft 落库；请重试 '
            . $queueHint
            . '，或检查 AiSiteAgent::runTaskPlanOperation 写入链路。（--id 为 weline_queue 主键，非 session_id）('
            . $hint
            . ')'
        );
    }

    private function applyForceTaskPlanRebuildPreset(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope($fresh->getScopeArray());
        $currentReq = \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [];
        $nextRound = \max(1, (int)($currentReq['round'] ?? 0) + 1);

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
            '_task_plan_sse_request' => [
                'prompt_mode' => 'rebuild_task_plan',
                'instruction' => '[FORCE] queue:run -f 强制重建第二阶段任务方案',
                'target_scope' => 'full_task_plan',
                'round' => $nextRound,
                'forced_by_queue_run' => 1,
            ],
            '_task_plan_rebuild_in_progress' => 1,
            'build_blueprint' => [],
            'build_tasks' => [],
            'task_plan_confirmed' => 0,
        ]);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /**
     * 在尚未创建 QueueDbWriter 时，直接追加到队列表 result/process（与 QueueDbWriter::appendQueueLog 同源可读）。
     */
    private function appendQueueLifecycleLine(Queue &$queue, string $message): void
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0 || $message === '') {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE ' . $message;
        $existing = (string)($row['result'] ?? '');
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'process' => $message,
                'result' => $existing === '' ? $line : $existing . PHP_EOL . $line,
            ],
        ]);
        $this->mirrorToCli($line);
    }

    /**
     * 队列内可见过程：写入 weline_queue.result + process，并同步会话事件（operation-sse 可轮询）。
     */
    private function queueTrace(QueueDbWriter $sse, string $message): void
    {
        if ($message === '') {
            return;
        }

        $sse->sendEvent('log', [
            'message' => $message,
            'event_type' => 'queue_lifecycle',
            'level' => 'info',
        ]);
        $this->mirrorToCli('[' . \date('H:i:s') . '] LOG ' . $message);
    }

    private function mirrorToCli(string $line): void
    {
        if ($line === '' || \PHP_SAPI !== 'cli') {
            return;
        }

        echo $line . \PHP_EOL;
        if (\function_exists('flush')) {
            \flush();
        }
    }
}
