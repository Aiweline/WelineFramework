<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueLogWriter;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSitePublishQueue implements QueueInterface
{
    private const QUEUE_RESULT_MAX_BYTES = 4096;
    private const QUEUE_RESULT_TRUNCATION_MARKER = '[... queue log truncated ...]';

    public function name(): string
    {
        return 'PageBuilder AI site publish queue';
    }

    public function tip(): string
    {
        return 'Publish PageBuilder AI site output asynchronously after the publish gates pass.';
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = $this->decodeContent($queue);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            return false;
        }

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);

        return $sessionService->loadByPublicId($publicId, $adminId) instanceof AiSiteAgentSession;
    }

    public function execute(Queue &$queue): string
    {
        $content = $this->decodeContent($queue);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
        $queueId = (int)$queue->getId();

        $sse = null;
        try {
            $this->appendQueueLifecycleLine($queue, 'Starting publish queue. queue_id=' . $queueId . ' public_id=' . $publicId . ' admin_id=' . $adminId);

            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('AI site session not found for publish.');
            }

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                $executionToken
            );

            $sse = new AiSiteQueueLogWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_PUBLISH,
                'publish',
                $executionToken,
                \trim((string)($content['job_key'] ?? '')),
                \trim((string)($content['job_type'] ?? ''))
            );

            $this->queueTrace($sse, 'Publish queue claimed the workspace operation.');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $executionToken, 'publish', 'queue']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $message = 'Duplicate publish queue execution skipped.';
                    $this->queueTrace($sse, $message);

                    return $message;
                }

                throw new \RuntimeException((string)($claim['message'] ?? 'Publish operation claim failed.'));
            }

            $this->invokePrivate($controller, 'runPublishOperation', [$sse, $session, $adminId]);

            $doneMessage = 'PageBuilder AI site publish completed.';
            $this->queueTrace($sse, $doneMessage);
            $this->markQueueDone($queue, $doneMessage);
            $sse->complete();

            return $doneMessage;
        } catch (\Throwable $throwable) {
            $message = \trim($throwable->getMessage()) ?: 'Publish failed.';
            if ($sse instanceof AiSiteQueueLogWriter) {
                $this->queueTrace($sse, 'Publish queue failed: ' . $message);
            } else {
                $this->appendQueueLifecycleLine($queue, 'Publish queue failed before queue log initialization: ' . $message);
            }
            $this->updateSessionError($publicId, $adminId, $executionToken, $message);

            throw new \RuntimeException('Publish failed: ' . $message, 0, $throwable);
        } finally {
            if ($sse instanceof AiSiteQueueLogWriter) {
                $sse->complete();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(Queue $queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function ensureQueuedActiveOperation(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        int $queueId,
        string $executionToken
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PUBLISH)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($active['status'] ?? ''));
        $activeQueueId = (int)($active['queue_id'] ?? 0);
        if (
            (string)($active['operation'] ?? '') === 'publish'
            && (string)($active['execution_token'] ?? '') === $executionToken
            && \in_array($activeStatus, ['queued', 'running'], true)
            && ($activeQueueId === $queueId || $queueId <= 0)
        ) {
            return $fresh;
        }

        $scope['active_operation'] = \array_replace($active, [
            'operation' => 'publish',
            'execution_token' => $executionToken,
            'status' => 'queued',
            'queue_id' => $queueId,
            'message' => 'Waiting for publish queue execution.',
            'started_at' => (string)($active['started_at'] ?? \date('Y-m-d H:i:s')),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations['publish'] = $scope['active_operation'];
        $scope['active_operations'] = $activeOperations;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);
        $sessionService->setPublishStatus((int)$fresh->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
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
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PUBLISH)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $active = \array_replace($active, [
                'operation' => 'publish',
                'status' => 'error',
                'message' => $message,
                'updated_at' => \date('Y-m-d H:i:s'),
                'queue_waiting_for_scheduler' => false,
                'can_close_stream' => false,
                'continue_other_operations' => false,
            ]);
            $scope['active_operation'] = $active;
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $activeOperations['publish'] = \array_replace(
                \is_array($activeOperations['publish'] ?? null) ? $activeOperations['publish'] : [],
                $active
            );
            $scope['active_operations'] = $activeOperations;
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
            $sessionService->setPublishStatus((int)$session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_FAILED);
        } catch (\Throwable) {
        }
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
                'result' => $this->appendQueueResultLine($existing, $line),
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
