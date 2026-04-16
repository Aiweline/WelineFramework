<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Console\AiSiteAgent;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class RunOperation implements CommandInterface
{
    private Printing $printer;
    private AiSiteAgentSessionService $sessionService;
    private AiSiteScopeCompatibilityService $scopeCompatibilityService;

    public function __construct()
    {
        $this->printer = ObjectManager::getInstance(Printing::class);
        $this->sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->scopeCompatibilityService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
    }

    public function execute(array $args = [], array $data = [])
    {
        $publicId = \trim((string)($args['public_id'] ?? $data['public_id'] ?? ''));
        $adminId = (int)($args['admin_id'] ?? $data['admin_id'] ?? 0);
        $executionToken = \trim((string)($args['execution_token'] ?? $data['execution_token'] ?? ''));

        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            $this->printer->error('Missing required args: --public_id --admin_id --execution_token');
            return 1;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            $this->printer->error('Session not found.');
            return 1;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $pageType = \trim((string)($activeOperation['page_type'] ?? ''));
        if ($operation === '' || \trim((string)($activeOperation['execution_token'] ?? '')) !== $executionToken) {
            $this->printer->error('Active operation token mismatch.');
            return 1;
        }

        $controller = ObjectManager::getInstance(AiSiteAgent::class);
        $silentWriter = new class extends SseWriter {
            public function start(): static { return $this; }
            public function sendEvent(string $event, mixed $data = null, ?int $id = null): static { return $this; }
            public function sendData(mixed $data): static { return $this; }
            public function sendComment(string $comment = ''): static { return $this; }
            public function sendHeartbeat(): static { return $this; }
            public function maybeHeartbeat(): static { return $this; }
            public function yieldAfterSend(): static { return $this; }
            public function sendError(string $message, int $code = 500): static { return $this; }
            public function complete(mixed $data = null): void {}
            public function close(): void {}
            public function isAlive(): bool { return true; }
        };

        try {
            match ($operation) {
                'build' => $this->runClaimedOperation($controller, 'runBuildOperation', [$silentWriter, $session, $adminId], $session, $adminId, $executionToken, $operation, $pageType),
                'regenerate_page' => $this->runClaimedOperation($controller, 'runRegeneratePageOperation', [$silentWriter, $session, $adminId, $pageType], $session, $adminId, $executionToken, $operation, $pageType),
                'publish' => $this->runClaimedOperation($controller, 'runPublishOperation', [$silentWriter, $session, $adminId], $session, $adminId, $executionToken, $operation, $pageType),
                default => throw new \RuntimeException('Unsupported background operation: ' . $operation),
            };
        } catch (\Throwable $throwable) {
            $this->markOperationFailed($controller, $session, $adminId, $operation, $pageType, $throwable);
            $this->printer->error($throwable->getMessage());
            return 1;
        }

        return 0;
    }

    private function runClaimedOperation(
        AiSiteAgent $controller,
        string $method,
        array $invokeArgs,
        AiSiteAgentSession $session,
        int $adminId,
        string $executionToken,
        string $operation,
        string $pageType
    ): void {
        $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $executionToken, $operation]);
        if (!\is_array($claim) || !($claim['ok'] ?? false)) {
            $reason = (string)($claim['reason'] ?? '');
            if ($reason === 'duplicate_stream') {
                return;
            }
            throw new \RuntimeException((string)($claim['message'] ?? 'Operation claim failed.'));
        }

        $this->invokePrivate($controller, $method, $invokeArgs);
    }

    private function markOperationFailed(
        AiSiteAgent $controller,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $pageType,
        \Throwable $throwable
    ): void {
        $failedStatus = $operation === 'publish'
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;

        $this->invokePrivate($controller, 'updateActiveOperation', [
            $session,
            $adminId,
            ['status' => 'error', 'message' => $throwable->getMessage()],
            $failedStatus,
            $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null,
        ]);

        $stageCode = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $this->invokePrivate($controller, 'appendWorkspaceEvent', [
            $session->getId(),
            $adminId,
            $stageCode,
            'operation_failed',
            (string)__('鎿嶄綔鎵ц澶辫触锛?{message}', ['message' => $throwable->getMessage()]),
            [
                'operation' => $operation,
                'page_type' => $pageType,
                'details' => ['exception' => $throwable->getMessage()],
            ],
            AiSiteAgentSessionEvent::LEVEL_ERROR,
        ]);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    public function tip(): string
    {
        return 'Run PageBuilder AI active operation in a detached background process.';
    }

    public function help(): array|string
    {
        return 'Usage: php bin/w pagebuilder:ai-site-agent:run-operation --public_id=<id> --admin_id=<id> --execution_token=<token>';
    }
}
