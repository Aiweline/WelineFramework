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
        self::assertSame('Fabric', $translator->translate('织物', 'en_US'));
        self::assertSame('Polyester', $translator->translate('涤纶', 'en_US'));
        self::assertSame('Hanfu', $translator->translate('汉服', 'en_US'));
    }

    public function testKeepsUnknownTextUnchanged(): void
    {
        $translator = new StorefrontFacetTranslator();

        self::assertSame('Unknown Facet XYZ', $translator->translate('Unknown Facet XYZ', 'en_US'));
    }
}
