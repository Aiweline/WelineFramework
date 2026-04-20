<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
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

        return !empty($content['public_id']) && !empty($content['admin_id']) && !empty($content['execution_token']);
    }

    public function execute(Queue &$queue): string
    {
        $content = \json_decode((string)$queue->getContent(), true);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];

        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('会话不存在或无权访问。');
            }
            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                (int)$queue->getId(),
                'build',
                $executionToken
            );

            $scope = $scopeService->normalizeScope($session->getScopeArray());
            if ($scopePatch !== []) {
                $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
            }
            if ((int)($scope['task_plan_confirmed'] ?? 0) !== 1) {
                throw new \RuntimeException('请先确认第二阶段任务方案，再开始执行构建。');
            }

            $allowStubAiInTest = (int)($scope['fake_mode'] ?? 0) === 1;

            $sse = new QueueDbWriter(
                (int)$session->getId(),
                $adminId,
                (int)$queue->getId(),
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build'
            );

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $executionToken, 'build']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    return '检测到重复构建任务，已跳过。';
                }

                throw new \RuntimeException((string)($claim['message'] ?? '操作认领失败。'));
            }

            if ($allowStubAiInTest) {
                RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, true);
            }
            try {
                $this->invokePrivate($controller, 'runBuildOperation', [$sse, $session, $adminId]);
            } finally {
                if ($allowStubAiInTest) {
                    RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST);
                }
            }

            return '构建完成。';
        } catch (\Throwable $throwable) {
            $this->updateSessionError($publicId, $adminId, $executionToken, $throwable->getMessage());
            throw new \RuntimeException('构建失败：' . $throwable->getMessage(), 0, $throwable);
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
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }
}
