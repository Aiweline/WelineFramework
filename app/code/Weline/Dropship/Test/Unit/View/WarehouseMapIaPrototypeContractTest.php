<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Contract: 货源履约仓 IA 原型（可丢弃）须保留三变体与推荐判定。 */
final class WarehouseMapIaPrototypeContractTest extends TestCase
{
    public function testIaPrototypeHasThreeVariantsAndRecommendsProviderCoverage(): void
    {
        $path = dirname(__DIR__, 3) . '/view/prototypes/warehouse-map-ia.html';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        self::assertStringContainsString('PROTOTYPE', $html);
        self::assertStringContainsString('variant=A', $html);
        self::assertStringContainsString('variant=B', $html);
        self::assertStringContainsString('variant=C', $html);
        self::assertStringContainsString('货源覆盖', $html);
        self::assertStringContainsString('推荐 A', $html);
        self::assertStringContainsString('万能货源', $html);
        self::assertStringContainsString('远程履约原点', $html);
    }
}
