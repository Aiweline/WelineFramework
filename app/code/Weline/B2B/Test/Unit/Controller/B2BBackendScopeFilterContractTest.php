<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\B2B\Controller\Backend\ControlCenter;

require_once dirname(__DIR__) . '/bootstrap.php';

final class B2BBackendScopeFilterContractTest extends TestCase
{
    public function testFilterRowsByB2bScopeMatchesWebsiteAndChannelInheritance(): void
    {
        $method = (new ReflectionClass(ControlCenter::class))->getMethod('filterRowsByB2bScope');
        $method->setAccessible(true);
        $controller = (new ReflectionClass(ControlCenter::class))->newInstanceWithoutConstructor();

        $rows = [
            ['website_id' => 0, 'channel_id' => null, 'sku' => 'a'],
            ['website_id' => 0, 'channel_id' => 'ch-a', 'sku' => 'b'],
            ['website_id' => 1, 'channel_id' => 'ch-a', 'sku' => 'c'],
            ['website_id' => 0, 'channel_id' => 'ch-b', 'sku' => 'd'],
        ];

        $websiteOnly = $method->invoke($controller, $rows, 0, null);
        self::assertSame(['a', 'b', 'd'], array_column($websiteOnly, 'sku'));

        $channel = $method->invoke($controller, $rows, 0, 'ch-a');
        self::assertSame(['a', 'b'], array_column($channel, 'sku'));

        $all = $method->invoke($controller, $rows, null, null);
        self::assertCount(4, $all);
    }
}
