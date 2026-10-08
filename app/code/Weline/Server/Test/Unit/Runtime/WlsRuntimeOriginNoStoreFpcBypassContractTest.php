<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

final class WlsRuntimeOriginNoStoreFpcBypassContractTest extends TestCase
{
    public function testCurrentFpcStatusHonorsSharedForbidAndCollectorBypass(): void
    {
        $root = dirname(__DIR__, 3);
        $runtime = (string)file_get_contents($root . '/Runtime/WlsRuntime.php');

        self::assertStringContainsString('use Weline\\Framework\\Cache\\SharedResponseCachePolicy;', $runtime);
        self::assertStringContainsString('use Weline\\Framework\\Http\\HeaderCollector;', $runtime);
        self::assertStringContainsString('SharedResponseCachePolicy::isForbidden()', $runtime);
        self::assertStringContainsString('resolveExplicitFpcStatusHeader', $runtime);
        self::assertStringContainsString('GuardHeaders::CACHE_STATUS', $runtime);
        self::assertStringContainsString('GuardHeaders::STATUS_BYPASS', $runtime);
        self::assertMatchesRegularExpression(
            "/private function currentFpcStatus\\(Response \\\$response, bool \\\$fpcHit\\): string\\s*\\{[\\s\\S]*?SharedResponseCachePolicy::isForbidden\\(\\)[\\s\\S]*?return 'MISS';/",
            $runtime
        );
    }
}
