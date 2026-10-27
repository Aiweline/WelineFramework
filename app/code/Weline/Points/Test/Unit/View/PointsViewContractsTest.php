<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

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
        self::assertStringContainsString('Promise.resolve(global.Weline.Api.resource', $src);
        self::assertStringContainsString("discount.quote", $src);
        self::assertStringContainsString("discount.apply", $src);
        self::assertStringContainsString("discount.clear", $src);
        self::assertStringContainsString('points_discount_apply_minor', $src);
        self::assertStringContainsString('.mini-cart-drawer', $src);
        self::assertStringContainsString('data-money-summary-payable-minor', $src);
        self::assertStringContainsString('bootAttempts', $src);
        self::assertStringContainsString('quoteAttempts', $src);
        self::assertStringContainsString('getApplyMinor', $src);
        self::assertStringNotContainsString('fetch(', $src);
        self::assertStringNotContainsString('alert(', $src);
    }

    public function testCheckoutSubmitFallsBackToPointsSession(): void
    {
        $src = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );

        self::assertStringContainsString('points_discount_apply_minor', $src);
        self::assertStringContainsString('PointsDiscountApplySessionInterface', $src);
        self::assertStringContainsString('$b2b > 0', $src);
    }

    public function testBackendMenuExposesPointsModule(): void
    {
        $src = (string) file_get_contents($this->modulePath('etc/backend/menu.xml'));

        self::assertStringContainsString('积分与会员', $src);
        self::assertStringContainsString('points/backend/points/index', $src);
        self::assertStringContainsString('points/backend/points/levels', $src);
        self::assertStringContainsString('points/backend/points/settings', $src);
        self::assertFileExists($this->modulePath('Controller/Backend/Points.php'));
        self::assertFileExists($this->modulePath('view/templates/backend/Points/index.phtml'));
    }

    public function testSettingsPageEmbedsPointsAndPaymentConfig(): void
    {
        $src = (string) file_get_contents($this->modulePath('view/templates/backend/Points/index.phtml'));

        self::assertStringContainsString('<w:config:embed', $src);
        self::assertStringContainsString('module="Weline_Points"', $src);
        self::assertStringContainsString('points/discount/enabled', $src);
        self::assertStringContainsString('module="Weline_Payment"', $src);
        self::assertStringContainsString('payment/asset/points/exchange_ratio', $src);
        self::assertStringContainsString('在统一配置中心打开', $src);
    }

    public function testBackendTemplateCompiles(): void
    {
        $file = $this->modulePath('view/templates/backend/Points/index.phtml');
        $source = (string) file_get_contents($file);
        $template = ObjectManager::getInstance(\Weline\Framework\View\Template::class);

        $html = $template->fetchSourceHtml(
            'Weline_Points::templates/backend/Points/index.phtml',
            $source,
            $file,
            'points-backend-contract-test',
            [],
        );

        self::assertIsString($html);
    }

    public function testCheckoutSubmitCarriesPointsApplyMinor(): void
    {
        $src = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/Checkout/view/frontend/checkout/index.phtml',
        );

        self::assertStringContainsString('points_discount_apply_minor', $src);
        self::assertStringContainsString('WelinePointsCheckout.getApplyMinor', $src);
    }
}
