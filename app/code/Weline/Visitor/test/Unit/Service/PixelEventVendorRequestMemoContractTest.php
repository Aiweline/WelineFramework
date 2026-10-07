<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Cold homepage: pixel vendors must memoize per-request / process to avoid
 * repeated SELECT on w_pixel_event_vendor from header bootstrap + body-end.
 */
final class PixelEventVendorRequestMemoContractTest extends TestCase
{
    public function testListRuntimeVendorsUsesRequestContextAndProcessMemo(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PixelEventVendorManager.php'
        );

        self::assertStringContainsString('visitor.pixel_event_vendors.runtime.v1.', $src);
        self::assertStringContainsString('RequestContext::has', $src);
        self::assertStringContainsString('RequestContext::set', $src);
        self::assertStringContainsString('$processRuntimeVendors', $src);
        self::assertStringContainsString('clearProcessVendorCache', $src);
    }
}
