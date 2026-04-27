<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Ai\Service\AiRuntimeContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSitePlanQueue implements QueueInterface
{
    private const MAX_PLAN_QUEUE_ATTEMPTS = 3;

    public function name(): string
    {
        return 'PageBuilder AI 第一阶段方案生成队列';
    }

    public function tip(): string
    {
        return '异步执行 PageBuilder 第一阶段方案 AI 生成任务，并通过 SSE 同步阶段进度。';
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

        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
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
        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
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

            $hasQueuedPlanMutation = $this->hasQueuedPlanMutationRequest($content);
            $guard = $this->guardPlanQueueExecution($sessionService, $scopeService, $session, $adminId, $forceRebuild, $hasQueuedPlanMutation);
            if (!($guard['ok'] ?? false)) {
                $message = (string)($guard['message'] ?? 'Stage-one plan queue stopped.');
                $this->appendQueueLifecycleLine($queue, $message);
                $this->markQueueStopped($queue, $message);
                return $message;
            }

            if ($forceRebuild) {
                $session = $this->applyForcePlanRebuildPreset($sessionService, $scopeService, $session, $adminId);
                $this->appendQueueLifecycleLine(
                    $queue,
                    '检测到 _force_rebuild=1，已切换为 rebuild 强制重建阶段一，execution_token=' . \substr($effectiveExecutionToken, 0, 20)
                );
            } else {
                $session = $this->applyQueuedPlanRequest($sessionService, $scopeService, $session, $adminId, $content);
            }

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                'plan',
                $effectiveExecutionToken
            );
            $this->appendQueueLifecycleLine(
                $queue,
                '已同步 active_operation=queued operation=plan execution_token=' . \substr($effectiveExecutionToken, 0, 12)
            );

            $sse = new QueueDbWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_PLAN,
                'plan',
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
            $this->queueTrace($sse, 'QueueDbWriter 已创建，阶段一进度将写入队列 result 与会话事件。');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, 'plan', 'queue']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, '认领跳过：duplicate_stream（重复阶段一生成）');
                    return '检测到重复阶段一生成任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }
            $this->queueTrace($sse, '认领成功，进入 runPlanOperation。');

            $this->invokePrivate($controller, 'runPlanOperation', [$sse, $session, $adminId]);
            $this->queueTrace($sse, 'runPlanOperation 已返回。');
            $this->queueTrace($sse, '队列执行成功：第一阶段方案生成完成。');
            $this->markQueueDone($queue, '第一阶段方案生成完成。');
            $sse->complete();

            return '第一阶段方案生成完成。';
        } catch (\Throwable $throwable) {
            $message = $this->normalizeQueueFailureMessage($throwable->getMessage());
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '异常：' . $message);
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（SSE 未初始化）：' . $message);
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $message);
            throw new \RuntimeException($message, 0, $throwable);
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

    private function normalizeQueueFailureMessage(string $message): string
    {
        $normalized = \trim($message);
        if ($normalized === '') {
            $normalized = '未知错误。';
        }

        $normalized = (string)(\preg_replace('/^(?:第一阶段方案生成失败：\s*)+/u', '', $normalized) ?? $normalized);
        $normalized = (string)(\preg_replace('/^(?:AI plan generation failed:\s*)+/i', '', $normalized) ?? $normalized);

        return '第一阶段方案生成失败：' . $normalized;
    }

    private function applyForcePlanRebuildPreset(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $currentReq = \is_array($scope['_plan_sse_request'] ?? null) ? $scope['_plan_sse_request'] : [];
        $nextRound = \max(1, (int)($currentReq['round'] ?? 0) + 1);

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
            '_plan_sse_request' => [
                'prompt_mode' => 'rebuild',
                'instruction' => '[FORCE] queue:run -f 强制重建阶段一方案',
                'target_scope' => 'full_plan',
                'round' => $nextRound,
                'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? '')),
                'forced_by_queue_run' => 1,
            ],
            'plan_confirmed' => 0,
            'plan_generation_last_error' => [],
        ]);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function hasQueuedPlanMutationRequest(array $content): bool
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scopePatch['_plan_sse_request'] ?? null) ? $scopePatch['_plan_sse_request'] : [],
            \is_array($content['_plan_sse_request'] ?? null) ? $content['_plan_sse_request'] : [],
            \is_array($details['_plan_sse_request'] ?? null) ? $details['_plan_sse_request'] : []
        );
        $mutation = \is_array($request['mutation'] ?? null)
            ? $request['mutation']
            : (\is_array($content['mutation'] ?? null)
                ? $content['mutation']
                : (\is_array($details['mutation'] ?? null) ? $details['mutation'] : []));
        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);
        $action = $this->firstNonEmptyString([$content['action'] ?? null, $details['action'] ?? null, $mutation['action'] ?? null]);
        $blockKey = $this->firstNonEmptyString([$content['block_key'] ?? null, $details['block_key'] ?? null, $mutation['block_key'] ?? null]);
        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);

        return $promptMode === 'mutate_plan_block'
            || $action !== ''
            || $blockKey !== ''
            || $mutation !== []
            || \str_contains($targetScope, '.blocks.');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function applyQueuedPlanRequest(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        array $content
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $request = $this->buildQueuedPlanSseRequest($content, $scope);
        if ($request === []) {
            return $fresh;
        }

        $patch = [
            '_plan_sse_request' => $request,
            'plan_last_prompt_mode' => (string)($request['prompt_mode'] ?? ''),
            'plan_last_target_scope' => (string)($request['target_scope'] ?? ''),
            'plan_last_round' => (int)($request['round'] ?? 1),
            'plan_generation_last_error' => [],
        ];
        if (\in_array((string)($request['prompt_mode'] ?? ''), ['mutate_plan_block', 'refine', 'rebuild'], true)) {
            $patch['plan_confirmed'] = 0;
        }

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, $patch);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildQueuedPlanSseRequest(array $content, array $scope): array
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scopePatch['_plan_sse_request'] ?? null) ? $scopePatch['_plan_sse_request'] : [],
            \is_array($content['_plan_sse_request'] ?? null) ? $content['_plan_sse_request'] : [],
            \is_array($details['_plan_sse_request'] ?? null) ? $details['_plan_sse_request'] : []
        );
        $mutation = \is_array($request['mutation'] ?? null)
            ? $request['mutation']
            : (\is_array($content['mutation'] ?? null)
                ? $content['mutation']
                : (\is_array($details['mutation'] ?? null) ? $details['mutation'] : []));
        $blockConfig = \is_array($mutation['block_config'] ?? null)
            ? $mutation['block_config']
            : (\is_array($content['block_config'] ?? null)
                ? $content['block_config']
                : (\is_array($details['block_config'] ?? null) ? $details['block_config'] : []));
        $action = $this->firstNonEmptyString([$content['action'] ?? null, $details['action'] ?? null, $mutation['action'] ?? null]);
        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $mutation['page_type'] ?? null, $request['page_type'] ?? null]);
        $blockKey = $this->firstNonEmptyString([$content['block_key'] ?? null, $details['block_key'] ?? null, $mutation['block_key'] ?? null, $request['block_key'] ?? null]);
        if ($mutation === [] && ($action !== '' || $pageType !== '' || $blockKey !== '')) {
            $mutation = [
                'action' => $action,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'block_config' => $blockConfig,
            ];
        }
        if ($mutation !== [] && !\is_array($mutation['block_config'] ?? null)) {
            $mutation['block_config'] = $blockConfig;
        }

        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);
        if ($promptMode === '' && $mutation !== []) {
            $promptMode = 'mutate_plan_block';
        }
        if ($promptMode === '') {
            return [];
        }

        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);
        if ($targetScope === '' && $pageType !== '') {
            $targetScope = 'pages.' . $pageType . '.blocks.' . ($blockKey !== '' ? $blockKey : 'new');
        }
        $roundValue = $this->firstNonEmptyString([$content['round'] ?? null, $details['round'] ?? null, $request['round'] ?? null]);
        $round = \max(1, (int)($roundValue !== '' ? $roundValue : ((int)($scope['plan_last_round'] ?? 0) + 1)));
        $instruction = $this->firstNonEmptyString([
            $content['instruction'] ?? null,
            $details['instruction'] ?? null,
            $request['instruction'] ?? null,
            $blockConfig['instruction'] ?? null,
        ]);

        $request = \array_replace($request, [
            'prompt_mode' => $promptMode,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'round' => $round,
            'plan_locale' => $this->firstNonEmptyString([$content['plan_locale'] ?? null, $details['plan_locale'] ?? null, $request['plan_locale'] ?? null, $scope['plan_locale'] ?? null]),
        ]);
        if ($mutation !== []) {
            $request['mutation'] = $mutation;
        }

        return $request;
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
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $attemptNo = \max(0, (int)($active['attempt_no'] ?? 0)) + 1;
            $active['status'] = 'error';
            $active['message'] = $message;
            $active['attempt_no'] = $attemptNo;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
            $scope['plan_generation_last_error'] = [
                'message' => $message,
                'attempt_no' => $attemptNo,
                'max_attempts' => self::MAX_PLAN_QUEUE_ATTEMPTS,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
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
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
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
            'attempt_no' => \max(0, (int)($active['attempt_no'] ?? 0)),
            'started_at' => (string)($active['started_at'] ?? \date('Y-m-d H:i:s')),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $scope['active_operation'];
        $scope['active_operations'] = $activeOperations;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private function guardPlanQueueExecution(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        bool $forceRebuild,
        bool $allowExistingPlan
    ): array {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );

        if (!$forceRebuild && !$allowExistingPlan && $scopeService->hasPersistedStageOnePlan($scope)) {
            $message = (string)__('第一阶段方案已存在；队列已跳过重复生成。若需重新生成，请使用强制重建。');
            $this->persistPlanQueueStopState($sessionService, (int)$fresh->getId(), $adminId, $scope, $message);
            return ['ok' => false, 'message' => $message];
        }

        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $attemptNo = \max(0, (int)($active['attempt_no'] ?? 0));
        if (!$forceRebuild && !$allowExistingPlan && $attemptNo >= self::MAX_PLAN_QUEUE_ATTEMPTS) {
            $message = (string)__('第一阶段方案生成已失败 %{1} 次，队列不再自动重试；如需继续，请手动强制重建。', [self::MAX_PLAN_QUEUE_ATTEMPTS]);
            $this->persistPlanQueueStopState($sessionService, (int)$fresh->getId(), $adminId, $scope, $message);
            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistPlanQueueStopState(
        AiSiteAgentSessionService $sessionService,
        int $sessionId,
        int $adminId,
        array $scope,
        string $message
    ): void {
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($active !== []) {
            $active['status'] = 'stop';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
        }
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        $scope['plan_generation_last_error'] = [
            'message' => $message,
            'attempt_no' => \max(0, (int)($active['attempt_no'] ?? 0)),
            'max_attempts' => self::MAX_PLAN_QUEUE_ATTEMPTS,
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $sessionService->replaceScope($sessionId, $adminId, $scope);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }

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
