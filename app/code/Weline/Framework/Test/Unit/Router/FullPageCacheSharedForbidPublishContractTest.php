<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * WLS process/shared FPC publish must honor SharedResponseCachePolicy::forbid
 * and shared HeaderCollector Cache-Control: no-store (Response may be detached).
 */
final class FullPageCacheSharedForbidPublishContractTest extends TestCase
{
    public function testCanPublishHardBlocksSharedForbidAndHeaderCollectorNoStore(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php');
        self::assertStringContainsString('function sharedResponseForbidsPublish', $src);
        self::assertStringContainsString('function sharedHeaderCollectorHasNoStore', $src);
        self::assertStringContainsString('function markLiveResponseUncacheable', $src);
        self::assertStringContainsString('SharedResponseCachePolicy::isForbidden()', $src);
        self::assertStringContainsString('sharedHeaderCollectorHasNoStore()', $src);
        self::assertStringContainsString('sharedResponseForbidsPublish()', $src);
        self::assertStringContainsString('GuardHeaders::STATUS_BYPASS', $src);
        self::assertStringContainsString("directive === 'no-store'", $src);
    }
}
