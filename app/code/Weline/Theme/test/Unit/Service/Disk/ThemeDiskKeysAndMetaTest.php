<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Disk;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Helper\CssVariableParser;
use Weline\Theme\Service\Disk\ThemeDiskKeys;

class ThemeDiskKeysAndMetaTest extends TestCase
{
    public function testDiskKeyHelpers(): void
    {
        self::assertSame('theme.frontend.disk_active.color.value', ThemeDiskKeys::activeIdentify('frontend', 'color'));
        self::assertSame('theme.backend.disk_custom.spacing.my-disk.value', ThemeDiskKeys::customIdentify('backend', 'spacing', 'My Disk'));
        self::assertSame(['kind' => 'custom', 'key' => 'abc'], ThemeDiskKeys::parseActiveRef('custom:abc'));
        self::assertSame(['kind' => 'file', 'key' => 'amazon'], ThemeDiskKeys::parseActiveRef('file:amazon'));
    }

    public function testParserReadsPanelMeta(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/colors/_light.css';
        $meta = CssVariableParser::parseFileMeta($path);
        self::assertSame('color', $meta['panel'] ?? null);
        self::assertSame('mode', $meta['palette_role'] ?? null);
        self::assertSame('colors', $meta['disk_kind'] ?? null);
    }

    public function testLateSafeAllowsPrimaryNotCanvas(): void
    {
        /** @var CssVariableInjector $injector */
        $injector = \Weline\Framework\Manager\ObjectManager::getInstance(CssVariableInjector::class);
        self::assertTrue($injector->isLateSafeToken('--color-primary'));
        self::assertFalse($injector->isLateSafeToken('--color-bg-primary'));
        self::assertTrue($injector->isLateSafeToken('--spacing-md'));
    }
}
