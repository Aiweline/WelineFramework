<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryFormBootstrap;

final class SupplierApplicationContractTest extends TestCase
{
    public function testBootstrapDefinesSupplierApplicationCode(): void
    {
        self::assertSame('supplier-application', InquiryFormBootstrap::CODE_SUPPLIER_APPLICATION);
    }

    public function testSuppliersTemplateRendersInquiryInline(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/suppliers.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="inquiry-supplier-application"', $src);
        self::assertStringContainsString('InquiryRendererInterface::class', $src);
        self::assertStringContainsString('InquiryFormBootstrap::CODE_SUPPLIER_APPLICATION', $src);
        self::assertStringContainsString("'mode' => 'inline'", $src);
        self::assertStringContainsString("'skin' => 'amazon'", $src);
        self::assertStringContainsString('w-inquiry-suppliers--amazon', $src);
        self::assertStringContainsString('Weline_Inquiry::css/supplier-application-amazon.css', $src);
        self::assertStringNotContainsString('<w:inquiry', $src);
    }

    public function testAmazonSkinCssExists(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/supplier-application-amazon.css';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('--amz-cta-bg', $src);
        self::assertStringContainsString('.weline-inquiry--amazon', $src);
    }

    public function testRendererSupportsAmazonSkinOption(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryRenderer.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("in_array(\$input, ['amazon'], true)", $src);
        self::assertStringContainsString('weline-inquiry--', $src);
        self::assertStringContainsString('data-inquiry-skin', $src);
        self::assertStringContainsString('weline-inquiry__submit', $src);
        self::assertStringContainsString('type==="country"', $src);
        self::assertStringContainsString('data-w-address', $src);
        self::assertStringContainsString('address-loader.js', $src);
        self::assertStringContainsString('catalog:countryCatalog', $src);
    }

    public function testBootstrapUsesThemeCountryFieldType(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryFormBootstrap.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'validation' => ['catalog' => 'global']", $src);
    }

    public function testSuppliersControllerUsesDefaultLayout(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Suppliers.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("layoutType = 'default'", $src);
        self::assertStringContainsString('templates/frontend/suppliers.phtml', $src);
        self::assertStringContainsString('供应商申请', $src);
    }

    public function testUpgradeCallsSupplierBootstrap(): void
    {
        $path = dirname(__DIR__, 3) . '/Setup/Upgrade.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('InquiryFormBootstrap', $src);
        self::assertStringContainsString('ensureSupplierApplication', $src);
    }
}
