<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeBrandResolver;

/**
 * Website Scope brand assets for the default storefront — contract that favicon/logo media exist
 * under pub/media/websites/.../brand (not Theme package defaults).
 */
final class ThemeWebsiteChanganBrandAssetsContractTest extends TestCase
{
    public function testBrandResolverKeysRemainFourAppearanceBrandSlots(): void
    {
        self::assertSame(
            ['favicon', 'apple_touch_icon', 'logo_light', 'logo_dark'],
            ThemeBrandResolver::KEYS,
        );
    }

    public function testChanganWebsiteBrandPngsExistUnderMediaBrandDir(): void
    {
        $root = \dirname(__DIR__, 7);
        $brandDir = $root . '/pub/media/websites/default/default/brand';
        $files = [
            'changan-hanfu-icon-v1.png',
            'changan-hanfu-apple-touch-icon-v1.png',
            'changan-hanfu-logo-v1.png',
            // Website 基础信息当前发布槽（appearance.brand）
            'changan-hanfu-icon-v6.png',
            'changan-hanfu-apple-touch-icon-v6.png',
            'changan-hanfu-logo-v6.jpg',
        ];
        foreach ($files as $name) {
            $path = $brandDir . '/' . $name;
            self::assertFileExists($path, $name . ' must exist for website appearance.brand');
            $bytes = (string)\file_get_contents($path);
            self::assertNotSame('', $bytes);
            if (\str_ends_with(\strtolower($name), '.jpg') || \str_ends_with(\strtolower($name), '.jpeg')) {
                self::assertSame("\xFF\xD8\xFF", \substr($bytes, 0, 3), $name . ' must be JPEG');
            } else {
                self::assertSame("\x89PNG", \substr($bytes, 0, 4), $name . ' must be PNG');
            }
        }
    }
}
