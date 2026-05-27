<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSiteWorkflowTrace;
use Weline\Ai\Service\AiRuntimeContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSiteBuildQueue implements QueueInterface
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const CONTENT_ATTEMPT_KEY = 'attempt';
    private const CONTENT_MAX_ATTEMPTS_KEY = 'max_attempts';
    private const CONTENT_LAST_GATE_REASON_KEY = 'last_gate_reason';
    private const CONTENT_LAST_GATE_AT_KEY = 'last_gate_at';
    private const CONTENT_LAST_GATE_DECISION_KEY = 'completion_gate_decision';
    private const CONTENT_LAST_GATE_SNAPSHOT_KEY = 'completion_gate_snapshot';
    private const QUEUE_SCOPE_PATCH_REDUNDANT_KEYS = [
        'build_blueprint' => true,
        'build_tasks' => true,
        'build_task_summary' => true,
        'build_plan_v2' => true,
        'plan_projection' => true,
        'content_manifest' => true,
        'build_workbench' => true,
        'build_contracts' => true,
        'render_data_contract' => true,
        'qa_report_contract' => true,
        'task_results' => true,
        'qa_report_v2' => true,
        'repair_patch' => true,
    ];
    private const BUILD_QUEUE_SCOPE_ARTIFACT_KEYS = [
        'plan_json',
        'build_plan_v2',
        'plan_projection',
        'content_manifest',
        'execution_blueprint',
        'build_blueprint',
        'build_workbench',
        'build_contracts',
        'render_data_contract',
        'task_results',
        'qa_report',
        'repair_patch',
    ];

    public function name(): string
    {
        return 'PageBuilder AI 建站构建队列';
    }

    public function tip(): string
    {
        return '异步执行 PageBuilder 建站构建任务，并通过 SSE 同步构建进度。';
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
        $content = \is_array($content) ? $content : [];
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $operation = $this->normalizeQueuedOperation((string)($content['operation'] ?? 'build'));
        $forceNewExecutionToken = \in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)
            && (int)($content['_force_rebuild'] ?? 0) === 1;
        $forceFullBuildRegeneration = $operation === 'build' && $forceNewExecutionToken;
        $effectiveExecutionToken = $executionToken;
        if ($forceNewExecutionToken) {
            $effectiveExecutionToken = \sprintf(
                '%s-force-%s',
                $executionToken !== '' ? $executionToken : 'queue',
                \substr(\sha1((string)\microtime(true) . ':' . (string)\mt_rand()), 0, 10)
            );
        }
        $queueId = (int)$queue->getId();
        [$content, $attempt, $maxAttempts] = $this->beginQueueAttempt(
            $queue,
            $content,
            $effectiveExecutionToken !== '' ? $effectiveExecutionToken : $executionToken
        );
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];

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
            /** @var AiSiteBuildTaskService $buildTaskService */
            $buildTaskService = ObjectManager::getInstance(AiSiteBuildTaskService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('会话不存在或无权访问。');
            }
            AiSiteWorkflowTrace::log('queue_build_execute_start', [
                'public_id' => $publicId,
                'queue_id' => $queueId,
                'operation' => $operation,
                'execution_token' => $effectiveExecutionToken,
                'force_rebuild' => $forceFullBuildRegeneration,
            ]);
            $this->appendQueueLifecycleLine($queue, '已加载会话 session_id=' . (int)$session->getId());
            $supersedingQueueRow = $this->findSupersedingQueueRow(
                $queueId,
                $queue->getBizKey(),
                $operation,
                $effectiveExecutionToken
            );
            if ($supersedingQueueRow !== []) {
                $newerQueueId = (int)($supersedingQueueRow['queue_id'] ?? 0);
                $message = 'Superseded by newer PageBuilder queue #' . $newerQueueId . '; skipped duplicate AI execution.';
                $this->appendQueueLifecycleLine($queue, $message);

                return $message;
            }
            $activeQueueId = $this->resolveActiveQueueIdForQueuedOperation(
                $sessionService,
                $scopeService,
                $session,
                $operation,
                $effectiveExecutionToken
            );
            if ($activeQueueId > 0 && $activeQueueId !== $queueId && $activeQueueId > $queueId) {
                $message = 'Active operation is already owned by newer queue #' . $activeQueueId . '; skipped duplicate AI execution.';
                $this->appendQueueLifecycleLine($queue, $message);

                return $message;
            }
            if ($forceFullBuildRegeneration) {
                $session = $this->applyForceBuildQueuePreset($sessionService, $scopeService, $session, $adminId);
                $this->appendQueueLifecycleLine(
                    $queue,
                    '检测到 _force_rebuild=1，已换新 execution_token 以允许重新认领构建，token=' . \substr($effectiveExecutionToken, 0, 24) . '…'
                );
            }

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                $operation,
                $effectiveExecutionToken
            );
            $this->appendQueueLifecycleLine(
                $queue,
                '已同步 active_operation=queued operation=' . $operation . ' execution_token=' . \substr($effectiveExecutionToken, 0, 12) . '…'
            );

            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $session)
            );
            $confirmedScope = $scope;
            if ($operation === 'build') {
                $scopePatch = $buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $confirmedScope);
                if ($scopePatch !== []) {
                    $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                }
                $scope = $buildTaskService->restoreBuildPlanContract($scope, $confirmedScope);
                $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
                $scope = $buildTaskService->ensureTaskScope(
                    $scope,
                    \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                    $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
                );
                if ($forceFullBuildRegeneration) {
                    $scope = $buildTaskService->clearBuildArtifactsForRegeneration($scope);
                    $scope = $buildTaskService->resetBuildTasksToPendingForRebuild($scope, false);
                }
                $normalizedScope = $buildTaskService->normalizeConfirmedBuildPlanFlag($scope);
                $scopeChanged = $normalizedScope !== $confirmedScope;
                $scope = $normalizedScope;
            } elseif (\in_array($operation, ['block_regenerate', 'block_partial_patch'], true) && $scopePatch !== []) {
                $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                $scopeChanged = $scope !== $confirmedScope;
            } else {
                $scopeChanged = false;
            }
            if ($scopeChanged) {
                $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
                $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            }
            if (!$buildTaskService->hasConfirmedBuildPlanForBuild($scope)) {
                throw new \RuntimeException('请先确认建站方案，再开始执行构建。');
            }

            $sse = new QueueDbWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                $operation,
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
            $this->queueTrace($sse, 'QueueDbWriter 已创建，构建进度将写入队列 result');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, $operation, 'queue']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, '认领跳过：duplicate_stream（仍视为重复构建，可加 -f 换新令牌）');

                    return '检测到重复构建任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }
            $this->queueTrace($sse, '认领成功 claimActiveOperationExecution ok，进入队列操作执行 operation=' . $operation);

            // mergeScope 只更新库内 scope；内存中的 $session 可能仍带旧 build_tasks，会导致 ensureTaskScope 继续合并为 done 从而秒结束。
            $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;

            // 断点续生成入口防御：build operation 进入控制器之前，先把上次硬崩（OOM/kill -9/Worker 死亡，
            // 没走 catch 分支）残留的 status=running 任务清回 pending+attempt_no=0。这样下次
            // pickConcurrentTasks 能拾取干净的 task，避免 markTaskRunning 的 bumpAttempt 把
            // attempt_no 累计到 BUILD_TASK_MAX_GENERATION_ATTEMPTS=3 之后被永久 failed，
            // 让单页/单 section 的续生成可在不依赖 -f 的前提下成立。
            // controller 入口 runHtmlBlocksBuildOperationV3 也有兜底 reset，这里属于双层防御。
            if ($operation === 'build') {
                $resumeScope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $session)
                );
                if ($forceFullBuildRegeneration) {
                    $resetScope = $buildTaskService->clearBuildArtifactsForRegeneration($resumeScope);
                    $resetScope = $buildTaskService->resetBuildTasksToPendingForRebuild($resetScope, false);
                    if ($resetScope !== $resumeScope) {
                        $sessionService->replaceScope((int)$session->getId(), $adminId, $resetScope);
                        $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                        $this->queueTrace($sse, '强制重新生成：已在执行前清空旧构建产物并重置全部构建任务。');
                    }
                }
                $resumeScope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $session)
                );
                $resetScope = $buildTaskService->resetRunningTasksForInterruptedBuild(
                    $resumeScope,
                    'Queue restart: clearing stale running tasks for resume.'
                );
                if ($resetScope !== $resumeScope) {
                    $sessionService->replaceScope((int)$session->getId(), $adminId, $resetScope);
                    $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                    $this->queueTrace($sse, '入口已清理脏 running 状态，断点续生成就绪');
                }
            }

            $operationContexts = $operation === 'block_regenerate'
                ? $this->resolveQueuedOperationContexts($content, $sessionService, $session, $scopeService)
                : [];

            if ($operation === 'block_regenerate') {
                foreach ($operationContexts as $operationContext) {
                    $this->invokePrivate($controller, 'runRegenerateBlockOperation', [
                        $sse,
                        $session,
                        $adminId,
                        (string)($operationContext['page_type'] ?? ''),
                        (string)($operationContext['component_code'] ?? ''),
                        (string)($operationContext['instruction'] ?? ''),
                    ]);
                    $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                }
            } elseif ($operation === 'block_partial_patch') {
                $operationContext = $this->resolveQueuedOperationContext($content, $sessionService, $session, $scopeService);
                $this->invokePrivate($controller, 'runBlockPartialPatchOperation', [
                    $sse,
                    $session,
                    $adminId,
                    (string)($operationContext['page_type'] ?? ''),
                    (string)($operationContext['component_code'] ?? ''),
                    (string)($operationContext['instruction'] ?? ''),
                    $effectiveExecutionToken,
                ]);
            } elseif ($operation === 'regenerate_page') {
                $this->invokePrivate($controller, 'runRegeneratePageOperation', [
                    $sse,
                    $session,
                    $adminId,
                    $this->resolveQueuedPageType($content, $sessionService, $session, $scopeService),
                ]);
            } else {
                $this->invokePrivate($controller, 'runBuildOperation', [$sse, $session, $adminId]);
            }
            $this->queueTrace($sse, '队列操作已返回 operation=' . $operation);

            if (\in_array($operation, ['build', 'regenerate_page'], true)) {
                $gateAction = $this->finalizeQueueBuildCompletion(
                    $queue,
                    $sessionService,
                    $scopeService,
                    $buildTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId,
                    $attempt,
                    $maxAttempts
                );
                if (($gateAction['action'] ?? '') === 'retryable') {
                    $retryMessage = (string)($gateAction['message'] ?? 'Build queue marked retryable after completion gate failure.');
                    $this->queueTrace($sse, 'completion gate blocked, queue marked retryable: ' . $retryMessage);

                    return $retryMessage;
                }
                $this->markQueueBuildOperationPassedGate(
                    $sessionService,
                    $scopeService,
                    $buildTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId
                );
            } elseif (\in_array($operation, ['block_regenerate', 'block_partial_patch'], true)) {
                $this->markQueueBuildOperationPassedGate(
                    $sessionService,
                    $scopeService,
                    $buildTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId
                );
            }
            if ($forceNewExecutionToken) {
                $this->clearQueueForceBuildMarker($sessionService, (int)$session->getId(), $adminId);
            }

            $doneMessage = $this->buildOperationDoneMessage($operation);
            $this->queueTrace($sse, '队列执行成功：' . $doneMessage);
            $this->markQueueDone($queue, $doneMessage);
            $sse->complete();

            return $doneMessage;
        } catch (\Throwable $throwable) {
            $diagnostic = $this->formatThrowableDiagnostic($throwable);
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '异常：' . $diagnostic);
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（SSE 未初始化）：' . $diagnostic);
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $throwable->getMessage());
            throw new \RuntimeException('构建失败：' . $throwable->getMessage(), 0, $throwable);
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

    /**
     * -f：换新 execution_token + 将 build_tasks 全部置回 pending，否则任务已 done 会秒结束且不调 AI。
     * 页面重建也必须支持该语义，否则 regenerate_page 失败后会复用旧 token 并触发 duplicate_stream。
     */
    private function normalizeQueuedOperation(string $operation): string
    {
        $operation = \trim($operation);

        return \in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true) ? $operation : 'build';
    }

    private function buildOperationDoneMessage(string $operation): string
    {
        return match ($operation) {
            'block_regenerate' => '区块重建完成。',
            'block_partial_patch' => '区块局部修改完成。',
            'regenerate_page' => '页面重新生成完成。',
            default => '构建完成。',
        };
    }

    private function resolveQueuedPageType(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): string {
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];
        $pageType = $this->firstNonEmptyString([
            $content['page_type'] ?? null,
            $details['page_type'] ?? null,
            $content['page_key'] ?? null,
            $details['page_key'] ?? null,
            $active['page_type'] ?? null,
            $activeDetails['page_type'] ?? null,
            $active['page_key'] ?? null,
            $activeDetails['page_key'] ?? null,
            $scope['preview_page_type'] ?? null,
            $scope['preview_page_key'] ?? null,
        ]);
        if ($pageType === '') {
            $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
            $normalizedPageTypes = [];
            foreach ($pageTypes as $candidate) {
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $candidate = \trim((string)$candidate);
                if ($candidate !== '') {
                    $normalizedPageTypes[] = $candidate;
                }
            }
            if (\in_array('home_page', $normalizedPageTypes, true)) {
                $pageType = 'home_page';
            } elseif ($normalizedPageTypes !== []) {
                $pageType = (string)$normalizedPageTypes[0];
            }
        }
        if ($pageType === '') {
            throw new \RuntimeException('Page regenerate queue context is missing page_type.');
        }

        return $pageType;
    }

    /**
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function resolveQueuedOperationContexts(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): array {
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];

        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $active['page_type'] ?? null, $activeDetails['page_type'] ?? null]);
        $pageTypes = $this->mergeStringLists([
            $content['page_types'] ?? null,
            $details['page_types'] ?? null,
            $active['page_types'] ?? null,
            $activeDetails['page_types'] ?? null,
            $content['page_keys'] ?? null,
            $details['page_keys'] ?? null,
        ]);
        if ($pageTypes === [] && $pageType !== '') {
            $pageTypes = [$pageType];
        }

        $instruction = $this->firstNonEmptyString([$content['instruction'] ?? null, $details['instruction'] ?? null, $active['instruction'] ?? null, $activeDetails['instruction'] ?? null]);
        $targetContexts = $this->buildQueuedTargetOperationContexts([$details, $content], $pageType, $instruction);
        if ($targetContexts !== []) {
            return $targetContexts;
        }
        $targetContexts = $this->buildQueuedTargetOperationContexts([$activeDetails, $active], $pageType, $instruction);
        if ($targetContexts !== []) {
            return $targetContexts;
        }

        $singleComponentCode = $this->firstNonEmptyString([
            $content['component_code'] ?? null,
            $details['component_code'] ?? null,
            $active['component_code'] ?? null,
            $activeDetails['component_code'] ?? null,
            $content['section_code'] ?? null,
            $details['section_code'] ?? null,
            $active['section_code'] ?? null,
            $activeDetails['section_code'] ?? null,
        ]);
        $componentCodes = $this->mergeStringLists([
            $singleComponentCode,
            $content['component_codes'] ?? null,
            $details['component_codes'] ?? null,
            $active['component_codes'] ?? null,
            $activeDetails['component_codes'] ?? null,
            $content['section_codes'] ?? null,
            $details['section_codes'] ?? null,
        ]);
        if ($componentCodes === []) {
            $singleBlockCode = $this->firstNonEmptyString([
                $content['block_id'] ?? null,
                $details['block_id'] ?? null,
                $active['block_id'] ?? null,
                $activeDetails['block_id'] ?? null,
                $content['block_key'] ?? null,
                $details['block_key'] ?? null,
                $active['block_key'] ?? null,
                $activeDetails['block_key'] ?? null,
            ]);
            $componentCodes = $this->mergeStringLists([
                $singleBlockCode,
                $content['block_ids'] ?? null,
                $details['block_ids'] ?? null,
                $active['block_ids'] ?? null,
                $activeDetails['block_ids'] ?? null,
                $content['block_keys'] ?? null,
                $details['block_keys'] ?? null,
                $active['block_keys'] ?? null,
                $activeDetails['block_keys'] ?? null,
            ]);
        }
        if ($componentCodes === []) {
            $componentCodes = $this->mergeStringLists([
                $content['task_key'] ?? null,
                $details['task_key'] ?? null,
                $active['task_key'] ?? null,
                $activeDetails['task_key'] ?? null,
                $content['task_keys'] ?? null,
                $details['task_keys'] ?? null,
                $active['task_keys'] ?? null,
                $activeDetails['task_keys'] ?? null,
            ]);
        }

        if ($pageTypes === [] || $componentCodes === []) {
            throw new \RuntimeException('Block queue context is missing page_type or component_code.');
        }

        $contexts = [];
        foreach ($componentCodes as $index => $componentCode) {
            $contextPageType = (string)($pageTypes[$index] ?? $pageTypes[0] ?? '');
            if ($contextPageType === '' || $componentCode === '') {
                continue;
            }
            $contexts[] = [
                'page_type' => $contextPageType,
                'component_code' => $componentCode,
                'instruction' => $instruction,
            ];
        }

        if ($contexts === []) {
            throw new \RuntimeException('Block queue context is missing page_type or component_code.');
        }

        return $this->uniqueQueuedOperationContexts($contexts);
    }

    /**
     * @param list<array<string, mixed>> $targetSources
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function buildQueuedTargetOperationContexts(array $targetSources, string $defaultPageType, string $defaultInstruction): array
    {
        $contexts = [];
        foreach ($targetSources as $source) {
            $targets = \is_array($source['targets'] ?? null) ? $source['targets'] : [];
            foreach ($targets as $target) {
                if (!\is_array($target)) {
                    continue;
                }
                $pageType = $this->firstNonEmptyString([
                    $target['page_type'] ?? null,
                    $target['page_key'] ?? null,
                    $defaultPageType,
                ]);
                $componentCode = $this->firstNonEmptyString([
                    $target['section_code'] ?? null,
                    $target['component_code'] ?? null,
                    $target['block_id'] ?? null,
                    $target['block_key'] ?? null,
                    $target['task_key'] ?? null,
                ]);
                if ($pageType === '' || $componentCode === '') {
                    continue;
                }
                $contexts[] = [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'instruction' => $this->firstNonEmptyString([$target['instruction'] ?? null, $defaultInstruction]),
                ];
            }
        }

        return $this->uniqueQueuedOperationContexts($contexts);
    }

    /**
     * @param list<array{page_type: string, component_code: string, instruction: string}> $contexts
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function uniqueQueuedOperationContexts(array $contexts): array
    {
        $unique = [];
        $seen = [];
        foreach ($contexts as $context) {
            $pageType = \trim((string)($context['page_type'] ?? ''));
            $componentCode = \trim((string)($context['component_code'] ?? ''));
            if ($pageType === '' || $componentCode === '') {
                continue;
            }
            $key = $pageType . '|' . $componentCode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = [
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'instruction' => \trim((string)($context['instruction'] ?? '')),
            ];
        }

        return $unique;
    }

    /**
     * @return array{page_type: string, component_code: string, instruction: string}
     */
    private function resolveQueuedOperationContext(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): array {
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];

        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $active['page_type'] ?? null, $activeDetails['page_type'] ?? null]);
        $componentCode = $this->firstNonEmptyString([
            $content['block_id'] ?? null,
            $details['block_id'] ?? null,
            $active['block_id'] ?? null,
            $activeDetails['block_id'] ?? null,
            $content['component_code'] ?? null,
            $details['component_code'] ?? null,
            $active['component_code'] ?? null,
            $activeDetails['component_code'] ?? null,
            $content['block_key'] ?? null,
            $details['block_key'] ?? null,
            $active['block_key'] ?? null,
            $activeDetails['block_key'] ?? null,
            $content['section_code'] ?? null,
            $details['section_code'] ?? null,
            $active['section_code'] ?? null,
            $activeDetails['section_code'] ?? null,
            $content['task_key'] ?? null,
            $details['task_key'] ?? null,
            $active['task_key'] ?? null,
            $activeDetails['task_key'] ?? null,
        ]);
        $instruction = $this->firstNonEmptyString([$content['instruction'] ?? null, $details['instruction'] ?? null, $active['instruction'] ?? null, $activeDetails['instruction'] ?? null]);

        if ($pageType === '' || $componentCode === '') {
            throw new \RuntimeException('Block queue context is missing page_type or component_code.');
        }

        return [
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'instruction' => $instruction,
        ];
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

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function mergeStringLists(array $values): array
    {
        $merged = [];
        foreach ($values as $value) {
            foreach ($this->stringListFromMixed($value) as $item) {
                if ($item !== '' && !\in_array($item, $merged, true)) {
                    $merged[] = $item;
                }
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function stringListFromMixed(mixed $value): array
    {
        if (\is_array($value)) {
            return \array_values(\array_filter(\array_map(
                static fn($item): string => \is_scalar($item) ? \trim((string)$item) : '',
                $value
            ), static fn(string $item): bool => $item !== ''));
        }
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            $item = \trim((string)$value);
            return $item !== '' ? [$item] : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBuildQueueScope(
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session
    ): array {
        return $sessionService->loadScopeForStage(
            $session,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            self::BUILD_QUEUE_SCOPE_ARTIFACT_KEYS
        );
    }

    private function applyForceBuildQueuePreset(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        /** @var AiSiteBuildTaskService $buildTaskService */
        $buildTaskService = ObjectManager::getInstance(AiSiteBuildTaskService::class);
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage(
                $fresh,
                AiSiteAgentSession::STAGE_PLAN,
                ['build_plan_v2', 'plan_projection', 'content_manifest']
            )
        );
        $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $scope = $buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $pageTypes = $scopeService->resolveScopedPageTypes($scope);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0 && $pageTypes !== []) {
            /** @var AiSiteVirtualThemeService $virtualThemeService */
            $virtualThemeService = ObjectManager::getInstance(AiSiteVirtualThemeService::class);
            $virtualThemeService->resetGeneratedPageLayoutsForRebuild($virtualThemeId, $pageTypes);
        }
        $scope = $buildTaskService->clearBuildArtifactsForRegeneration($scope);
        $scope = $buildTaskService->resetBuildTasksToPendingForRebuild($scope, false);
        $sessionService->mergeScope((int)$fresh->getId(), $adminId, \array_replace($buildTaskService->extractBuildPlanDerivedScopePatch($scope), [
            'build_blueprint' => \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [],
            'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
            'virtual_pages_by_type' => [],
            'pagebuilder_pages_by_type' => [],
            'materialized_pages_by_type' => [],
            'page_type_layouts' => [],
            'pending_generation_page_types' => [],
            'build_summary' => [],
            'build_task_summary' => [],
            'build_workbench' => [],
            'build_contracts' => [],
            'render_data_contract' => [],
            'qa_report_contract' => [],
            'publish_verification' => [],
            'pre_publish_visual_urls' => [],
            'preview_full_url' => '',
            'visual_preview_url' => '',
            'visual_edit_url' => '',
            'can_publish' => 0,
            'site_ready' => 0,
            'latest_build_failed' => 0,
            'latest_build_failure' => [],
            'publish_blocked_by_latest_ai_failure' => 0,
            'publish_blocked_reason' => '',
            'retryable_ai_failures' => \is_array($scope['retryable_ai_failures'] ?? null) ? $scope['retryable_ai_failures'] : [],
            'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
            'next_stage_blocked_by_ai_failures' => (int)($scope['next_stage_blocked_by_ai_failures'] ?? 0),
            '_build_regeneration' => \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [],
            '_queue_force_build' => [
                'active' => 1,
                'at' => \date('Y-m-d H:i:s'),
            ],
        ]));

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @return array{action:string,message:string}
     */
    private function finalizeQueueBuildCompletion(
        Queue &$queue,
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteBuildTaskService $buildTaskService,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId,
        int $attempt,
        int $maxAttempts
    ): array {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $blueprintTasks = \is_array($scope['build_blueprint']['tasks'] ?? null) ? $scope['build_blueprint']['tasks'] : [];
        if ($blueprintTasks === []) {
            $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
            $restoredScope = $buildTaskService->ensureTaskScope(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            );
            $restoredScope = $buildTaskService->resetBuildTasksToPendingForRebuild($restoredScope, true);
            if ($restoredScope !== $scope) {
                $sessionService->replaceScope((int)$fresh->getId(), $adminId, $restoredScope);
                $fresh = $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
                $scope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $fresh)
                );
            }
        }
        $finalizedScope = $buildTaskService->finalizeBuildTaskStatesAfterRunLoop($scope);
        if ($finalizedScope !== $scope) {
            $sessionService->replaceScope((int)$fresh->getId(), $adminId, $finalizedScope);
            $fresh = $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $fresh)
            );
        } else {
            $scope = $finalizedScope;
        }
        $gate = $buildTaskService->inspectBuildCompletionGate($scope);
        $scope['build_task_summary'] = \is_array($gate['summary'] ?? null) ? $gate['summary'] : [];
        $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
        $buildSummary['completion_gate'] = $this->stripGateSummary($gate);
        $buildSummary['last_gate_checked_at'] = \date('Y-m-d H:i:s');
        $scope['build_summary'] = $buildSummary;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        $content = $this->decodeQueueContent($queue);
        $content[self::CONTENT_ATTEMPT_KEY] = $attempt;
        $content[self::CONTENT_MAX_ATTEMPTS_KEY] = $maxAttempts;
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = (string)($gate['reason'] ?? ($gate['passed'] ? 'passed' : 'completion_gate_failed'));
        $content[self::CONTENT_LAST_GATE_AT_KEY] = \date('Y-m-d H:i:s');
        $content[self::CONTENT_LAST_GATE_SNAPSHOT_KEY] = $this->stripGateSummary($gate);

        if (!empty($gate['passed'])) {
            $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'passed';
            $this->saveQueueContent($queue, $content);

            return [
                'action' => 'passed',
                'message' => '',
            ];
        }

        $message = $this->formatBuildCompletionGateBlockedMessage($buildTaskService, $gate, $operation);
        if ($this->shouldRetryBuildQueue($gate) && $attempt < $maxAttempts) {
            $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'retryable';
            $retryQueueId = $this->createCompletionGateRetryQueue($queue, $content, $message);
            $scope = $buildTaskService->resetUnfinishedTasksForQueueRetry($scope, $message);
            $scope = $this->patchBuildActiveOperationForRetry(
                $scope,
                $operation,
                $retryQueueId,
                $attempt,
                $maxAttempts,
                $executionToken,
                $message,
                $gate
            );
            $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);
            $sessionService->appendEvent(
                (int)$fresh->getId(),
                $adminId,
                'pagebuilder_queue_retry_scheduled',
                [
                    'queue_id' => $retryQueueId,
                    'retry_of_queue_id' => $queueId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
                    'completion_gate_snapshot' => $this->stripGateSummary($gate),
                ],
                AiSiteAgentSession::STAGE_VISUAL_EDIT
            );
            $this->markQueueRetryScheduled($queue, $retryQueueId, $content, $message);

            return [
                'action' => 'retryable',
                'message' => $message . ' Queue #' . $retryQueueId . ' marked retryable.',
            ];
        }

        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'error';
        $this->saveQueueContent($queue, $content);
        $scope = $this->patchBuildActiveOperationForGateFailure(
            $scope,
            $operation,
            $queueId,
            $attempt,
            $maxAttempts,
            $executionToken,
            $message,
            $gate
        );
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        throw new \RuntimeException($message);
    }

    private function markQueueBuildOperationPassedGate(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteBuildTaskService $buildTaskService,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId
    ): void {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $gate = $buildTaskService->inspectBuildCompletionGate($scope);
        $fullBuildGatePassed = !empty($gate['passed']);
        $isScopedBuildOperation = \in_array($operation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true);
        if (!$fullBuildGatePassed && !$isScopedBuildOperation) {
            return;
        }

        $now = \date('Y-m-d H:i:s');
        $message = $this->buildOperationDoneMessage($operation);
        $operationStatePatch = [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'done',
            'queue_id' => $queueId,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'progress_percent' => 100,
            'failure_mode' => '',
            'retry_allowed' => 0,
            'retryable_ai_failure_count' => 0,
            'queue_waiting_for_scheduler' => false,
            'can_close_stream' => true,
            'continue_other_operations' => false,
        ];

        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = \trim((string)($active['operation'] ?? ''));
        $activeToken = \trim((string)($active['execution_token'] ?? ''));
        if (
            $active === []
            || $activeOperation === $operation
            || $this->executionTokenMatches($activeToken, $executionToken)
        ) {
            $scope['active_operation'] = \array_replace($active, $operationStatePatch);
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $operationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        $operationToken = \trim((string)($operationState['execution_token'] ?? ''));
        if (
            $operationState === []
            || $this->executionTokenMatches($operationToken, $executionToken)
        ) {
            $activeOperations[$operation] = \array_replace($operationState, $operationStatePatch);
            $scope['active_operations'] = $activeOperations;
        }

        if ($fullBuildGatePassed) {
            $scope = $buildTaskService->clearRetryableAiFailures($scope, 'build');
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $scope['can_publish'] = 1;
            $scope['site_ready'] = 1;
            $scope['latest_build_failed'] = 0;
            $scope['latest_build_failure'] = [];
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            $scope['publish_blocked_reason'] = '';
            $scope['next_stage_blocked_by_ai_failures'] = 0;
            $scope['build_task_summary'] = \is_array($gate['summary'] ?? null) ? $gate['summary'] : [];
            $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
            $buildSummary['can_publish'] = true;
            $buildSummary['active_operation'] = $operation;
            $buildSummary['last_generated_at'] = $now;
            $scope['build_summary'] = $buildSummary;
        } elseif ($isScopedBuildOperation) {
            $scope = $this->clearResolvedScopedBuildOperationFailureState(
                $scope,
                $operation,
                $executionToken,
                $buildTaskService
            );
            $scope['build_task_summary'] = \is_array($gate['summary'] ?? null) ? $gate['summary'] : [];
            $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
            $buildSummary['active_operation'] = $operation;
            $buildSummary['last_scoped_operation_at'] = $now;
            $scope['build_summary'] = $buildSummary;
        }

        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearResolvedScopedBuildOperationFailureState(
        array $scope,
        string $operation,
        string $executionToken,
        AiSiteBuildTaskService $buildTaskService
    ): array {
        if (isset($scope['retryable_ai_failures']['build']['items'][$operation])) {
            unset($scope['retryable_ai_failures']['build']['items'][$operation]);
        }
        $scope = $buildTaskService->clearResolvedRetryableAiFailures($scope);
        $buildLedger = $buildTaskService->getRetryableAiFailures($scope, 'build');
        $items = \is_array($buildLedger['build']['items'] ?? null) ? $buildLedger['build']['items'] : [];
        if ($items !== []) {
            foreach ($items as $itemKey => $item) {
                if (!\is_array($item)) {
                    unset($items[$itemKey]);
                    continue;
                }
                $itemOperation = \trim((string)($item['operation'] ?? ''));
                $retryOperation = \trim((string)($item['retry_operation'] ?? ''));
                $failureToken = \trim((string)($item['execution_token'] ?? ''));
                if (
                    $itemKey === $operation
                    || $itemOperation === $operation
                    || $retryOperation === $operation
                    || ($failureToken !== '' && $this->executionTokenMatches($failureToken, $executionToken))
                ) {
                    unset($items[$itemKey]);
                }
            }
        }
        $scope = $buildTaskService->replaceRetryableAiFailures($scope, 'build', $items);

        $latestFailure = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
        $latestOperation = \trim((string)($latestFailure['operation'] ?? ''));
        if ($latestOperation === $operation && $items === []) {
            $scope['latest_build_failed'] = 0;
            $scope['latest_build_failure'] = [];
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            $scope['publish_blocked_reason'] = '';
            $scope['next_stage_blocked_by_ai_failures'] = 0;
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function shouldRetryBuildQueue(array $gate): bool
    {
        $reason = \trim((string)($gate['reason'] ?? ''));
        if (!empty($gate['passed']) || $reason === 'cancelled_build_tasks') {
            return false;
        }

        if ($reason === '') {
            return (int)($gate['total'] ?? 0) > 0;
        }

        return \in_array($reason, [
            'missing_build_blueprint_tasks',
            'failed_build_tasks',
            'invalid_generated_artifacts',
            'duplicate_generated_artifacts',
            'unfinished_build_tasks',
        ], true);
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function formatBuildCompletionGateBlockedMessage(
        AiSiteBuildTaskService $buildTaskService,
        array $gate,
        string $operation
    ): string {
        $detail = $buildTaskService->formatBuildCompletionGateFailureDetail($gate);
        $message = \sprintf(
            'Build queue operation %s cannot finish while completion gate is blocked: total=%d pending=%d running=%d failed=%d cancelled=%d invalid_artifacts=%d duplicate_artifacts=%d reason=%s.',
            $operation,
            (int)($gate['total'] ?? 0),
            (int)($gate['pending'] ?? 0),
            (int)($gate['running'] ?? 0),
            (int)($gate['failed'] ?? 0),
            (int)($gate['cancelled'] ?? 0),
            (int)($gate['invalid_artifacts'] ?? 0),
            (int)($gate['duplicate_artifacts'] ?? 0),
            (string)($gate['reason'] ?? '')
        );
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForRetry(
        array $scope,
        string $operation,
        int $queueId,
        int $attempt,
        int $maxAttempts,
        string $executionToken,
        string $message,
        array $gate
    ): array {
        $now = \date('Y-m-d H:i:s');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $startedAt = \trim((string)($activeOperation['started_at'] ?? ''));
        if ($startedAt === '') {
            $startedAt = $now;
        }
        $operationState = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'queued',
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'message' => $message,
            'queue_waiting_for_scheduler' => true,
            'started_at' => $startedAt,
            'updated_at' => $now,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
            'completion_gate_snapshot' => $this->stripGateSummary($gate),
            'can_close_stream' => true,
            'continue_other_operations' => true,
        ]);

        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $operationState;
        $scope['active_operations'] = $activeOperations;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForGateFailure(
        array $scope,
        string $operation,
        int $queueId,
        int $attempt,
        int $maxAttempts,
        string $executionToken,
        string $message,
        array $gate
    ): array {
        $now = \date('Y-m-d H:i:s');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operationState = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'error',
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'queue_waiting_for_scheduler' => false,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
            'completion_gate_snapshot' => $this->stripGateSummary($gate),
            'can_close_stream' => false,
            'continue_other_operations' => false,
        ]);
        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $operationState;
        $scope['active_operations'] = $activeOperations;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        $scope['_queue_force_build'] = [
            'active' => 0,
            'consumed_at' => $now,
            'failure_queue_id' => $queueId,
        ];
        $scope['_build_regeneration'] = [
            'active' => 0,
            'finished_at' => $now,
        ];

        return $scope;
    }

    private function clearQueueForceBuildMarker(AiSiteAgentSessionService $sessionService, int $sessionId, int $adminId): void
    {
        try {
            $sessionService->mergeScope($sessionId, $adminId, [
                '_queue_force_build' => [
                    'active' => 0,
                    'consumed_at' => \date('Y-m-d H:i:s'),
                ],
                '_build_regeneration' => [
                    'active' => 0,
                    'finished_at' => \date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    private function updateSessionError(string $publicId, int $adminId, string $executionToken, string $message): void
    {
        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
            /** @var AiSiteBuildTaskService $buildTaskService */
            $buildTaskService = ObjectManager::getInstance(AiSiteBuildTaskService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                return;
            }

            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $session)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $active['status'] = 'error';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $active['queue_waiting_for_scheduler'] = false;
            $active['can_close_stream'] = false;
            $active['continue_other_operations'] = false;
            $scope['active_operation'] = $active;
            $operation = \trim((string)($active['operation'] ?? ''));
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            if ($operation !== '' && \is_array($activeOperations[$operation] ?? null)) {
                $operationState = $activeOperations[$operation];
                if ((string)($operationState['execution_token'] ?? '') === $executionToken) {
                    $operationState['status'] = 'error';
                    $operationState['message'] = $message;
                    $operationState['updated_at'] = $active['updated_at'];
                    $operationState['queue_waiting_for_scheduler'] = false;
                    $operationState['can_close_stream'] = false;
                    $operationState['continue_other_operations'] = false;
                    $activeOperations[$operation] = $operationState;
                    $scope['active_operations'] = $activeOperations;
                }
            }
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            if (\in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
                $failurePayload = $this->buildPublishBlockingAiFailurePayload($operation, $message);
                $scope['latest_build_failed'] = 1;
                $scope['latest_build_failure'] = $failurePayload;
                $scope['publish_blocked_by_latest_ai_failure'] = 1;
                $scope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failurePayload);
            }
            if ($operation === 'build') {
                $scope = $buildTaskService->resetRunningTasksForInterruptedBuild(
                    $scope,
                    'Build interrupted before task completion: ' . $message
                );
            }
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{blocked:bool,operation:string,status:string,message:string}
     */
    private function buildPublishBlockingAiFailurePayload(string $operation, string $message): array
    {
        $message = \trim($message);
        if ($message === '') {
            $message = 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return [
            'blocked' => true,
            'operation' => $operation !== '' ? $operation : 'build',
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $failure
     */
    private function formatPublishBlockedByAiFailureMessage(array $failure): string
    {
        $message = \trim((string)($failure['message'] ?? ''));
        if ($message === '') {
            return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes. Error: ' . $message;
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
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($active['status'] ?? ''));
        $activeQueueId = (int)($active['queue_id'] ?? 0);
        if (
            (string)($active['operation'] ?? '') === $operation
            && $this->executionTokenMatches((string)($active['execution_token'] ?? ''), $executionToken)
            && \in_array($activeStatus, ['queued', 'running'], true)
            && $activeQueueId > 0
            && $activeQueueId !== $queueId
            && $activeQueueId > $queueId
        ) {
            throw new \RuntimeException('Duplicate build queue is superseded by active queue #' . $activeQueueId . '.');
        }
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

    /**
     * @return array<string, mixed>
     */
    private function findSupersedingQueueRow(int $queueId, string $bizKey, string $operation, string $executionToken): array
    {
        $bizKey = \trim($bizKey);
        if ($queueId <= 0 || $bizKey === '') {
            return [];
        }

        try {
            $result = w_query('queue', 'list', [
                'biz_key' => $bizKey,
                'page_size' => 20,
            ]);
        } catch (\Throwable) {
            return [];
        }

        foreach (\is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData') ? $item->getData() : $item;
            if (!\is_array($row)) {
                continue;
            }
            $candidateQueueId = (int)($row['queue_id'] ?? 0);
            if ($candidateQueueId <= $queueId) {
                continue;
            }
            $status = \trim((string)($row['status'] ?? ''));
            if (!\in_array($status, ['pending', 'queued', 'running', 'done', 'error', 'stop'], true)) {
                continue;
            }
            if ($this->isStrictSupersedingQueueSlot($bizKey)) {
                return $row;
            }
            if ($this->queueRowMatchesOperationToken($row, $operation, $executionToken)) {
                return $row;
            }
        }

        return [];
    }

    private function resolveActiveQueueIdForQueuedOperation(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        string $operation,
        string $executionToken
    ): int {
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (
            \trim((string)($active['operation'] ?? '')) === $operation
            && $this->executionTokenMatches((string)($active['execution_token'] ?? ''), $executionToken)
        ) {
            return (int)($active['queue_id'] ?? 0);
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $operationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        if ($this->executionTokenMatches((string)($operationState['execution_token'] ?? ''), $executionToken)) {
            return (int)($operationState['queue_id'] ?? 0);
        }

        return 0;
    }

    private function isStrictSupersedingQueueSlot(string $bizKey): bool
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return false;
        }

        return \str_contains($bizKey, ':queue_slot:')
            || \str_contains($bizKey, ':operation:');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function queueRowMatchesOperationToken(array $row, string $operation, string $executionToken): bool
    {
        $content = $row['content'] ?? null;
        if (\is_string($content)) {
            $decoded = \json_decode($content, true);
            $content = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($content)) {
            return false;
        }

        $rowOperation = \trim((string)($content['operation'] ?? ''));
        $rowToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));

        return $rowOperation === $operation
            && $this->executionTokenMatches($rowToken, $executionToken);
    }

    private function executionTokenMatches(string $actualToken, string $requestedToken): bool
    {
        $actualToken = \trim($actualToken);
        $requestedToken = \trim($requestedToken);
        if ($actualToken === '' || $requestedToken === '') {
            return false;
        }
        if ($actualToken === $requestedToken) {
            return true;
        }

        $actualBase = \explode('-force-', $actualToken, 2)[0] ?? $actualToken;
        $requestedBase = \explode('-force-', $requestedToken, 2)[0] ?? $requestedToken;

        return $actualBase !== '' && $actualBase === $requestedBase;
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    private function appendQueueLifecycleLine(Queue &$queue, string $message): void
    {
        $qid = (int)$queue->getId();
        if ($qid <= 0 || $message === '') {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $qid]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE ' . $message;
        $existing = (string)($row['result'] ?? '');
        w_query('queue', 'update', [
            'queue_id' => $qid,
            'patch' => [
                'process' => $message,
                'result' => $existing === '' ? $line : $existing . PHP_EOL . $line,
            ],
        ]);
        $this->mirrorToCli($line);
    }

    private function markQueueDone(Queue &$queue, string $message): void
    {
        $qid = (int)$queue->getId();
        if ($qid <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $qid]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE_DONE ' . $message;
        $existing = (string)($row['result'] ?? '');
        w_query('queue', 'update', [
            'queue_id' => $qid,
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

    private function formatThrowableDiagnostic(\Throwable $throwable): string
    {
        $frames = [];
        foreach (\array_slice($throwable->getTrace(), 0, 8) as $frame) {
            $file = \trim((string)($frame['file'] ?? ''));
            $line = (int)($frame['line'] ?? 0);
            $function = \trim((string)($frame['function'] ?? ''));
            $class = \trim((string)($frame['class'] ?? ''));
            $location = $file !== '' ? $file . ($line > 0 ? ':' . $line : '') : '[internal]';
            $call = $class !== '' ? $class . '::' . $function : $function;
            $frames[] = $location . ($call !== '' ? ' ' . $call : '');
        }

        return \sprintf(
            '%s in %s:%d%s',
            $throwable->getMessage(),
            $throwable->getFile(),
            (int)$throwable->getLine(),
            $frames !== [] ? ' | trace: ' . \implode(' <- ', $frames) : ''
        );
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

    /**
     * @param array<string, mixed> $content
     * @return array{0:array<string,mixed>,1:int,2:int}
     */
    private function beginQueueAttempt(Queue &$queue, array $content, string $effectiveExecutionToken): array
    {
        $attempt = \max(0, (int)($content[self::CONTENT_ATTEMPT_KEY] ?? 0)) + 1;
        $maxAttempts = \max(
            $attempt,
            (int)($content[self::CONTENT_MAX_ATTEMPTS_KEY] ?? self::DEFAULT_MAX_ATTEMPTS),
            self::DEFAULT_MAX_ATTEMPTS
        );
        $content[self::CONTENT_ATTEMPT_KEY] = $attempt;
        $content[self::CONTENT_MAX_ATTEMPTS_KEY] = $maxAttempts;
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = '';
        $content[self::CONTENT_LAST_GATE_AT_KEY] = '';
        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'running';
        $content[self::CONTENT_LAST_GATE_SNAPSHOT_KEY] = [];
        if ($effectiveExecutionToken !== '') {
            $content['execution_token'] = $effectiveExecutionToken;
        }
        $content = $this->compactQueueContentForStorage($content);
        $this->saveQueueContent($queue, $content);

        return [$content, $attempt, $maxAttempts];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function saveQueueContent(Queue &$queue, array $content): void
    {
        $content = $this->compactQueueContentForStorage($content);
        $queue->setContent($this->encodeQueueContent($content))->save();
    }

    /**
     * Keep retry metadata on the same stage queue row. The HTTP entry layer
     * resets reused queue rows to pending, so retries and rebuilds do not create
     * unbounded duplicate rows for the same session stage slot.
     *
     * @param array<string, mixed> $content
     */
    private function createCompletionGateRetryQueue(Queue &$queue, array $content, string $message): int
    {
        $queueId = (int)$queue->getId();
        $content['retry_of_queue_id'] = $queueId;
        $content['retry_reason'] = $message;
        $content['retry_scheduled_at'] = \date('Y-m-d H:i:s');
        $content['execution_token'] = \trim((string)($content['execution_token'] ?? ''));
        $this->saveQueueContent($queue, $content);
        if ($queueId <= 0) {
            throw new \RuntimeException('Unable to mark PageBuilder build queue retry after completion gate block.');
        }

        return $queueId;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function markQueueRetryScheduled(Queue &$queue, int $retryQueueId, array $content, string $message): void
    {
        $content = $this->compactQueueContentForStorage($content);
        $content['retry_queue_id'] = $retryQueueId;
        $line = '[' . \date('H:i:s') . '] QUEUE_RETRY same_queue=' . $retryQueueId . ' ' . $message;
        $queue->setStatus(Queue::status_pending)
            ->setContent($this->encodeQueueContent($content))
            ->setFinished(false)
            ->setPid(0)
            ->setData(Queue::schema_fields_start_at, null)
            ->setData(Queue::schema_fields_end_at, null)
            ->setProcess($message)
            ->setResult($this->appendQueueMessage((string)$queue->getResult(), $line))
            ->save();
        $this->mirrorToCli($line);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function compactQueueContentForStorage(array $content): array
    {
        if (!\is_array($content['scope_patch'] ?? null)) {
            return $content;
        }

        $scopePatch = $content['scope_patch'];
        foreach (self::QUEUE_SCOPE_PATCH_REDUNDANT_KEYS as $key => $_) {
            unset($scopePatch[$key]);
        }
        if (\is_array($scopePatch['build_summary'] ?? null)) {
            unset($scopePatch['build_summary']['task_summary']);
            if ($scopePatch['build_summary'] === []) {
                unset($scopePatch['build_summary']);
            }
        }
        $content['scope_patch'] = $scopePatch;

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeQueueContent(Queue &$queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function stripGateSummary(array $gate): array
    {
        return \array_diff_key($gate, ['summary' => true]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function encodeQueueContent(array $content): string
    {
        $json = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($json) ? $json : '{}';
    }

    private function appendQueueMessage(string $existing, string $line): string
    {
        $existing = \trim($existing);
        $line = \trim($line);
        if ($line === '') {
            return $existing;
        }
        if ($existing === '') {
            return $line;
        }

        return $existing . \PHP_EOL . $line;
    }
}
