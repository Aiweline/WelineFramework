<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\PaymentAssetFacadeInterface;
use Weline\Payment\Api\PaymentEffectOutboxProcessorInterface;

/**
 * Retries asset commit/release independently from provider execution.
 */
final class PaymentAssetEffectConsumer
{
    public const EFFECT_COMMIT = 'asset:commit:v1';
    public const EFFECT_RELEASE = 'asset:release:v1';

    public function __construct(
        private readonly PaymentEffectOutboxProcessorInterface $outbox,
        private readonly PaymentAssetFacadeInterface $assets,
    ) {
    }

    /** @return array<string, mixed> */
    public function processOne(string $outboxCode): array
    {
        return $this->outbox->process(
            $outboxCode,
            fn (PaymentEffectRecord $effect): array =>
                $this->assets->applyTerminalEffect($effect),
        );
    }

    /** @return list<array<string, mixed>> */
    public function processPending(int $limit = 20): array
    {
        $results = [];
        foreach ($this->outbox->pendingCodes([
            self::EFFECT_COMMIT,
            self::EFFECT_RELEASE,
        ], $limit) as $outboxCode) {
            $results[] = $this->processOne($outboxCode);
        }

        return $results;
    }
}
