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

        return !empty($content['public_id']) && !empty($content['admin_id']) && !empty($content['execution_token']);
    }

    public function execute(Queue &$queue): string
    {
        $args = $queue->getArgs();
        $content = \json_decode((string)$queue->getContent(), true);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $queueId = (int)$queue->getId();

        $sse = null;
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

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                'task_plan',
                $executionToken
            );
            $this->appendQueueLifecycleLine($queue, '已同步 active_operation=queued operation=task_plan execution_token=' . \substr($executionToken, 0, 12) . '…');

            $scope = $scopeService->normalizeScope($session->getScopeArray());
            if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
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
            $this->queueTrace($sse, 'QueueDbWriter 已创建，后续步骤将写入队列 result 与会话事件');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $executionToken, 'task_plan']);
            if (!\is_array($claim) || !($claim['ok'] ?? false) || $args['force'] === true) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, '认领跳过：duplicate_stream（重复第二阶段生成）');

                    return '检测到重复第二阶段生成任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }
            $this->queueTrace($sse, '认领成功 claimActiveOperationExecution ok，进入 runTaskPlanOperation');

            $this->invokePrivate($controller, 'runTaskPlanOperation', [$sse, $session, $adminId]);
            $this->queueTrace($sse, 'runTaskPlanOperation 已返回');

            $this->ensureTaskPlanDraftPersisted($sessionService, $scopeService, $session, $adminId);
            $this->queueTrace($sse, 'ensureTaskPlanDraftPersisted 已完成（草案已就绪或已补全）');

            $this->queueTrace($sse, '队列执行成功：第二阶段任务方案生成完成');

            return '第二阶段任务方案生成完成。';
        } catch (\Throwable $throwable) {
            if ($sse instanceof QueueDbWriter) {
                $this->queueTrace($sse, '异常：' . $throwable->getMessage());
            } else {
                $this->appendQueueLifecycleLine($queue, '异常（SSE 未初始化）：' . $throwable->getMessage());
            }
            $this->updateSessionError($publicId, $adminId, $executionToken, $throwable->getMessage());
            throw new \RuntimeException('第二阶段任务方案生成失败：' . $throwable->getMessage(), 0, $throwable);
        } finally {
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
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    private function ensureTaskPlanDraftPersisted(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): void {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope($fresh->getScopeArray());
        $draft = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
        $draftMarkdown = \trim((string)($scope['virtual_theme_plan']['draft_markdown'] ?? ''));
        if ($draft !== [] && $draftMarkdown !== '') {
            return;
        }

        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if ($buildBlueprint === []) {
            throw new \RuntimeException('第二阶段任务方案生成后缺少 build_blueprint，无法补全草案。');
        }

        /** @var AiSiteVirtualThemePlanService $planService */
        $planService = ObjectManager::getInstance(AiSiteVirtualThemePlanService::class);
        $artifacts = $planService->buildTaskPlanArtifacts($scope, $buildBlueprint);
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $markdown = (string)($artifacts['markdown'] ?? '');
        if ($virtualThemePlan === [] || $markdown === '') {
            throw new \RuntimeException('第二阶段任务方案未生成有效草案。');
        }

        $sessionService->mergeScope((int)$fresh->getId(), $adminId, [
            'virtual_theme_plan' => [
                'draft' => $virtualThemePlan,
                'draft_markdown' => $markdown,
                'draft_generated_at' => \date('Y-m-d H:i:s'),
                'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                'plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
            ],
            'task_plan_structured' => $structured,
            'task_plan_markdown' => $markdown,
            'task_plan_generated_at' => \date('Y-m-d H:i:s'),
            'task_plan_confirmed' => 0,
            '_task_plan_sse_request' => [],
        ]);
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
    }
}
