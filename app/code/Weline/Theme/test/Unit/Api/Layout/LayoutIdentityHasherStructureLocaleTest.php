<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Layout;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutIdentityHasher;

final class LayoutIdentityHasherStructureLocaleTest extends TestCase
{
    public function testStructureHashesIgnoreLocaleCode(): void
    {
        $zh = new LayoutIdentity('default', 'shop.main.default', 'global', 0, 'zh_Hans_CN');
        $en = new LayoutIdentity('default', 'shop.main.default', 'global', 0, 'en_US');
        $empty = new LayoutIdentity('default', 'shop.main.default', 'global', 0, '');

        self::assertSame(
            LayoutIdentityHasher::base(9, 'homepage', $zh),
            LayoutIdentityHasher::base(9, 'homepage', $en),
        );
        self::assertSame(
            LayoutIdentityHasher::base(9, 'homepage', $zh),
            LayoutIdentityHasher::base(9, 'homepage', $empty),
        );
        self::assertSame(
            LayoutIdentityHasher::virtual(9, 'frontend', 'homepage', $zh),
            LayoutIdentityHasher::virtual(9, 'frontend', 'homepage', $en),
        );
        self::assertSame(
            LayoutIdentityHasher::injection(9, 'frontend', 'homepage', $zh, 'slot:header'),
            LayoutIdentityHasher::injection(9, 'frontend', 'homepage', $en, 'slot:header'),
        );
    }
}
