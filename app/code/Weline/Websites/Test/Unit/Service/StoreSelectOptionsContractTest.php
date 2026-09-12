<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\StoreSelectOptions;

final class StoreSelectOptionsContractTest extends TestCase
{
    public function testFromRowsUsesStoreIdAsValue(): void
    {
        $options = StoreSelectOptions::fromRows([
            ['store_id' => 0, 'name' => '默认店铺', 'code' => 'default', 'is_default' => true],
            ['store_id' => 3, 'name' => '旗舰店', 'code' => 'flagship', 'is_default' => false],
            ['store_id' => 3, 'name' => '重复应跳过', 'code' => 'x'],
        ]);
        self::assertCount(2, $options);
        self::assertSame('0', $options[0]['value']);
        self::assertStringContainsString('#0', $options[0]['label']);
        self::assertSame('3', $options[1]['value']);
        self::assertSame('#3 旗舰店', StoreSelectOptions::resolveDisplay($options, '3'));
    }
}
