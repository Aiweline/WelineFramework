<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchRecoveryRequestContractTest extends TestCase
{
    public function testRequestBoundarySupportsReadbackCacheRetryAndWholeBatchRollback(): void
    {
        $root = \dirname(__DIR__, 4);
        $service = (string)\file_get_contents($root . '/Service/Scoped/ThemeScopedWorkspaceRequestService.php');

        foreach (['readBatch(', 'retryBatchCache(', 'rollbackBatch('] as $method) {
            self::assertStringContainsString('public function ' . $method, $service);
        }
        self::assertStringContainsString('$this->workspace->getReleaseBatch(', $service);
        self::assertStringContainsString('$this->workspace->rollbackReleaseBatch(', $service);
        self::assertStringContainsString('finalizeBatchCacheState(', $service);

        $retryStart = \strpos($service, 'public function retryBatchCache(');
        $rollbackStart = \strpos($service, 'public function rollbackBatch(', (int)$retryStart);
        self::assertNotFalse($retryStart);
        self::assertNotFalse($rollbackStart);
        $retry = \substr($service, (int)$retryStart, (int)$rollbackStart - (int)$retryStart);
        self::assertStringNotContainsString('publishBatch(', $retry);
        self::assertStringNotContainsString('rollbackReleaseBatch(', $retry);
    }

    public function testControllerExposesRecoveryEndpointsBehindPublishAcl(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php'
        );
        foreach ([
            'getScopedReleaseBatch',
            'postRetryScopedReleaseBatchCache',
            'postRollbackScopedReleaseBatch',
        ] as $method) {
            self::assertStringContainsString('function ' . $method . '()', $controller);
        }
    }
}
