<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Guards terminal checkpoint reconciliation before a new owner analysis. */
final class SeoOptimizationTerminalCheckpointReconciliationContractTest extends TestCase
{
    public function testTerminalCheckpointIsReleasedThroughTheAdapterAndRefreshed(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SeoOptimizationOrchestrator.php'
        );

        self::assertStringContainsString(
            '$snapshot = $this->reconcileTerminalCheckpoint($adapter, $websiteId, $snapshot);',
            $source,
        );
        self::assertStringContainsString('private function reconcileTerminalCheckpoint(', $source);
        self::assertStringContainsString('SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION,', $source);
        self::assertStringContainsString('$release = $adapter->finalize([', $source);
        self::assertStringContainsString(
            "if (!empty(\$release['success']) || !empty(\$release['checkpoint_released'])) {",
            $source,
        );
        self::assertStringContainsString('return $adapter->snapshot($websiteId, [', $source);

        $ownerAvailable = \substr(
            $source,
            (int)\strpos($source, 'private function ownerAvailable('),
            (int)\strpos($source, 'private function reconcileTerminalCheckpoint(')
                - (int)\strpos($source, 'private function ownerAvailable('),
        );
        self::assertStringNotContainsString(
            'SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION',
            $ownerAvailable,
            'manual_intervention is terminal; only a still-present owner checkpoint may keep it busy.',
        );
    }
}
