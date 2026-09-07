<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchRequestContractTest extends TestCase
{
    public function testRequestServiceCommitsBeforeClassifyingCacheState(): void
    {
        $root = \dirname(__DIR__, 4);
        $service = (string)\file_get_contents($root . '/Service/Scoped/ThemeScopedWorkspaceRequestService.php');

        self::assertStringContainsString('public function publishBatch(', $service);
        self::assertStringContainsString('ThemeScopedReleaseBatch::fromExpectations(', $service);
        self::assertStringContainsString('$this->workspace->publishBatch(', $service);
        self::assertStringContainsString('ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED', $service);
        self::assertStringContainsString('updateReleaseBatchCacheState(', $service);
        self::assertStringContainsString("'cache_retryable'", $service);
    }

    public function testWorkspaceExposesBatchCacheReadbackWithoutRepublishing(): void
    {
        $root = \dirname(__DIR__, 4);
        $interface = (string)\file_get_contents($root . '/Api/Scoped/ThemeScopedWorkspaceInterface.php');
        $workspace = (string)\file_get_contents($root . '/Service/Scoped/ThemeScopedWorkspace.php');

        foreach (['updateReleaseBatchCacheState(', 'getReleaseBatch('] as $method) {
            self::assertStringContainsString($method, $interface);
            self::assertStringContainsString($method, $workspace);
        }
    }
}
