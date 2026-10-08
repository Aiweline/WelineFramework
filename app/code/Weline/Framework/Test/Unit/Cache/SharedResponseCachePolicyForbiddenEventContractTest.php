<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\SharedResponseCachePolicy;

final class SharedResponseCachePolicyForbiddenEventContractTest extends TestCase
{
    public function testForbidDispatchesSharedCacheForbiddenEventConstant(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Cache/SharedResponseCachePolicy.php');
        self::assertStringContainsString(
            "EVENT_SHARED_CACHE_FORBIDDEN = 'Weline_Framework::response::shared_cache_forbidden'",
            $src,
        );
        self::assertStringContainsString('dispatch(self::EVENT_SHARED_CACHE_FORBIDDEN', $src);
        self::assertStringContainsString("'cache_control'", $src);
        self::assertStringContainsString('private, no-store, max-age=0, must-revalidate', $src);
    }
}
