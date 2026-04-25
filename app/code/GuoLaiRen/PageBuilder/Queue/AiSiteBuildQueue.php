<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSiteBuildQueue implements QueueInterface
{
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
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $operation = $this->normalizeQueuedOperation((string)($content['operation'] ?? 'build'));
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];
        $forceRebuild = $operation === 'build' && (int)($content['_force_rebuild'] ?? 0) === 1;
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
            /** @var AiSiteBuildTaskService $buildTaskService */
            $buildTaskService = ObjectManager::getInstance(AiSiteBuildTaskService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('会话不存在或无权访问。');
            }
            $this->appendQueueLifecycleLine($queue, '已加载会话 session_id=' . (int)$session->getId());
            if ($forceRebuild) {
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
                '已同步 active_operation=queued operation=build execution_token=' . \substr($effectiveExecutionToken, 0, 12) . '…'
            );

            $scope = $scopeService->normalizeScope($session->getScopeArray());
            $confirmedScope = $scope;
            if ($operation === 'build') {
                $scopePatch = $buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $confirmedScope);
                if ($scopePatch !== []) {
                    $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                }
                $scope = $buildTaskService->restoreBuildPlanContract($scope, $confirmedScope);
                $normalizedScope = $buildTaskService->normalizeConfirmedTaskPlanFlag($scope);
                $scopeChanged = $normalizedScope !== $confirmedScope;
                $scope = $normalizedScope;
            } elseif ($operation === 'block_regenerate' && $scopePatch !== []) {
                $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                $scopeChanged = $scope !== $confirmedScope;
            } else {
                $scopeChanged = false;
            }
            if ($scopeChanged) {
                $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
                $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            }
            if ((int)($scope['task_plan_confirmed'] ?? 0) !== 1) {
                throw new \RuntimeException('请先确认第二阶段任务方案，再开始执行构建。');
            }

            $allowStubAiInTest = (int)($scope['fake_mode'] ?? 0) === 1;

            $sse = new QueueDbWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                $operation
            );
            $previousSseContextExists = RequestContext::has(RequestContext::SSE_WRITER_KEY);
            $previousSseContext = RequestContext::get(RequestContext::SSE_WRITER_KEY);
            RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
            $sseContextRegistered = true;
            $this->queueTrace($sse, 'QueueDbWriter 已创建，构建进度将写入队列 result');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, $operation]);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, '认领跳过：duplicate_stream（仍视为重复构建，可加 -f 换新令牌）');

                    return '检测到重复构建任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }
            $this->queueTrace($sse, '认领成功 claimActiveOperationExecution ok，进入 runBuildOperation');

            // mergeScope 只更新库内 scope；内存中的 $session 可能仍带旧 build_tasks，会导致 ensureTaskScope 继续合并为 done 从而秒结束。
            $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $operationContext = $operation === 'block_regenerate'
                ? $this->resolveQueuedOperationContext($content, $session, $scopeService)
                : [];

            if ($allowStubAiInTest) {
                RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, true);
            }
            try {
                if ($operation === 'block_regenerate') {
                    $this->invokePrivate($controller, 'runRegenerateBlockOperation', [
                        $sse,
                        $session,
                        $adminId,
                        (string)($operationContext['page_type'] ?? ''),
                        (string)($operationContext['component_code'] ?? ''),
                        (string)($operationContext['instruction'] ?? ''),
                    ]);
                } else {
                    $this->invokePrivate($controller, 'runBuildOperation', [$sse, $session, $adminId]);
                }
            } finally {
                if ($allowStubAiInTest) {
                    RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST);
                }
            }
            $this->queueTrace($sse, 'runBuildOperation 已返回');

            if ($forceRebuild) {
                $this->clearQueueForceBuildMarker($sessionService, (int)$session->getId(), $adminId);
            }

            $this->queueTrace($sse, '队列执行成功：构建完成');

            return '构建完成。';
        } catch (\Throwable $throwable) {
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '异常：' . $throwable->getMessage());
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（SSE 未初始化）：' . $throwable->getMessage());
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $throwable->getMessage());
            throw new \RuntimeException('构建失败：' . $throwable->getMessage(), 0, $throwable);
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

    /**
     * -f：换新 execution_token + 将 build_tasks 全部置回 pending，否则任务已 done 会秒结束且不调 AI。
     */
    private function normalizeQueuedOperation(string $operation): string
    {
        $operation = \trim($operation);

        return \in_array($operation, ['build', 'block_regenerate'], true) ? $operation : 'build';
    }

    /**
     * @return array{page_type: string, component_code: string, instruction: string}
     */
    private function resolveQueuedOperationContext(
        array $content,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): array {
        $scope = $scopeService->normalizeScope($session->getScopeArray());
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];

        $pageType = \trim((string)($content['page_type'] ?? $details['page_type'] ?? $active['page_type'] ?? $activeDetails['page_type'] ?? ''));
        $componentCode = \trim((string)($content['component_code'] ?? $details['component_code'] ?? $active['component_code'] ?? $activeDetails['component_code'] ?? ''));
        $instruction = \trim((string)($content['instruction'] ?? $details['instruction'] ?? $active['instruction'] ?? $activeDetails['instruction'] ?? ''));

        if ($pageType === '' || $componentCode === '') {
            throw new \RuntimeException('Block queue context is missing page_type or component_code.');
        }

        return [
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'instruction' => $instruction,
        ];
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
        $scope = $scopeService->normalizeScope($fresh->getScopeArray());
        $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $scope = $buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $scope = $buildTaskService->resetBuildTasksToPendingForRebuild($scope);
        $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
            'build_blueprint' => \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [],
            'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
            '_queue_force_build' => [
                'active' => 1,
                'at' => \date('Y-m-d H:i:s'),
            ],
        ]);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    private function clearQueueForceBuildMarker(AiSiteAgentSessionService $sessionService, int $sessionId, int $adminId): void
    {
        try {
            $sessionService->mergeScope($sessionId, $adminId, [
                '_queue_force_build' => [
                    'active' => 0,
                    'consumed_at' => \date('Y-m-d H:i:s'),
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
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
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
