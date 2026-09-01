<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 业务不做礼品心愿单 / 礼品卡：顶栏与 main-nav 默认不得硬链 /registry、/gift-cards。
 */
final class HeaderNavNoGiftLinksContractTest extends TestCase
{
    public function testHeaderDefaultOmitsGiftRegistryAndGiftCardLinks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("@url{'promotion/deals'}", $src);
        self::assertStringContainsString("@url{'help'}", $src);
        self::assertStringNotContainsString("@url{'registry'}", $src);
        self::assertStringNotContainsString("@url{'gift-cards'}", $src);
        self::assertStringNotContainsString('礼品心愿单', $src);
        self::assertStringNotContainsString('礼品卡', $src);
    }

    public function testMainNavDefaultShortcutsOmitGiftLinks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/main-nav/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("'/promotion/deals'", $src);
        self::assertStringContainsString("'/help'", $src);
        self::assertStringNotContainsString("'/registry'", $src);
        self::assertStringNotContainsString("'/gift-cards'", $src);
        self::assertStringNotContainsString('礼品心愿单', $src);
        self::assertStringNotContainsString('礼品卡', $src);
    }
}
