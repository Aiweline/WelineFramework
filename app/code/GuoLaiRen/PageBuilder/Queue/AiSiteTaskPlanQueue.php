<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use Weline\Ai\Service\AiRuntimeContext;
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
        $previousAiRuntimeParamsExists = false;
        $previousAiRuntimeParams = [];
        $aiRuntimeParamsRegistered = false;
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
            $queuedScope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $hasQueuedTaskPlanMutation = $this->hasQueuedTaskPlanMutationRequest($content);
            $hasQueuedTaskPlanResume = $this->hasQueuedTaskPlanResumeRequest($content, $queuedScope);
            $guard = $this->guardTaskPlanQueueExecution(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $forceRebuild,
                $hasQueuedTaskPlanMutation || $hasQueuedTaskPlanResume,
                $effectiveExecutionToken
            );
            if (!($guard['ok'] ?? false)) {
                $message = (string)($guard['message'] ?? 'Stage-two task-plan queue stopped.');
                $this->appendQueueLifecycleLine($queue, $message);
                if ((string)($guard['terminal_status'] ?? '') === Queue::status_done) {
                    $this->markQueueDone($queue, $message);
                } else {
                    $this->markQueueStopped($queue, $message);
                }

                return $message;
            }

            if ($forceRebuild) {
                $session = $this->applyForceTaskPlanRebuildPreset($sessionService, $scopeService, $session, $adminId);
                $this->appendQueueLifecycleLine($queue, '检测到 _force_rebuild=1，已切换为 rebuild_task_plan 强制重建，execution_token=' . \substr($effectiveExecutionToken, 0, 20) . '…');
            } else {
                $session = $this->applyQueuedTaskPlanRequest($sessionService, $scopeService, $session, $adminId, $content);
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

            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
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
                'task_plan',
                $effectiveExecutionToken,
                \trim((string)($content['job_key'] ?? '')),
                \trim((string)($content['job_type'] ?? ''))
            );
            $previousSseContextExists = RequestContext::has(RequestContext::SSE_WRITER_KEY);
            $previousSseContext = RequestContext::get(RequestContext::SSE_WRITER_KEY);
            RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
            $sseContextRegistered = true;
            $previousAiRuntimeParamsExists = AiRuntimeContext::hasDefaultParams();
            $previousAiRuntimeParams = AiRuntimeContext::getDefaultParams();
            AiRuntimeContext::setDefaultParams(AiRuntimeContext::thinkingModeParams());
            $aiRuntimeParamsRegistered = true;
            $this->queueTrace($sse, 'AI thinking mode enabled for queue execution; reasoning_content is kept separate from output content.');
            $this->queueTrace($sse, 'QueueDbWriter 已创建，后续步骤将写入队列 result 与会话事件');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, 'task_plan', 'queue']);
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
            $this->markQueueDone($queue, '第二阶段任务方案生成完成。');
            $sse->complete();

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
            if ($aiRuntimeParamsRegistered) {
                if ($previousAiRuntimeParamsExists) {
                    AiRuntimeContext::setDefaultParams($previousAiRuntimeParams);
                } else {
                    AiRuntimeContext::removeDefaultParams();
                }
            }
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

            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $active['status'] = 'error';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $taskPlanOperation = \is_array($activeOperations['task_plan'] ?? null) ? $activeOperations['task_plan'] : [];
            if ($taskPlanOperation !== [] && (string)($taskPlanOperation['execution_token'] ?? '') === $executionToken) {
                $taskPlanOperation['status'] = 'error';
                $taskPlanOperation['message'] = $message;
                $taskPlanOperation['updated_at'] = $active['updated_at'];
                $activeOperations['task_plan'] = $taskPlanOperation;
                $scope['active_operations'] = $activeOperations;
            }
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
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($active['status'] ?? ''));
        $activeQueueId = (int)($active['queue_id'] ?? 0);
        if (
            (string)($active['operation'] ?? '') === $operation
            && (string)($active['execution_token'] ?? '') === $executionToken
            && \in_array($activeStatus, ['queued', 'running'], true)
            && ($activeQueueId === $queueId || $queueId <= 0)
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
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $draft = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
        $draftMarkdown = \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? ''));
        $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
        $taskPlanGeneratedAt = \trim((string)($scope['task_plan_generated_at'] ?? ''));
        $needsRepair = false;

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
            $summaryPlan = $taskPlanStructured !== [] ? $taskPlanStructured : $draft;
            if (!isset($summary['shared_task_count'])) {
                $summary['shared_task_count'] = \count(\is_array($summaryPlan['shared_tasks'] ?? null) ? $summaryPlan['shared_tasks'] : []);
            }
            if (!isset($summary['page_task_count'])) {
                $summary['page_task_count'] = \array_sum(\array_map(
                    static fn($items): int => \is_array($items) ? \count($items) : 0,
                    \is_array($summaryPlan['page_tasks'] ?? null) ? $summaryPlan['page_tasks'] : []
                ));
            }

            $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
                'task_plan_markdown' => $taskPlanMarkdown,
                'task_plan_generated_at' => $taskPlanGeneratedAt,
                'task_plan_summary' => $summary,
            ]);

            $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
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
            ? '将队列 #' . $sse->getQueueId() . ' 重置为 pending/pid=0 后等待系统定时任务调度'
            : '将对应 weline_queue 记录重置为 pending/pid=0 后等待系统定时任务调度';
        throw new \RuntimeException(
            '任务方案草案校验失败，未检测到完整 draft 落库；请'
            . $queueHint
            . '，或检查 AiSiteAgent::runTaskPlanOperation 写入链路。('
            . $hint
            . ')'
        );
    }

    /**
     * @param array<string, mixed> $content
     */
    private function hasQueuedTaskPlanMutationRequest(array $content): bool
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scopePatch['_task_plan_sse_request'] ?? null) ? $scopePatch['_task_plan_sse_request'] : [],
            \is_array($content['_task_plan_sse_request'] ?? null) ? $content['_task_plan_sse_request'] : [],
            \is_array($details['_task_plan_sse_request'] ?? null) ? $details['_task_plan_sse_request'] : []
        );
        $mutation = \is_array($request['mutation'] ?? null)
            ? $request['mutation']
            : (\is_array($content['mutation'] ?? null)
                ? $content['mutation']
                : (\is_array($details['mutation'] ?? null) ? $details['mutation'] : []));
        $mutations = \is_array($request['mutations'] ?? null)
            ? $request['mutations']
            : (\is_array($content['mutations'] ?? null)
                ? $content['mutations']
                : (\is_array($details['mutations'] ?? null) ? $details['mutations'] : []));
        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);
        $action = $this->firstNonEmptyString([$content['action'] ?? null, $details['action'] ?? null, $mutation['action'] ?? null]);
        $taskKey = $this->firstNonEmptyString([$content['task_key'] ?? null, $details['task_key'] ?? null, $mutation['task_key'] ?? null, $request['task_key'] ?? null]);
        $taskKeys = $this->normalizeStringList([
            ...$this->normalizeStringList($content['task_keys'] ?? []),
            ...$this->normalizeStringList($details['task_keys'] ?? []),
            ...$this->normalizeStringList($request['task_keys'] ?? []),
        ]);
        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);
        $targetScopes = $this->normalizeStringList([
            ...$this->normalizeStringList($content['target_scopes'] ?? []),
            ...$this->normalizeStringList($details['target_scopes'] ?? []),
            ...$this->normalizeStringList($request['target_scopes'] ?? []),
        ]);

        return \in_array($promptMode, ['mutate_task_plan_task', 'refine_task_plan', 'rebuild_task_plan'], true)
            || $action !== ''
            || $taskKey !== ''
            || $taskKeys !== []
            || $mutation !== []
            || $mutations !== []
            || ($targetScope !== '' && $targetScope !== 'task_plan' && $targetScope !== 'full_task_plan');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function hasQueuedTaskPlanResumeRequest(array $content, array $scope = []): bool
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [],
            \is_array($scopePatch['_task_plan_sse_request'] ?? null) ? $scopePatch['_task_plan_sse_request'] : [],
            \is_array($content['_task_plan_sse_request'] ?? null) ? $content['_task_plan_sse_request'] : [],
            \is_array($details['_task_plan_sse_request'] ?? null) ? $details['_task_plan_sse_request'] : []
        );
        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);

        if ($promptMode === 'resume_task_plan') {
            return true;
        }

        return $this->hasTaskPlanRetryableFailureHints($scope, $scopePatch, $content, $details, $request);
    }

    /**
     * @param array<string, mixed> ...$sources
     */
    private function hasTaskPlanRetryableFailureHints(array ...$sources): bool
    {
        foreach ($sources as $source) {
            $ledger = \is_array($source['retryable_ai_failures'] ?? null) ? $source['retryable_ai_failures'] : [];
            if ($ledger !== [] && $this->hasTaskPlanRetryableFailuresInLedger($ledger)) {
                return true;
            }

            $summary = \is_array($source['retryable_ai_failure_summary'] ?? null) ? $source['retryable_ai_failure_summary'] : [];
            $ops = \is_array($summary['operations'] ?? null) ? $summary['operations'] : [];
            if ((int)($ops['task_plan'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ledger
     */
    private function hasTaskPlanRetryableFailuresInLedger(array $ledger): bool
    {
        $taskPlanBucket = \is_array($ledger['task_plan'] ?? null) ? $ledger['task_plan'] : [];
        $taskPlanItems = \is_array($taskPlanBucket['items'] ?? null) ? $taskPlanBucket['items'] : [];
        if ($taskPlanItems !== []) {
            return true;
        }

        if (\array_is_list($ledger)) {
            foreach ($ledger as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                if (\trim((string)($item['operation'] ?? '')) === 'task_plan') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function applyQueuedTaskPlanRequest(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        array $content
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $request = $this->buildQueuedTaskPlanSseRequest($content, $scope);
        if ($request === []) {
            return $fresh;
        }

        $patch = [
            '_task_plan_sse_request' => $request,
            'task_plan_generation_last_error' => [],
        ];
        if (\in_array((string)($request['prompt_mode'] ?? ''), ['mutate_task_plan_task', 'refine_task_plan', 'rebuild_task_plan'], true)) {
            $patch['task_plan_confirmed'] = 0;
        }

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, $patch);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildQueuedTaskPlanSseRequest(array $content, array $scope): array
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [],
            \is_array($scopePatch['_task_plan_sse_request'] ?? null) ? $scopePatch['_task_plan_sse_request'] : [],
            \is_array($content['_task_plan_sse_request'] ?? null) ? $content['_task_plan_sse_request'] : [],
            \is_array($details['_task_plan_sse_request'] ?? null) ? $details['_task_plan_sse_request'] : []
        );
        $mutation = \is_array($request['mutation'] ?? null)
            ? $request['mutation']
            : (\is_array($content['mutation'] ?? null)
                ? $content['mutation']
                : (\is_array($details['mutation'] ?? null) ? $details['mutation'] : []));
        $taskConfig = \is_array($mutation['task_config'] ?? null)
            ? $mutation['task_config']
            : (\is_array($content['task_config'] ?? null)
                ? $content['task_config']
                : (\is_array($details['task_config'] ?? null) ? $details['task_config'] : []));
        $action = $this->firstNonEmptyString([$content['action'] ?? null, $details['action'] ?? null, $mutation['action'] ?? null]);
        $bucket = $this->firstNonEmptyString([$content['bucket'] ?? null, $details['bucket'] ?? null, $mutation['bucket'] ?? null]);
        $bucket = \strtolower($bucket) === 'shared' ? 'shared' : 'page';
        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $mutation['page_type'] ?? null, $request['page_type'] ?? null]);
        $taskKey = $this->firstNonEmptyString([$content['task_key'] ?? null, $details['task_key'] ?? null, $mutation['task_key'] ?? null, $request['task_key'] ?? null]);
        $taskKeys = $this->normalizeStringList([
            ...$this->normalizeStringList($content['task_keys'] ?? []),
            ...$this->normalizeStringList($details['task_keys'] ?? []),
            ...$this->normalizeStringList($request['task_keys'] ?? []),
            ...$this->normalizeStringList($mutation['task_keys'] ?? []),
        ]);
        if ($taskKey !== '' && !\in_array($taskKey, $taskKeys, true)) {
            \array_unshift($taskKeys, $taskKey);
        }
        $targetScopes = $this->normalizeStringList([
            ...$this->normalizeStringList($content['target_scopes'] ?? []),
            ...$this->normalizeStringList($details['target_scopes'] ?? []),
            ...$this->normalizeStringList($request['target_scopes'] ?? []),
            ...$this->normalizeStringList($mutation['target_scopes'] ?? []),
        ]);
        if ($mutation === [] && ($action !== '' || $taskKey !== '' || $pageType !== '')) {
            $mutation = [
                'action' => $action,
                'bucket' => $bucket,
                'page_type' => $pageType,
                'task_key' => $taskKey,
                'task_keys' => $taskKeys,
                'task_config' => $taskConfig,
            ];
        }
        if ($mutation !== [] && !\is_array($mutation['task_config'] ?? null)) {
            $mutation['task_config'] = $taskConfig;
        }

        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);
        if ($promptMode === '' && $mutation !== []) {
            $promptMode = 'mutate_task_plan_task';
        }
        if ($promptMode === '') {
            return [];
        }

        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);
        if ($targetScope === '') {
            $targetScope = $taskKey !== ''
                ? $taskKey
                : ($bucket === 'shared' ? 'shared_tasks' : ($pageType !== '' ? 'page_tasks.' . $pageType : 'task_plan'));
        }
        if ($targetScope !== '' && !\in_array($targetScope, $targetScopes, true)) {
            \array_unshift($targetScopes, $targetScope);
        }
        $roundValue = $this->firstNonEmptyString([$content['round'] ?? null, $details['round'] ?? null, $request['round'] ?? null]);
        $round = \max(1, (int)($roundValue !== '' ? $roundValue : ((int)($scope['virtual_theme_plan']['last_round'] ?? 0) + 1)));
        $instruction = $this->firstNonEmptyString([
            $content['instruction'] ?? null,
            $details['instruction'] ?? null,
            $request['instruction'] ?? null,
            $taskConfig['instruction'] ?? null,
        ]);

        $request = \array_replace($request, [
            'prompt_mode' => $promptMode,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'target_scopes' => $targetScopes,
            'round' => $round,
            'task_key' => $taskKey,
            'task_keys' => $taskKeys,
        ]);
        if ($mutation !== []) {
            $request['mutation'] = $mutation;
        }

        return $request;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (!\is_scalar($item) && !(\is_object($item) && \method_exists($item, '__toString'))) {
                continue;
            }
            $text = \trim((string)$item);
            if ($text !== '' && !\in_array($text, $list, true)) {
                $list[] = $text;
            }
        }
        return $list;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function applyForceTaskPlanRebuildPreset(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $currentReq = \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [];
        $nextRound = \max(1, (int)($currentReq['round'] ?? 0) + 1);

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, \array_replace($this->buildTaskPlanForceRebuildResetPatch(), [
            '_task_plan_sse_request' => [
                'prompt_mode' => 'rebuild_task_plan',
                'instruction' => '[FORCE] queue:run -f 强制重建第二阶段任务方案',
                'target_scope' => 'full_task_plan',
                'round' => $nextRound,
                'forced_by_queue_run' => 1,
            ],
            '_task_plan_rebuild_in_progress' => 1,
        ]));

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @return array{ok: bool, message?: string, terminal_status?: string}
     */
    private function guardTaskPlanQueueExecution(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        bool $forceRebuild,
        bool $allowExistingTaskPlan,
        string $executionToken
    ): array {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );

        if (!$forceRebuild && !$allowExistingTaskPlan && $this->scopeHasPersistedStageTwoTaskPlan($scope)) {
            $message = 'Stage-two task plan already exists; queue skipped duplicate generation. Use task/block refine or force rebuild to run again.';
            $this->persistTaskPlanQueueStopState($sessionService, (int)$fresh->getId(), $adminId, $scope, $message, $executionToken, true);

            return ['ok' => false, 'message' => $message, 'terminal_status' => Queue::status_done];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeHasPersistedStageTwoTaskPlan(array $scope): bool
    {
        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1) {
            return true;
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        if ($draft !== [] || $confirmed !== []) {
            return true;
        }

        foreach (['draft_markdown', 'confirmed_markdown', 'confirmed_at', 'confirmed_signature', 'plan_signature'] as $key) {
            if (\trim((string)($virtualThemePlan[$key] ?? '')) !== '') {
                return true;
            }
        }
        if (\trim((string)($scope['task_plan_markdown'] ?? '')) !== '') {
            return true;
        }
        if (\is_array($scope['task_plan_structured'] ?? null) && $scope['task_plan_structured'] !== []) {
            return true;
        }
        if (\is_array($scope['task_plan_directory_tree'] ?? null) && $scope['task_plan_directory_tree'] !== []) {
            return true;
        }

        $summary = \is_array($scope['task_plan_summary'] ?? null) ? $scope['task_plan_summary'] : [];

        return ((int)($summary['page_task_count'] ?? 0)) > 0
            || ((int)($summary['shared_task_count'] ?? 0)) > 0
            || \trim((string)($summary['signature'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistTaskPlanQueueStopState(
        AiSiteAgentSessionService $sessionService,
        int $sessionId,
        int $adminId,
        array $scope,
        string $message,
        string $executionToken,
        bool $skipAsDone = false
    ): void {
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($active !== [] && (
            (string)($active['operation'] ?? '') === 'task_plan'
            || (string)($active['execution_token'] ?? '') === $executionToken
        )) {
            $active['status'] = $skipAsDone ? 'done' : 'stop';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $taskPlanOperation = \is_array($activeOperations['task_plan'] ?? null) ? $activeOperations['task_plan'] : [];
        if ($taskPlanOperation !== [] && (
            (string)($taskPlanOperation['execution_token'] ?? '') === $executionToken
            || \in_array((string)($taskPlanOperation['status'] ?? ''), ['queued', 'running'], true)
        )) {
            $taskPlanOperation['status'] = $skipAsDone ? 'done' : 'stop';
            $taskPlanOperation['message'] = $message;
            $taskPlanOperation['updated_at'] = \date('Y-m-d H:i:s');
            $activeOperations['task_plan'] = $taskPlanOperation;
            $scope['active_operations'] = $activeOperations;
        }

        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        if ($skipAsDone) {
            $scope['task_plan_generation_last_error'] = [];
        } else {
            $scope['task_plan_generation_last_error'] = [
                'message' => $message,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
        }
        $sessionService->replaceScope($sessionId, $adminId, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskPlanForceRebuildResetPatch(): array
    {
        return [
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'draft_generated_at' => '',
                'confirmed' => [],
                'confirmed_markdown' => '',
                'confirmed_at' => '',
                'confirmed_signature' => '',
                'plan_signature' => '',
                'last_prompt_mode' => 'rebuild_task_plan',
                'last_target_scope' => '',
                'last_round' => 1,
            ],
            'task_plan_markdown' => '',
            'task_plan_generated_at' => '',
            'task_plan_structured' => [],
            'task_plan_directory_tree' => [],
            'task_plan_summary' => [],
            'task_plan_confirmed' => 0,
            'task_plan_confirmed_at' => '',
            'task_plan_rebuild_summary' => [],
            'task_plan_change_scope_report' => [],
            'task_plan_generation_progress' => [],
            'task_plan_generation_summary' => [],
            'task_plan_generation_last_error' => '',
            'build_blueprint' => [],
            'build_tasks' => [],
        ];
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

    private function markQueueDone(Queue &$queue, string $message): void
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE_DONE ' . $message;
        $existing = (string)($row['result'] ?? '');
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'status' => Queue::status_done,
                'pid' => 0,
                'finished' => 1,
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

    private function markQueueStopped(Queue &$queue, string $message): void
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE_STOP ' . $message;
        $existing = (string)($row['result'] ?? '');
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'status' => Queue::status_stop,
                'pid' => 0,
                'finished' => 1,
                'process' => $message,
                'result' => $existing === '' ? $line : $existing . PHP_EOL . $line,
            ],
        ]);
        $this->mirrorToCli($line);
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
