<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderGroupContextContractTest extends TestCase
{
    public function testGroupContextPartialExposesNavigationTestIds(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/Backend/Order/partial/group-context.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="order-group-context"', $source);
        self::assertStringContainsString('data-testid="order-group-sibling-link"', $source);
        self::assertStringContainsString('结账上下文', $source);
        self::assertStringContainsString('运费归属', $source);
    }

    public function testViewAndEditFetchGroupContextPartial(): void
    {
        foreach (['view.phtml', 'edit.phtml'] as $file) {
            $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/' . $file;
            $source = (string)file_get_contents($path);
            self::assertStringContainsString(
                'Backend/Order/partial/group-context.phtml',
                $source,
                $file
            );
        }
    }

    public function testListExposesChildAndShipmentBadges(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/index.phtml';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="order-list-child-badge"', $source);
        self::assertStringContainsString('data-testid="order-list-shipment-badge"', $source);
        self::assertStringContainsString('group_contexts', $source);
    }
}
