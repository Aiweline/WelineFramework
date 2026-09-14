<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Filters\Service\StorefrontFacetTranslator;

final class StorefrontFacetTranslatorTest extends TestCase
{
    public function testTranslatesFiltersCsvKeysForEnglishLocale(): void
    {
        $translator = new StorefrontFacetTranslator();

        self::assertSame('Occasion', $translator->translate('场合', 'en_US'));
        self::assertSame('Style', $translator->translate('样式', 'en_US'));
        self::assertSame('Size', $translator->translate('大小', 'en_US'));
        self::assertSame('Fabric', $translator->translate('织物', 'en_US'));
        self::assertSame('Polyester', $translator->translate('涤纶', 'en_US'));
        self::assertSame('Hanfu', $translator->translate('汉服', 'en_US'));
        self::assertSame('Unisex style', $translator->translate('男女同款', 'en_US'));
        self::assertSame('Other', $translator->translate('其它', 'en_US'));
        self::assertSame('Default', $translator->translate('默认项', 'en_US'));
        self::assertSame('Huazhao Ji', $translator->translate('花朝记', 'en_US'));
        self::assertSame('Winter', $translator->translate('winter', 'en_US'));
        self::assertSame(
            '[Yearning Letter] Red wide-sleeved top + skirt',
            $translator->translate('【寄相思】Red wide-sleeved top + skirt', 'en_US'),
        );
    }

    public function testKeepsUnknownTextUnchanged(): void
    {
        $translator = new StorefrontFacetTranslator();

        self::assertSame('Unknown Facet XYZ', $translator->translate('Unknown Facet XYZ', 'en_US'));
    }
}
