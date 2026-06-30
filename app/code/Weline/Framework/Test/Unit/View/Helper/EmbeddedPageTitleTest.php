<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Helper\EmbeddedPageTitle;

final class EmbeddedPageTitleTest extends TestCase
{
    public function testTreatsModuleCodeAsPlaceholder(): void
    {
        self::assertTrue(EmbeddedPageTitle::isModulePlaceholder('Weline_Customer'));
        self::assertTrue(EmbeddedPageTitle::isModulePlaceholder('WeShop_Subscription'));
        self::assertFalse(EmbeddedPageTitle::isModulePlaceholder('我的订阅服务'));
    }
}
