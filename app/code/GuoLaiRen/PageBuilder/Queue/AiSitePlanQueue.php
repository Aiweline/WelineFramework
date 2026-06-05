<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonStateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueLogWriter;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteWorkflowTrace;
use Weline\Ai\Service\AiRuntimeContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\DeadWorkerRecoverableQueueInterface;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSitePlanQueue implements QueueInterface, DeadWorkerRecoverableQueueInterface
{
    private const MAX_PLAN_QUEUE_ATTEMPTS = 2;
    private const CONTENT_AUTO_ATTEMPT_KEY = '_plan_auto_attempt';
    private const CONTENT_MAX_AUTO_ATTEMPTS_KEY = 'max_auto_attempts';
    private const CONTENT_AUTO_RETRY_SCHEDULED_KEY = '_auto_retry_scheduled';
    private const CONTENT_LAST_GATE_DECISION_KEY = 'last_gate_decision';
    private const CONTENT_LAST_GATE_REASON_KEY = 'last_gate_reason';
    private const CONTENT_LAST_GATE_AT_KEY = 'last_gate_at';
    private const QUEUE_RESULT_MAX_BYTES = 4096;
    private const QUEUE_RESULT_TRUNCATION_MARKER = '[... queue log truncated ...]';
    private const PLAN_COMPLETION_GATE_ARTIFACT_KEYS = [];
    private const PLAN_JSON_PAGE_META_KEYS = [
        'page_key' => true,
        'page_type' => true,
        'type' => true,
        'status' => true,
        'message' => true,
        'error' => true,
        'error_message' => true,
        'updated_at' => true,
        'started_at' => true,
        'finished_at' => true,
        'attempt_no' => true,
        'title' => true,
        'label' => true,
        'page_label' => true,
        'page_title' => true,
        'page_goal' => true,
        'content_locale' => true,
        'page_design_plan' => true,
        'theme_alignment_summary' => true,
        'ordered_block_keys' => true,
        'pages' => true,
        'page' => true,
        'plan_json_page' => true,
        'seo' => true,
        'route' => true,
        'route_path' => true,
        'slug' => true,
        'path' => true,
        'layout' => true,
        'style_code' => true,
        'style_settings' => true,
        'design_tokens' => true,
        'theme_css_ref' => true,
        'navigation' => true,
        'menus' => true,
        'links' => true,
        'settings' => true,
        'preview_url' => true,
        'preview_full_url' => true,
        'visual_preview_url' => true,
        'visual_edit_url' => true,
        'virtual_preview_url' => true,
        'virtual_edit_url' => true,
        'sections' => true,
        'section_refinements' => true,
        'ai_description' => true,
        'content' => true,
        'description' => true,
        'summary' => true,
    ];

    public function name(): string
    {
        return 'PageBuilder AI 第一阶段方案生成队列';
    }

    public function tip(): string
    {
        return '异步执行 PageBuilder 第一阶段方案 AI 生成任务，并写入 plan_json 状态与队列日志。';
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

    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        unset($workerOutput);
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content)) {
            return false;
        }
        if ($deadPid <= 0) {
            return false;
        }
        if (\max(0, (int)($content[self::CONTENT_AUTO_ATTEMPT_KEY] ?? 0)) >= self::MAX_PLAN_QUEUE_ATTEMPTS) {
            return false;
        }

        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            return false;
        }

        $operation = \trim((string)($content['operation'] ?? 'plan'));
        $stage = \trim((string)($content['stage'] ?? 'plan'));

        return ($operation === '' || $operation === 'plan') && ($stage === '' || $stage === 'plan');
    }

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        unset($queue, $deadPid, $workerOutput);

        return 'PageBuilder plan worker exited before terminal state; queue reset to pending for scheduler resume.';
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
            AiSiteWorkflowTrace::log('queue_plan_execute_start', [
                'public_id' => $publicId,
                'queue_id' => $queueId,
                'execution_token' => $effectiveExecutionToken,
                'force_rebuild' => $forceRebuild,
            ]);
            $this->appendQueueLifecycleLine($queue, '已加载会话 session_id=' . (int)$session->getId());

            [$content, $autoAttempt, $maxAutoAttempts] = $this->beginPlanQueueAttempt($queue, $content, $effectiveExecutionToken);
            if ($autoAttempt > $maxAutoAttempts) {
                $message = 'Stage-one plan queue has already run '
                    . $maxAutoAttempts
                    . ' automatic attempts; automatic retry is stopped. Please confirm manually before running again.';
                $scope = $scopeService->normalizeScope(
                    $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
                );
                $this->persistPlanQueueStopState($sessionService, (int)$session->getId(), $adminId, $scope, $message);
                $this->markQueueStopped($queue, $message);

                return $message;
            }

            $hasQueuedPlanMutation = $this->hasQueuedPlanMutationRequest($content);
            $hasQueuedPlanResume = $this->hasQueuedPlanResumeRequest($content);
            $guard = $this->guardPlanQueueExecution(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $forceRebuild,
                $hasQueuedPlanMutation || ($hasQueuedPlanResume && !$this->isAutomaticPlanRetryContent($content))
            );
            if (!($guard['ok'] ?? false)) {
                $message = (string)($guard['message'] ?? 'Stage-one plan queue stopped.');
                $this->appendQueueLifecycleLine($queue, $message);
                if ((string)($guard['terminal_status'] ?? '') === Queue::status_done) {
                    $this->markQueueDone($queue, $message);
                } else {
                    $this->markQueueStopped($queue, $message);
                }
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

            $sse = new AiSiteQueueLogWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_PLAN,
                'plan',
                $effectiveExecutionToken,
                \trim((string)($content['job_key'] ?? '')),
                \trim((string)($content['job_type'] ?? ''))
            );
            $previousAiRuntimeParamsExists = AiRuntimeContext::hasDefaultParams();
            $previousAiRuntimeParams = AiRuntimeContext::getDefaultParams();
            AiRuntimeContext::setDefaultParams(AiRuntimeContext::thinkingModeParams());
            $aiRuntimeParamsRegistered = true;
            $this->queueTrace($sse, 'AI thinking mode enabled for queue execution; reasoning_content is kept separate from output content.');
            $this->queueTrace($sse, 'Queue log writer 已创建，阶段一状态写入 plan_json，队列仅记录 result 与会话事件。');

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
            $retryablePlanMessages = $this->assertPlanQueueCompletionGate($sessionService, $scopeService, $publicId, $adminId);
            if ($retryablePlanMessages !== []) {
                $retryMessage = 'Stage-one plan has retryable page failures; the same queue will resume missing pages: '
                    . \implode('; ', \array_slice($retryablePlanMessages, 0, 5));
                $this->queueTrace($sse, $retryMessage);
                if (!$this->canSchedulePlanCompletionGateRetry($content)) {
                    $stopMessage = 'Stage-one plan completion gate still found retryable page failures after '
                        . self::MAX_PLAN_QUEUE_ATTEMPTS
                        . ' automatic attempts; automatic retry has stopped. Please retry manually after adjusting or regenerating the plan: '
                        . \implode('; ', \array_slice($retryablePlanMessages, 0, 5));
                    $this->queueTrace($sse, $stopMessage);
                    $freshSession = $sessionService->loadByPublicId($publicId, $adminId) ?? $session;
                    $freshScope = $scopeService->normalizeScope(
                        $sessionService->loadScopeForStage($freshSession, AiSiteAgentSession::STAGE_PLAN)
                    );
                    $this->persistPlanQueueStopState(
                        $sessionService,
                        (int)$freshSession->getId(),
                        $adminId,
                        $freshScope,
                        $stopMessage
                    );
                    $this->markQueueStopped($queue, $stopMessage);

                    return $stopMessage;
                }
                $retryQueueId = $this->createPlanCompletionGateRetryQueue($queue, $content, $retryMessage);
                $this->markPlanRetryScheduledInScope(
                    $sessionService,
                    $scopeService,
                    $publicId,
                    $adminId,
                    $effectiveExecutionToken,
                    $retryQueueId,
                    $retryMessage
                );
                $this->markQueueRetryScheduled($queue, $retryQueueId, $content, $retryMessage);

                return $retryMessage;
            }
            $this->queueTrace($sse, '第一阶段完成门禁已通过。');
            $queueDoneMessage = '第一阶段方案生成完成。';
            $this->queueTrace($sse, '队列执行完成：' . $queueDoneMessage);
            $this->persistPlanQueueCompletionScope(
                $sessionService,
                $scopeService,
                $controller,
                $publicId,
                $adminId,
                $effectiveExecutionToken,
                $queueId
            );
            $this->markQueueDone($queue, $queueDoneMessage);
            $sse->complete();

            return $queueDoneMessage;
        } catch (\Throwable $throwable) {
            $message = $this->normalizeQueueFailureMessage($throwable->getMessage());
            if ($sse instanceof AiSiteQueueLogWriter) {
                $this->queueTrace($sse, '异常：' . $message);
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（队列日志未初始化）：' . $message);
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $message, $queueId);
            if ($this->isTransientAiProviderFailure($message) && $this->canScheduleTransientPlanRetry($content)) {
                $retryQueueId = $this->createTransientPlanRetryQueue($queue, $content, $message);
                $retryMessage = 'Stage-one plan provider was temporarily busy; queue #' . $retryQueueId . ' was marked retryable for the same adapter model.';
                $this->markPlanRetryScheduledInScope(
                    $sessionService,
                    $scopeService,
                    $publicId,
                    $adminId,
                    $effectiveExecutionToken,
                    $retryQueueId,
                    $retryMessage
                );
                $this->markQueueRetryScheduled($queue, $retryQueueId, $content, $retryMessage);
                if ($sse instanceof AiSiteQueueLogWriter) {
                    $this->queueTrace($sse, $retryMessage);
                }

                return $retryMessage;
            }
            if ($this->canScheduleGeneralPlanRetry($content, $message)) {
                $retryQueueId = $this->createGeneralPlanRetryQueue($queue, $content, $message);
                $retryMessage = 'Stage-one plan failed with retryable generated output; queue #' . $retryQueueId . ' will continue the same queue data.';
                $this->markPlanRetryScheduledInScope(
                    $sessionService,
                    $scopeService,
                    $publicId,
                    $adminId,
                    $effectiveExecutionToken,
                    $retryQueueId,
                    $retryMessage
                );
                $this->markQueueRetryScheduled($queue, $retryQueueId, $content, $retryMessage);
                if ($sse instanceof AiSiteQueueLogWriter) {
                    $this->queueTrace($sse, $retryMessage);
                }

                return $retryMessage;
            }
            throw new \RuntimeException($message, 0, $throwable);
        } finally {
            if ($aiRuntimeParamsRegistered) {
                if ($previousAiRuntimeParamsExists) {
                    AiRuntimeContext::setDefaultParams($previousAiRuntimeParams);
                } else {
                    AiRuntimeContext::removeDefaultParams();
                }
            }
            if ($sse instanceof AiSiteQueueLogWriter) {
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
            $sessionService->loadScopeForStage(
                $fresh,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_COMPLETION_GATE_ARTIFACT_KEYS
            )
        );
        $currentReq = \is_array($scope['_plan_sse_request'] ?? null) ? $scope['_plan_sse_request'] : [];
        $nextRound = \max(1, (int)($currentReq['round'] ?? 0) + 1);
        $planJsonEditor = new AiSitePlanJsonStateService((int)$fresh->getId());
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, \array_replace([
            '_plan_sse_request' => [
                'prompt_mode' => 'rebuild',
                'instruction' => '[FORCE] queue:run -f 强制重建建站方案',
                'target_scope' => 'full_plan',
                'round' => $nextRound,
                'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? '')),
                'forced_by_queue_run' => 1,
            ],
            'plan_generation_last_error' => [],
            'plan_generation_progress' => [],
        ], $planJsonEditor->setConfirmedScopePatch($planJson, false)));

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
        $mutations = \is_array($request['mutations'] ?? null)
            ? $request['mutations']
            : (\is_array($content['mutations'] ?? null)
                ? $content['mutations']
                : (\is_array($details['mutations'] ?? null) ? $details['mutations'] : []));
        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);
        $action = $this->firstNonEmptyString([$content['action'] ?? null, $details['action'] ?? null, $mutation['action'] ?? null]);
        $blockKey = $this->firstNonEmptyString([$content['block_key'] ?? null, $details['block_key'] ?? null, $mutation['block_key'] ?? null]);
        $blockKeys = $this->normalizeStringList([
            ...$this->normalizeStringList($content['block_keys'] ?? []),
            ...$this->normalizeStringList($details['block_keys'] ?? []),
            ...$this->normalizeStringList($request['block_keys'] ?? []),
        ]);
        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);
        $targetScopes = $this->normalizeStringList([
            ...$this->normalizeStringList($content['target_scopes'] ?? []),
            ...$this->normalizeStringList($details['target_scopes'] ?? []),
            ...$this->normalizeStringList($request['target_scopes'] ?? []),
        ]);

        return \in_array($promptMode, ['mutate_plan_block', 'refine', 'refine_page'], true)
            || $action !== ''
            || $blockKey !== ''
            || $blockKeys !== []
            || $mutation !== []
            || $mutations !== []
            || (\str_starts_with($targetScope, 'pages.') && \substr_count($targetScope, '.') >= 2);
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

        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $requestedPageTypes = $this->normalizeStringList([
            ...$this->normalizeStringList($scopePatch['page_types'] ?? []),
            ...$this->normalizeStringList($content['page_types'] ?? []),
            ...$this->normalizeStringList($details['page_types'] ?? []),
            ...$this->normalizeStringList($request['page_types'] ?? []),
        ]);
        $patch = [
            '_plan_sse_request' => $request,
            'plan_last_prompt_mode' => (string)($request['prompt_mode'] ?? ''),
            'plan_last_target_scope' => (string)($request['target_scope'] ?? ''),
            'plan_last_round' => (int)($request['round'] ?? 1),
            'plan_generation_last_error' => [],
        ];
        if ($requestedPageTypes !== []) {
            $patch['page_types'] = $requestedPageTypes;
            $patch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        if (\in_array((string)($request['prompt_mode'] ?? ''), ['mutate_plan_block', 'refine', 'rebuild'], true)) {
            $planJsonEditor = new AiSitePlanJsonStateService((int)$fresh->getId());
            $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
            $patch = \array_replace($patch, $planJsonEditor->setConfirmedScopePatch($planJson, false));
        }
        if ((string)($request['prompt_mode'] ?? '') === 'rebuild') {
            $patch['plan_generation_progress'] = [];
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
        $blockKeys = $this->normalizeStringList([
            ...$this->normalizeStringList($content['block_keys'] ?? []),
            ...$this->normalizeStringList($details['block_keys'] ?? []),
            ...$this->normalizeStringList($request['block_keys'] ?? []),
            ...$this->normalizeStringList($mutation['block_keys'] ?? []),
        ]);
        if ($blockKey !== '' && !\in_array($blockKey, $blockKeys, true)) {
            \array_unshift($blockKeys, $blockKey);
        }
        $targetScopes = $this->normalizeStringList([
            ...$this->normalizeStringList($content['target_scopes'] ?? []),
            ...$this->normalizeStringList($details['target_scopes'] ?? []),
            ...$this->normalizeStringList($request['target_scopes'] ?? []),
            ...$this->normalizeStringList($mutation['target_scopes'] ?? []),
        ]);
        if ($mutation === [] && ($action !== '' || $pageType !== '' || $blockKey !== '')) {
            $mutation = [
                'action' => $action,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'block_keys' => $blockKeys,
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

        $pageTypes = $this->normalizeStringList([
            ...$this->normalizeStringList($scopePatch['page_types'] ?? []),
            ...$this->normalizeStringList($content['page_types'] ?? []),
            ...$this->normalizeStringList($details['page_types'] ?? []),
            ...$this->normalizeStringList($request['page_types'] ?? []),
        ]);
        if ($pageTypes === []) {
            $pageTypes = $this->normalizeStringList($scope['page_types'] ?? []);
        }
        $targetScope = $this->firstNonEmptyString([$content['target_scope'] ?? null, $details['target_scope'] ?? null, $request['target_scope'] ?? null]);
        if ($targetScope === '' && $pageType !== '') {
            $targetScope = 'pages.' . $pageType . '.' . ($blockKey !== '' ? $blockKey : 'new');
        }
        if ($targetScope !== '' && !\in_array($targetScope, $targetScopes, true)) {
            \array_unshift($targetScopes, $targetScope);
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
            'target_scopes' => $targetScopes,
            'round' => $round,
            'plan_locale' => $this->firstNonEmptyString([$content['plan_locale'] ?? null, $details['plan_locale'] ?? null, $request['plan_locale'] ?? null, $scope['plan_locale'] ?? null]),
            'page_types' => $pageTypes,
            'block_key' => $blockKey,
            'block_keys' => $blockKeys,
        ]);
        if ($pageTypes !== []) {
            $request[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
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

    private function updateSessionError(string $publicId, int $adminId, string $executionToken, string $message, int $queueId = 0): void
    {
        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
            /** @var AiSitePlanJsonTaskService $planJsonTaskService */
            $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);

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
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $activeOperations['plan'] = $active;
            $scope['active_operations'] = $activeOperations;
            $scope['plan_generation_last_error'] = [
                'message' => $message,
                'attempt_no' => $attemptNo,
                'max_attempts' => self::MAX_PLAN_QUEUE_ATTEMPTS,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
            $scope = $this->markPlanGenerationFailureRetryable($scope, $active, $message, $attemptNo);
            $recoveredPageFailures = $this->recoverRetryableStageOnePageFailuresFromQueue($queueId, $message);
            if ($recoveredPageFailures !== []) {
                $scope = $planJsonTaskService->replaceRetryableAiFailures($scope, 'plan', $recoveredPageFailures);
                $scope['partial_retry_required'] = 1;
            }
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        } catch (\Throwable) {
        }
    }

    /**
     * Recover page-level retry hints from the persisted queue log when final stage-one
     * assembly fails before the normal success path can persist retryable page failures.
     *
     * @return list<array<string, mixed>>
     */
    private function recoverRetryableStageOnePageFailuresFromQueue(int $queueId, string $message): array
    {
        if ($queueId <= 0) {
            return [];
        }

        $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        if (!\is_array($row) || $row === []) {
            return [];
        }

        $result = (string)($row['result'] ?? '');
        if ($result === '') {
            return [];
        }

        $failures = [];
        $matches = [];
        if (\preg_match_all('/Plan JSON page contract failed; retrying strict recovery for page:\s*([a-z0-9_]+)\s+issues=(.+)/iu', $result, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $pageType = \trim((string)($match[1] ?? ''));
                $summary = \trim((string)($match[2] ?? ''));
                if ($pageType === '') {
                    continue;
                }
                $failures[$pageType] = $this->buildRecoveredStageOnePageFailure($pageType, $summary !== '' ? $summary : $message, $summary);
            }
        }

        $matches = [];
        if (\preg_match_all('/Plan JSON page generation returned no usable blocks and is waiting for retry:\s*([a-z0-9_]+)/iu', $result, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $pageType = \trim((string)($match[1] ?? ''));
                if ($pageType === '') {
                    continue;
                }
                $existing = $failures[$pageType] ?? [];
                $existingSummary = \trim((string)($existing['validation_summary'] ?? ''));
                $failures[$pageType] = $this->buildRecoveredStageOnePageFailure(
                    $pageType,
                    'Stage-one page fanout returned a plan JSON page without usable blocks.',
                    $existingSummary
                );
            }
        }

        $matches = [];
        if (\preg_match_all('/pages\.([a-z0-9_]+)(?:\.[^=;\n]+)?=([a-z0-9_]+)/iu', $message, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $pageType = \trim((string)($match[1] ?? ''));
                $reasonCode = \trim((string)($match[2] ?? ''));
                if ($pageType === '') {
                    continue;
                }
                $existing = $failures[$pageType] ?? [];
                $summary = \trim((string)($existing['validation_summary'] ?? ''));
                if ($summary === '') {
                    $summary = 'pages.' . $pageType . '=' . ($reasonCode !== '' ? $reasonCode : 'invalid');
                } elseif ($reasonCode !== '' && !\str_contains($summary, $reasonCode)) {
                    $summary .= '; pages.' . $pageType . '=' . $reasonCode;
                }
                $failures[$pageType] = $this->buildRecoveredStageOnePageFailure(
                    $pageType,
                    \trim((string)($existing['message'] ?? '')) !== '' ? (string)$existing['message'] : 'Stage-one page needs targeted retry.',
                    $summary
                );
            }
        }

        return \array_values($failures);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecoveredStageOnePageFailure(string $pageType, string $message, string $validationSummary = ''): array
    {
        $validationSummary = \trim($validationSummary);
        $validationIssues = [];
        if ($validationSummary !== '') {
            foreach (\preg_split('/\s*;\s*/u', $validationSummary) ?: [] as $part) {
                $part = \trim((string)$part);
                if ($part === '') {
                    continue;
                }
                if (\preg_match('/^(pages\.' . \preg_quote($pageType, '/') . '(?:\.[^=]+)?)=([a-z0-9_]+)$/iu', $part, $match) !== 1) {
                    continue;
                }
                $validationIssues[] = [
                    'page_type' => $pageType,
                    'path' => (string)$match[1],
                    'field_path' => (string)$match[1],
                    'code' => (string)$match[2],
                    'reason_code' => (string)$match[2],
                    'retry_scope' => 'plan_json',
                    'severity' => 'high',
                ];
            }
        }

        return [
            'operation' => 'plan',
            'item_key' => $pageType,
            'item_type' => 'page_fanout',
            'retry_scope' => 'stage1_page',
            'page_type' => $pageType,
            'failure_source' => 'gate_contract',
            'failure_class' => '阶段一门禁/契约校验未通过',
            'message' => \trim($message) !== '' ? \trim($message) : 'Stage-one page requires retry.',
            'validation_summary' => $validationSummary,
            'validation_issues' => $validationIssues,
            'failed_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function hasQueuedPlanResumeRequest(array $content): bool
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $request = \array_replace(
            \is_array($scopePatch['_plan_sse_request'] ?? null) ? $scopePatch['_plan_sse_request'] : [],
            \is_array($content['_plan_sse_request'] ?? null) ? $content['_plan_sse_request'] : [],
            \is_array($details['_plan_sse_request'] ?? null) ? $details['_plan_sse_request'] : []
        );
        $promptMode = $this->firstNonEmptyString([$content['prompt_mode'] ?? null, $details['prompt_mode'] ?? null, $request['prompt_mode'] ?? null]);

        return $promptMode === 'resume_plan';
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
        if ($activeStatus === '') {
            $activeStatus = \trim((string)($active['queue_status'] ?? ''));
        }
        $activeQueueId = (int)($active['queue_id'] ?? 0);
        if (
            (string)($active['operation'] ?? '') === $operation
            && (string)($active['execution_token'] ?? '') === $executionToken
            && $activeStatus === 'queued'
            && ($activeQueueId === $queueId || $queueId <= 0)
        ) {
            return $fresh;
        }

        $scope['active_operation'] = \array_replace($active, [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'queued',
            'queue_status' => 'queued',
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

    private function persistPlanQueueCompletionScope(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgent $controller,
        string $publicId,
        int $adminId,
        string $executionToken,
        int $queueId
    ): void {
        $session = $sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            throw new \RuntimeException('Stage-one plan completion finalization failed: session reload failed.');
        }

        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_COMPLETION_GATE_ARTIFACT_KEYS
            )
        );
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $this->assertPlanJsonPagesTree($planJson);
        $pageTypes = $this->resolveCompletedPlanPageTypes($planJson);
        $planLocale = $this->resolveCompletedPlanLocale($scope, $planJson);
        $sourceSignature = \trim((string)($scope['plan_generated_source_signature'] ?? ''));
        if ($sourceSignature === '') {
            try {
                $sourceSignature = (string)$this->invokePrivate(
                    $controller,
                    'PlanJsonSourceSignature',
                    [\array_replace($scope, ['page_types' => $pageTypes])]
                );
            } catch (\Throwable) {
                $sourceSignature = '';
            }
        }

        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $existingPlanOperation = \is_array($activeOperations['plan'] ?? null) ? $activeOperations['plan'] : [];
        $basePlanOperation = $existingPlanOperation !== [] ? $existingPlanOperation : $activeOperation;
        $planOperation = \array_replace($basePlanOperation, [
            'operation' => 'plan',
            'execution_token' => $executionToken !== '' ? $executionToken : (string)($basePlanOperation['execution_token'] ?? ''),
            'status' => 'done',
            'queue_id' => $queueId,
            'message' => 'Stage-one plan generation completed.',
            'progress_percent' => 100,
            'queue_waiting_for_scheduler' => false,
            'can_close_stream' => true,
            'continue_other_operations' => true,
            'retry_allowed' => 0,
            'failure_mode' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations['plan'] = $planOperation;

        $patch = [
            'plan_generated_page_types' => $pageTypes,
            'plan_generated_locale' => $planLocale,
            'plan_generated_source_signature' => $sourceSignature,
            'plan_ai_generated' => (int)($scope['fake_mode'] ?? 0) === 1
                ? (int)($scope['plan_ai_generated'] ?? 0)
                : 1,
            'plan_missing_page_types' => [],
            'plan_generation_progress' => [],
            'active_operations' => $activeOperations,
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH,
        ];

        $currentActiveOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $currentActiveExecutionToken = \trim((string)($activeOperation['execution_token'] ?? ''));
        if (
            $currentActiveOperationName === ''
            || $currentActiveOperationName === 'plan'
            || ($executionToken !== '' && $currentActiveExecutionToken === $executionToken)
        ) {
            $patch['active_operation'] = $planOperation;
        }

        $sessionService->mergeScope((int)$session->getId(), $adminId, $patch);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return list<string>
     */
    private function resolveCompletedPlanPageTypes(array $planJson): array
    {
        $fromPages = [];
        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType !== '' && !\in_array($pageType, $fromPages, true)) {
                $fromPages[] = $pageType;
            }
        }

        return $fromPages;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     */
    private function resolveCompletedPlanLocale(array $scope, array $planJson): string
    {
        foreach ([
            $scope['plan_locale'] ?? null,
            $planJson['i18n']['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate) && !(\is_object($candidate) && \method_exists($candidate, '__toString'))) {
                continue;
            }
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function markPlanRetryScheduledInScope(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        string $publicId,
        int $adminId,
        string $executionToken,
        int $queueId,
        string $message
    ): void {
        try {
            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                return;
            }
            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $attemptNo = \max(0, (int)($active['attempt_no'] ?? 0)) + 1;
            $active = \array_replace($active, [
                'operation' => 'plan',
                'execution_token' => $executionToken,
                'status' => 'queued',
                'queue_id' => $queueId,
                'message' => $message,
                'retry_allowed' => 1,
                'failure_mode' => 'plan_retryable_failure',
                'queue_waiting_for_scheduler' => true,
                'can_close_stream' => true,
                'continue_other_operations' => true,
                'attempt_no' => $attemptNo,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope['active_operation'] = $active;
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $activeOperations['plan'] = $active;
            $scope['active_operations'] = $activeOperations;
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
            $scope['partial_retry_required'] = 1;
            $scope['plan_generation_last_error'] = [
                'message' => $message,
                'attempt_no' => $attemptNo,
                'max_attempts' => self::MAX_PLAN_QUEUE_ATTEMPTS,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{ok: bool, message?: string, terminal_status?: string}
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
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        if (
            !$forceRebuild
            && !$allowExistingPlan
            && $this->hasCompletedPlanJsonPagesTree($planJson)
            && !$this->hasPlanCompletionGateFailures($scope)
            && $this->hasPlanCompletionValidationReport($scope)
        ) {
            $message = (string)__('第一阶段方案已存在；队列已跳过重复生成。若需重新生成，请使用强制重建。');
            $this->persistPlanQueueStopState($sessionService, (int)$fresh->getId(), $adminId, $scope, $message, true);
            return ['ok' => false, 'message' => $message, 'terminal_status' => Queue::status_done];
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
    private function hasPlanCompletionValidationReport(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planJson = (new AiSitePlanJsonStateService())->normalizePlanJson($planJson);
        $validationReport = \is_array($scope['stage1_validation_report'] ?? null)
            ? $scope['stage1_validation_report']
            : (\is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : []);

        if ($validationReport === [] || empty($validationReport['passed'])) {
            return false;
        }

        return \is_array($planJson['pages'] ?? null) && ($planJson['pages'] ?? []) !== [];
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function hasCompletedPlanJsonPagesTree(array $planJson): bool
    {
        try {
            $this->assertPlanJsonPagesTree($planJson);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function assertPlanJsonPagesTree(array $planJson): void
    {
        if (!\is_array($planJson['pages'] ?? null) || ($planJson['pages'] ?? []) === []) {
            throw new \RuntimeException('Stage-one plan completion gate failed: plan_json.pages is missing; regenerate the plan.');
        }

        $actual = [];
        $this->collectStageOnePageTypesFromSource($planJson['pages'], $actual);
        if ($actual === []) {
            throw new \RuntimeException('Stage-one plan completion gate failed: plan_json.pages has no direct page block nodes; regenerate the plan.');
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistPlanQueueStopState(
        AiSiteAgentSessionService $sessionService,
        int $sessionId,
        int $adminId,
        array $scope,
        string $message,
        bool $skipAsDone = false
    ): void {
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($active !== []) {
            $active['status'] = $skipAsDone ? 'done' : 'stop';
            $active['message'] = $message;
            $active['retry_allowed'] = 0;
            $active['failure_mode'] = $skipAsDone ? '' : 'plan_retry_exhausted';
            $active['queue_waiting_for_scheduler'] = false;
            $active['can_close_stream'] = true;
            $active['continue_other_operations'] = true;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $scope['active_operation'] = $active;
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $activeOperations['plan'] = $active;
            $scope['active_operations'] = $activeOperations;
        }
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        if ($skipAsDone) {
            $scope['plan_generation_last_error'] = [];
        } else {
            $scope['plan_generation_last_error'] = [
                'message' => $message,
                'attempt_no' => \max(0, (int)($active['attempt_no'] ?? 0)),
                'max_attempts' => self::MAX_PLAN_QUEUE_ATTEMPTS,
                'retry_allowed' => 0,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
            $scope = $this->clearPlanGenerationRetryableFailure($scope);
        }
        $sessionService->replaceScope($sessionId, $adminId, $scope);
    }

    private function assertPlanQueueCompletionGate(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        string $publicId,
        int $adminId
    ): array {
        $session = $sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            throw new \RuntimeException('Stage-one plan completion gate failed: session reload failed.');
        }

        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_COMPLETION_GATE_ARTIFACT_KEYS
            )
        );
        if (!\is_array($scope['plan_json'] ?? null) || ($scope['plan_json'] ?? []) === []) {
            throw new \RuntimeException('Stage-one plan completion gate failed: plan_json is empty.');
        }
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $this->assertPlanJsonPagesTree($planJson);
        $validationReport = \is_array($scope['stage1_validation_report'] ?? null)
            ? $scope['stage1_validation_report']
            : (\is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : []);
        $retryablePlanMessages = $this->extractPlanRetryableFailureMessages($scope);
        if ($validationReport !== [] && empty($validationReport['passed'])) {
            if ($retryablePlanMessages !== []) {
                return $retryablePlanMessages;
            }
            throw new \RuntimeException('Stage-one plan completion gate failed: validation_report failed: ' . $this->summarizeStageOneValidationReport($validationReport));
        }
        $missingPageTypes = $this->collectMissingSelectedPageTypes($scopeService, $scope, $planJson);
        if ($missingPageTypes !== []) {
            if ($retryablePlanMessages !== []) {
                return $retryablePlanMessages;
            }
            throw new \RuntimeException(
                'Stage-one plan completion gate failed: plan_json.pages missing selected page_types: ' . \implode(', ', $missingPageTypes)
            );
        }
        $hasAiGeneratedEvidence = (int)($scope['plan_ai_generated'] ?? 0) === 1
            || (
                \trim((string)($scope['plan_generated_at'] ?? '')) !== ''
                && \is_array($planJson['pages'] ?? null)
                && ($planJson['pages'] ?? []) !== []
        );
        if ((int)($scope['fake_mode'] ?? 0) !== 1 && !$hasAiGeneratedEvidence) {
            throw new \RuntimeException('Stage-one plan completion gate failed: plan was not AI generated.');
        }

        if ((int)($scope['partial_retry_required'] ?? 0) === 1 && $retryablePlanMessages === []) {
            throw new \RuntimeException('Stage-one plan completion gate failed: partial_retry_required=1 without retryable failure details');
        }

        return $retryablePlanMessages;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return list<string>
     */
    private function collectMissingSelectedPageTypes(
        AiSiteScopeCompatibilityService $scopeService,
        array $scope,
        array $planJson
    ): array {
        try {
            $expected = $scopeService->resolveScopedPageTypes($scope);
        } catch (\Throwable) {
            $expected = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
        }
        $expected = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $expected
        ), static fn(string $value): bool => $value !== ''));
        if ($expected === []) {
            return [];
        }

        $actual = [];
        foreach ($this->stageOnePageTypeSourceCandidates($scope, $planJson) as $pageSource) {
            $this->collectStageOnePageTypesFromSource($pageSource, $actual);
        }

        $missing = [];
        foreach ($expected as $pageType) {
            if (!isset($actual[$pageType])) {
                $missing[] = $pageType;
            }
        }

        return \array_values(\array_unique($missing));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return list<mixed>
     */
    private function stageOnePageTypeSourceCandidates(array $scope, array $planJson): array
    {
        unset($scope);

        return [
            $planJson['pages'] ?? null,
        ];
    }

    /**
     * @param array<string, true> $actual
     */
    private function collectStageOnePageTypesFromSource(mixed $pageSource, array &$actual, int $depth = 0): void
    {
        if (!\is_array($pageSource) || $depth > 4) {
            return;
        }

        $directPageType = \trim((string)($pageSource['page_type'] ?? $pageSource['type'] ?? ''));
        if ($directPageType !== '') {
            if ($this->stageOnePageHasDynamicBlocks($pageSource)) {
                $actual[$directPageType] = true;
            }
            return;
        }

        foreach ($pageSource as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
            if ($pageType === '' && \is_string($key) && !\ctype_digit($key)) {
                $pageType = \trim($key);
            }
            if ($pageType !== '' && $this->stageOnePageHasDynamicBlocks($page)) {
                $actual[$pageType] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function stageOnePageHasDynamicBlocks(array $page): bool
    {
        foreach ($page as $key => $value) {
            if (!\is_string($key)
                || isset(self::PLAN_JSON_PAGE_META_KEYS[$key])
                || !\is_array($value)
                || !$this->hasStringKey($value)
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     */
    private function hasStringKey(array $value): bool
    {
        foreach (\array_keys($value) as $key) {
            if (\is_string($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summarizeStageOneValidationReport(array $report): string
    {
        $issues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
        if ($issues === []) {
            return 'unknown validation issue';
        }
        $parts = [];
        foreach (\array_slice($issues, 0, 5) as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $path = \trim((string)($issue['path'] ?? $issue['field_path'] ?? 'stage1'));
            $code = \trim((string)($issue['code'] ?? $issue['reason_code'] ?? 'invalid'));
            $parts[] = ($path !== '' ? $path : 'stage1') . '=' . ($code !== '' ? $code : 'invalid');
        }

        return $parts !== [] ? \implode('; ', $parts) : 'unknown validation issue';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasPlanCompletionGateFailures(array $scope): bool
    {
        if ((int)($scope['partial_retry_required'] ?? 0) === 1) {
            return true;
        }

        return $this->extractPlanRetryableFailureMessages($scope) !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function extractPlanRetryableFailureMessages(array $scope): array
    {
        $items = \is_array($scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items'] ?? null)
            ? $scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items']
            : [];
        $messages = [];
        foreach ($items as $fallbackKey => $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $itemKey = \trim((string)($failure['item_key'] ?? $failure['page_type'] ?? $fallbackKey));
            $message = \trim((string)($failure['message'] ?? 'retryable AI failure'));
            $line = $itemKey !== '' ? ($itemKey . ': ' . $message) : $message;
            if (\mb_strlen($line) > 500) {
                $line = \mb_substr($line, 0, 500) . '...';
            }
            if ($line !== '' && !\in_array($line, $messages, true)) {
                $messages[] = $line;
            }
        }

        return \array_values($messages);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $active
     * @return array<string, mixed>
     */
    private function markPlanGenerationFailureRetryable(array $scope, array $active, string $message, int $attemptNo): array
    {
        try {
            /** @var AiSitePlanJsonTaskService $planJsonTaskService */
            $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
            $planFailures = \is_array($scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items'] ?? null)
                ? $scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items']
                : [];
            $normalizedMessage = \mb_strtolower(\trim($message));
            $failureSource = \str_contains($normalizedMessage, 'undefined constant')
                || \str_contains($normalizedMessage, 'undefined method')
                || \str_contains($normalizedMessage, 'fatal error')
                ? 'platform'
                : (\str_contains($normalizedMessage, 'missing_page') || \str_contains($normalizedMessage, 'stage-1 plan invalid')
                    ? 'gate_assemble'
                    : 'plan_pipeline');
            $planFailures['stage1_plan'] = [
                'operation' => 'plan',
                'item_key' => 'stage1_plan',
                'item_type' => 'stage_one_plan',
                'retry_scope' => 'plan',
                'failure_source' => $failureSource,
                'failure_class' => match ($failureSource) {
                    'platform' => '平台/代码异常（非 AI 文案问题）',
                    'gate_assemble' => '阶段一总装配门禁未通过',
                    default => 'plan_json 方案流水线失败',
                },
                'message' => $message,
                'queue_id' => (int)($active['queue_id'] ?? 0),
                'execution_token' => (string)($active['execution_token'] ?? ''),
                'attempt_no' => $attemptNo,
                'failed_at' => \date('Y-m-d H:i:s'),
            ];

            return $planJsonTaskService->replaceRetryableAiFailures($scope, 'plan', $planFailures);
        } catch (\Throwable) {
            return $scope;
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearPlanGenerationRetryableFailure(array $scope): array
    {
        try {
            /** @var AiSitePlanJsonTaskService $planJsonTaskService */
            $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
            $planFailures = \is_array($scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items'] ?? null)
                ? $scope[AiSitePlanJsonTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items']
                : [];
            unset($planFailures['stage1_plan']);

            return $planJsonTaskService->replaceRetryableAiFailures($scope, 'plan', $planFailures);
        } catch (\Throwable) {
            return $scope;
        }
    }

    private function isTransientAiProviderFailure(string $message): bool
    {
        $normalized = \mb_strtolower($message);
        foreach ([
            'http 503',
            'service_unavailable',
            'service unavailable',
            'service is too busy',
            'temporarily switch',
            'too many requests',
            'rate limit',
            'timeout',
            'timed out',
        ] as $needle) {
            if (\str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPlatformPlanQueueFailure(string $message): bool
    {
        $normalized = \mb_strtolower($message);
        foreach ([
            'undefined constant',
            'undefined method',
            'fatal error',
            'parse error',
            'syntax error',
            'class not found',
            'call to a member function',
            'typeerror',
        ] as $needle) {
            if (\str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPermanentAiProviderFailure(string $message): bool
    {
        $normalized = \mb_strtolower($message);
        foreach ([
            'http 400',
            'http 401',
            'http 402',
            'http 403',
            'invalid api key',
            'api key is invalid',
            'authentication',
            'unauthorized',
            'permission denied',
            'insufficient balance',
            'billing',
            'quota exceeded',
            'account balance',
        ] as $needle) {
            if (\str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $content
     * @return array{0:array<string,mixed>,1:int,2:int}
     */
    private function beginPlanQueueAttempt(Queue &$queue, array $content, string $effectiveExecutionToken): array
    {
        if (!$this->isAutomaticPlanRetryContent($content)) {
            unset($content[self::CONTENT_AUTO_ATTEMPT_KEY], $content[self::CONTENT_MAX_AUTO_ATTEMPTS_KEY]);
        }
        $attempt = \max(0, (int)($content[self::CONTENT_AUTO_ATTEMPT_KEY] ?? 0)) + 1;
        $content[self::CONTENT_AUTO_ATTEMPT_KEY] = $attempt;
        $content[self::CONTENT_MAX_AUTO_ATTEMPTS_KEY] = self::MAX_PLAN_QUEUE_ATTEMPTS;
        if ($effectiveExecutionToken !== '') {
            $content['execution_token'] = $effectiveExecutionToken;
        }
        $this->savePlanQueueContent($queue, $content);

        return [$content, $attempt, self::MAX_PLAN_QUEUE_ATTEMPTS];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isAutomaticPlanRetryContent(array $content): bool
    {
        return !empty($content[self::CONTENT_AUTO_RETRY_SCHEDULED_KEY])
            || \array_key_exists(self::CONTENT_AUTO_ATTEMPT_KEY, $content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function canScheduleAutomaticPlanRetry(array $content): bool
    {
        return \max(0, (int)($content[self::CONTENT_AUTO_ATTEMPT_KEY] ?? 0)) < self::MAX_PLAN_QUEUE_ATTEMPTS;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function savePlanQueueContent(Queue &$queue, array $content): void
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return;
        }
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'content' => (string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)$queue->getContent()),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function canScheduleTransientPlanRetry(array $content): bool
    {
        return $this->canScheduleAutomaticPlanRetry($content)
            && \max(0, (int)($content['_provider_transient_retry_count'] ?? 0)) < self::MAX_PLAN_QUEUE_ATTEMPTS;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function canScheduleGeneralPlanRetry(array $content, string $message): bool
    {
        if ($this->isPlatformPlanQueueFailure($message)) {
            return false;
        }
        if ($this->isPermanentAiProviderFailure($message)) {
            return false;
        }

        return $this->canScheduleAutomaticPlanRetry($content)
            && \max(0, (int)($content['_plan_queue_retry_count'] ?? 0)) < self::MAX_PLAN_QUEUE_ATTEMPTS;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function canSchedulePlanCompletionGateRetry(array $content): bool
    {
        return $this->canScheduleAutomaticPlanRetry($content)
            && \max(0, (int)($content['_plan_completion_gate_retry_count'] ?? 0)) < self::MAX_PLAN_QUEUE_ATTEMPTS;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createTransientPlanRetryQueue(Queue &$queue, array &$content, string $message): int
    {
        $content['_provider_transient_retry_count'] = \max(0, (int)($content['_provider_transient_retry_count'] ?? 0)) + 1;

        return $this->prepareSamePlanQueueRetry($queue, $content, $message, 'plan_provider_transient');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createGeneralPlanRetryQueue(Queue &$queue, array &$content, string $message): int
    {
        $content['_plan_queue_retry_count'] = \max(0, (int)($content['_plan_queue_retry_count'] ?? 0)) + 1;

        return $this->prepareSamePlanQueueRetry($queue, $content, $message, 'plan_retryable_failure');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createPlanCompletionGateRetryQueue(Queue &$queue, array &$content, string $message): int
    {
        $content['_plan_completion_gate_retry_count'] = \max(0, (int)($content['_plan_completion_gate_retry_count'] ?? 0)) + 1;

        return $this->prepareSamePlanQueueRetry($queue, $content, $message, 'plan_completion_gate');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function prepareSamePlanQueueRetry(Queue &$queue, array &$content, string $message, string $retryScope): int
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            throw new \RuntimeException('Unable to mark PageBuilder plan queue retry on the same queue row.');
        }

        $request = \is_array($content['_plan_sse_request'] ?? null) ? $content['_plan_sse_request'] : [];
        $request['prompt_mode'] = 'resume_plan';
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $details['prompt_mode'] = 'resume_plan';
        $details['_plan_sse_request'] = \array_replace(
            \is_array($details['_plan_sse_request'] ?? null) ? $details['_plan_sse_request'] : [],
            ['prompt_mode' => 'resume_plan']
        );
        $content['prompt_mode'] = 'resume_plan';
        $content['_plan_sse_request'] = $request;
        $content['details'] = $details;
        $content['retry_of_queue_id'] = $queueId;
        $content['retry_reason'] = $message;
        $content['retry_scheduled_at'] = \date('Y-m-d H:i:s');
        $content['retry_scope'] = $retryScope;
        $content[self::CONTENT_AUTO_RETRY_SCHEDULED_KEY] = 1;
        $content['_force_rebuild'] = 0;

        return $queueId;
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
                'result' => $this->appendQueueResultLine($existing, $line),
            ],
        ]);
        $this->mirrorToCli($line);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function markQueueRetryScheduled(Queue &$queue, int $retryQueueId, array $content, string $message): void
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return;
        }

        $content['retry_queue_id'] = $retryQueueId;
        $line = '[' . \date('H:i:s') . '] QUEUE_RETRY same_queue=' . $retryQueueId . ' ' . $message;
        $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        $existing = \is_array($row) ? (string)($row['result'] ?? '') : '';
        $storedContent = [];
        if (\is_array($row)) {
            $decodedContent = \json_decode((string)($row['content'] ?? ''), true);
            if (\is_array($decodedContent)) {
                $storedContent = $decodedContent;
            }
        }
        if ($storedContent !== []) {
            $storedStageOneProgress = \is_array($storedContent['stage1_page_progress'] ?? null)
                ? $storedContent['stage1_page_progress']
                : [];
            $content = \array_replace($storedContent, $content);
            if ($storedStageOneProgress !== []) {
                $content['stage1_page_progress'] = $storedStageOneProgress;
            }
        }
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'status' => Queue::status_pending,
                'content' => (string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)$queue->getContent()),
                'pid' => 0,
                'finished' => 0,
                'start_at' => null,
                'end_at' => null,
                'process' => $message,
                'result' => $this->appendQueueResultLine($existing, $line),
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
                'result' => $this->appendQueueResultLine($existing, $line),
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
        $content = \json_decode((string)($row['content'] ?? ''), true);
        if (!\is_array($content)) {
            $content = \json_decode((string)$queue->getContent(), true);
        }
        if (!\is_array($content)) {
            $content = [];
        }
        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'manual_confirmation_required';
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = 'automatic_attempt_limit';
        $content[self::CONTENT_LAST_GATE_AT_KEY] = \date('Y-m-d H:i:s');
        w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'status' => Queue::status_stop,
                'pid' => 0,
                'finished' => 1,
                'content' => (string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)$queue->getContent()),
                'process' => $message,
                'result' => $this->appendQueueResultLine($existing, $line),
            ],
        ]);
        $this->mirrorToCli($line);
    }

    private function appendQueueResultLine(string $existing, string $line): string
    {
        $result = \trim($existing) === '' ? $line : $existing . PHP_EOL . $line;
        if (\strlen($result) <= self::QUEUE_RESULT_MAX_BYTES) {
            return $result;
        }

        $marker = self::QUEUE_RESULT_TRUNCATION_MARKER;
        $tailBudget = self::QUEUE_RESULT_MAX_BYTES - \strlen($marker) - \strlen(\PHP_EOL);
        if ($tailBudget <= 0) {
            return \substr($result, -self::QUEUE_RESULT_MAX_BYTES);
        }

        $tail = \substr($result, -$tailBudget);
        $newlinePos = \strpos($tail, \PHP_EOL);
        if ($newlinePos !== false && ($newlinePos + \strlen(\PHP_EOL)) < \strlen($tail)) {
            $tail = (string)\substr($tail, $newlinePos + \strlen(\PHP_EOL));
        }

        return $marker . PHP_EOL . $tail;
    }

    private function queueTrace(AiSiteQueueLogWriter $sse, string $message): void
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
