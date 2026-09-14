<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Api\FulfillmentSplitPlanInterface;
use Weline\Inventory\Service\FulfillmentSplitPlanService;

final class FulfillmentSplitPlanContractTest extends TestCase
{
    public function testInterfaceAndDeterministicOrderingInSource(): void
    {
        self::assertTrue(interface_exists(FulfillmentSplitPlanInterface::class));
        self::assertTrue(class_exists(FulfillmentSplitPlanService::class));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FulfillmentSplitPlanService.php');
        self::assertStringContainsString('split_key', $src);
        self::assertStringContainsString('wh:', $src);
        self::assertStringContainsString('resolveDefault', $src);
        self::assertStringNotContainsString('mt_rand', $src);
        self::assertStringNotContainsString('array_rand', $src);
        $module = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.php');
        self::assertStringContainsString('FulfillmentSplitPlanInterface', $module);
        self::assertStringContainsString('2.5.23', $module);
    }
}
