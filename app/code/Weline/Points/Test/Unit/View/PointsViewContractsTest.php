<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PointsViewContractsTest extends TestCase
{
    private function modulePath(string $relative): string
    {
        return \dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
    }

    public function testHookLoadsPointsModuleAndCarriesLabels(): void
    {
        $src = (string) file_get_contents(
            $this->modulePath('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'),
        );

        self::assertStringContainsString('data-weline-load="pointsCheckout"', $src);
        self::assertStringContainsString('data-label-title=', $src);
        self::assertStringContainsString('data-label-apply=', $src);
        self::assertStringContainsString("/admin", $src);
    }

    public function testModuleMapRegistersPointsCheckout(): void
    {
        $src = (string) file_get_contents($this->modulePath('view/statics/frontend/weline.modules.js'));

        self::assertStringContainsString('pointsCheckout', $src);
        self::assertStringContainsString('Weline_Points::js/points-discount.js', $src);
        self::assertStringContainsString('WelinePointsCheckout', $src);
    }

    public function testJsUsesWelineApiAndCheckoutSubmitField(): void
    {
        $src = (string) file_get_contents($this->modulePath('view/statics/js/points-discount.js'));

        self::assertStringContainsString("Weline.Api.resource('points')", $src);
        self::assertStringContainsString("discount.quote", $src);
        self::assertStringContainsString("discount.apply", $src);
        self::assertStringContainsString("discount.clear", $src);
        self::assertStringContainsString('points_discount_apply_minor', $src);
        self::assertStringNotContainsString('fetch(', $src);
        self::assertStringNotContainsString('alert(', $src);
    }
}
