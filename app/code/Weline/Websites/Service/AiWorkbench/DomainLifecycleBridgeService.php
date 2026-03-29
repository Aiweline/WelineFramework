<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Model\AiSiteBuilderSession;

class DomainLifecycleBridgeService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EventStreamService $eventStreamService,
    ) {
    }

    /**
     * Build a domain lifecycle status summary for the workspace UI top-bar.
     *
     * @return array{
     *   domain: string,
     *   stage: string,
     *   stage_label: string,
     *   status: string,
     *   status_label: string,
     *   is_ready: bool,
     *   updated_at: string
     * }
     */
    public function buildLifecycleStatus(AiSiteBuilderSession $session): array
    {
        $scope = $session->getScopeArray();
        $runtimeDomain = (string)($scope['target_domain'] ?? $session->getSelectedDomain() ?? '');
        $status = (string)($scope['domain_purchase_status'] ?? 'idle');
        $stage = (string)($scope['domain_purchase_stage'] ?? $this->inferStageFromStatus($status));
        $updatedAt = (string)($scope['domain_purchase_updated_at'] ?? $session->getUpdateTime() ?? '');

        $isReady = $status === 'completed';

        return [
            'domain' => $runtimeDomain,
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage, $session),
            'status' => $status,
            'status_label' => $this->getStatusLabel($status),
            'is_ready' => $isReady,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Append a domain lifecycle event to the session event stream.
     */
    public function appendLifecycleEvent(
        int $sessionId,
        int $adminId,
        string $stage,
        array $payload = [],
        string $level = 'info'
    ): int {
        return $this->eventStreamService->appendEvent(
            $sessionId,
            $adminId,
            'domain',
            'domain_lifecycle_stage_changed',
            \array_merge($payload, ['stage' => $stage, 'stage_label' => $this->getStageLabel($stage, $this->sessionService->loadById($sessionId, $adminId) ?? $this->createMinimalSession())]),
            $level
        );
    }

    /**
     * Check whether the domain is ready for the build/materialization stage.
     */
    public function isDomainReadyForBuild(AiSiteBuilderSession $session): bool
    {
        $scope = $session->getScopeArray();
        $status = (string)($scope['domain_purchase_status'] ?? 'idle');
        $domain = (string)($scope['target_domain'] ?? $session->getSelectedDomain() ?? '');

        return $domain !== '' && $status === 'completed';
    }

    /**
     * Get a human-readable label for a lifecycle stage.
     */
    public function getStageLabel(string $stage, AiSiteBuilderSession $session): string
    {
        return match ($stage) {
            'idle' => __('准备中'),
            'purchase', 'queued' => __('购买中'),
            'dns' => __('DNS 解析中'),
            'resolve' => __('DNS 解析中'),
            'verify' => __('验证中'),
            'ssl' => __('SSL 证书中'),
            'certificate' => __('SSL 证书中'),
            'cdn' => __('CDN 部署中'),
            'completed' => __('已完成'),
            'failed' => __('失败'),
            default => __('未知'),
        };
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'idle' => __('等待中'),
            'queued' => __('排队中'),
            'running' => __('进行中'),
            'completed' => __('已完成'),
            'failed' => __('失败'),
            default => __('未知'),
        };
    }

    private function inferStageFromStatus(string $status): string
    {
        return match ($status) {
            'idle' => 'idle',
            'queued' => 'purchase',
            'running' => 'purchase',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'idle',
        };
    }

    private function createMinimalSession(): AiSiteBuilderSession
    {
        return new AiSiteBuilderSession();
    }
}
