<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: Outbox 仓映射缺失降级，不阻断推单。 */
final class DropshipOutboxWarehouseMapFallbackContractTest extends TestCase
{
    public function testOutboxDegradesMissingWarehouseMap(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipOutboxService.php');
        self::assertStringContainsString('resolveWarehouseMapOrEmpty', $src);
        self::assertStringContainsString('ERROR_MISSING', $src);
        self::assertStringContainsString('tracking_number', $src);
        self::assertStringContainsString("\$result['status']", $src);
    }
}
