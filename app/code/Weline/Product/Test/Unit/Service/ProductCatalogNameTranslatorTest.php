<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCatalogNameTranslator;

final class ProductCatalogNameTranslatorTest extends TestCase
{
    public function testItTranslatesGasolineAndElectricDirtBikeTitles(): void
    {
        $translator = new ProductCatalogNameTranslator();

        self::assertSame(
            'ZTOT Z8-MAX YBS300 PRO 295cc 汽油越野摩托',
            $translator->toZhHans('ZTOT Z8-MAX YBS300 PRO 295cc Gasoline Dirt Bike'),
        );
        self::assertSame(
            'HEZZO D9 Pro 电动越野摩托',
            $translator->toZhHans('HEZZO D9 Pro Electric Dirt Bike'),
        );
        self::assertSame(
            'STN G11 PRO MT300 293cc 二冲程越野摩托',
            $translator->toZhHans('STN G11 PRO MT300 293cc Two-Stroke Dirt Bike'),
        );
        self::assertSame(
            'ZTOT Z7MAX YBS300PRO 摇篮架版 295cc 汽油越野摩托',
            $translator->toZhHans('ZTOT Z7MAX YBS300PRO Cradle Version 295cc Gasoline Dirt Bike'),
        );
    }
}
