<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\ScopedQueueConsumerInterface;

/**
 * Scoped 队列消费者执行前的 kind/维度守卫（P1b）。
 */
final class ScopedQueueConsumerGuard
{
    public const DIMENSIONS = [
        'website_id',
        'website_code',
        'store_code',
        'channel_code',
        'store_mode',
    ];

    public function assertStoredEnvelopeAccepted(
        ScopedQueueConsumerInterface $consumer,
        ?ScopeEnvelope $envelope,
    ): void {
        if ($envelope === null) {
            throw new \RuntimeException((string)__(
                '队列任务缺少已迁移的 Scope 信封，拒绝执行；请先完成迁移或 quarantine。'
            ));
        }

        $this->assertAccepted($consumer, $envelope);
    }

    public function assertAccepted(ScopedQueueConsumerInterface $consumer, ScopeEnvelope $envelope): void
    {
        $accepted = $consumer->acceptedScopeKinds();
        $this->assertAcceptedKindsList($accepted);
        $kind = $envelope->scope->scopeKind;
        if (!\in_array($kind, $accepted, true)) {
            throw $this->rejection($consumer, $envelope, (string)__(
                '队列任务 Scope kind=%{1} 不在消费者接受列表 [%{2}] 内，拒绝执行。',
                [
                    $kind,
                    \implode(',', $accepted),
                ]
            ));
        }

        $required = $consumer->requiredScopeDimensions();
        $this->assertRequiredDimensionsList($required);
        foreach ($required as $dimension) {
            if (!$this->dimensionPresent($envelope->scope, $dimension)) {
                throw $this->rejection($consumer, $envelope, (string)__(
                    '队列任务 Scope 缺少必需维度 %{1}，拒绝执行。',
                    [$dimension]
                ));
            }
        }
    }

    /**
     * @param list<string> $accepted
     */
    private function assertAcceptedKindsList(array $accepted): void
    {
        if ($accepted === []) {
            throw new \LogicException('ScopedQueueConsumerInterface::acceptedScopeKinds() 不得为空');
        }
        foreach ($accepted as $kind) {
            if (!\is_string($kind) || !\in_array($kind, ScopeIdentity::KINDS, true)) {
                throw new \LogicException('ScopedQueueConsumerInterface::acceptedScopeKinds() 含非法 kind');
            }
        }
    }

    /**
     * @param list<string> $required
     */
    private function assertRequiredDimensionsList(array $required): void
    {
        foreach ($required as $dimension) {
            if (!\is_string($dimension) || !\in_array($dimension, self::DIMENSIONS, true)) {
                throw new \LogicException(
                    'ScopedQueueConsumerInterface::requiredScopeDimensions() 含非法维度'
                );
            }
        }
    }

    private function dimensionPresent(ScopeIdentity $scope, string $dimension): bool
    {
        return match ($dimension) {
            'website_id' => $scope->websiteId !== null,
            'website_code' => $scope->websiteCode !== null && $scope->websiteCode !== '',
            'store_code' => $scope->storeCode !== null && $scope->storeCode !== '',
            'channel_code' => $scope->channelCode !== null && $scope->channelCode !== '',
            'store_mode' => $scope->storeMode !== null && $scope->storeMode !== '',
            default => false,
        };
    }

    private function rejection(
        ScopedQueueConsumerInterface $consumer,
        ScopeEnvelope $envelope,
        string $prefix,
    ): \RuntimeException {
        $message = \trim($consumer->rejectedScopeMessage($envelope));

        return new \RuntimeException($prefix . ($message !== '' ? ' ' . $message : ''));
    }
}
