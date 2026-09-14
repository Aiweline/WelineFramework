<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * SHIP-SHELL-001：壳能力面、基类继承、Facade、壳禁供应商字面量。
 */
final class ShippingShellProviderContractTest extends TestCase
{
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testShellDocsAndInterfaceExist(): void
    {
        $root = $this->moduleRoot();
        self::assertFileExists($root . '/doc/shipping-shell.md');
        self::assertFileExists($root . '/doc/provider-development.md');
        self::assertFileExists($root . '/Interface/ShippingProviderInterface.php');
        self::assertFileExists($root . '/Service/Provider/AbstractShippingProvider.php');
        self::assertFileExists($root . '/Service/ShippingProviderScanner.php');
        self::assertFileExists($root . '/Service/ShippingProviderManager.php');
        self::assertFileExists($root . '/Service/ShippingFacade.php');
    }

    public function testRequirementMentionsShell(): void
    {
        $req = (string)file_get_contents($this->moduleRoot() . '/doc/需求.md');
        self::assertStringContainsString('SHIP-SHELL-001', $req);
        self::assertStringContainsString('AbstractShippingProvider', $req);
        self::assertMatchesRegularExpression('/~~不做承运商 API 实时询价~~|已由 SHIP-SHELL-001/', $req);
    }

    public function testInterfaceDeclaresFullCapabilitySurface(): void
    {
        $src = (string)file_get_contents($this->moduleRoot() . '/Interface/ShippingProviderInterface.php');
        foreach ([
            'getCode',
            'getProviderCode',
            'getProviderApiVersion',
            'getCapabilities',
            'getDisplayMetadata',
            'getConfigSchema',
            'getDynamicFormSchema',
            'cspDirectives',
            'checkAvailability',
            'defaultCoverageRegions',
            'quote',
            'createShipment',
            'cancelShipment',
            'confirmShipment',
            'getLabel',
            'queryTracking',
            'verifyCallback',
            'parseCallback',
            'testConnection',
            'normalizeError',
        ] as $method) {
            self::assertMatchesRegularExpression('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src);
        }
    }

    public function testAbstractImplementsInterface(): void
    {
        $src = (string)file_get_contents($this->moduleRoot() . '/Service/Provider/AbstractShippingProvider.php');
        self::assertStringContainsString('implements ShippingProviderInterface', $src);
        self::assertStringContainsString('ShippingQuoteResult::unsupported', $src);
    }

    public function testBuiltinProvidersExtendAbstract(): void
    {
        $local = (string)file_get_contents(
            $this->moduleRoot() . '/extends/module/Weline_Shipping/ShippingProvider/LocalRateTemplateProvider.php',
        );
        $yanwen = (string)file_get_contents(
            $this->moduleRoot() . '/extends/module/Weline_Shipping/ShippingProvider/YanwenProvider.php',
        );
        self::assertStringContainsString('extends AbstractShippingProvider', $local);
        self::assertStringContainsString("return 'local'", $local);
        self::assertStringContainsString('extends AbstractShippingProvider', $yanwen);
        self::assertStringContainsString("return 'yanwen'", $yanwen);
        self::assertFileExists(
            $this->moduleRoot() . '/extends/module/Weline_SystemConfig/Config/backend/yanwen.phtml',
        );
    }

    public function testQuoteRatesDelegatesToProviderWithoutVendorBranch(): void
    {
        $src = (string)file_get_contents($this->moduleRoot() . '/Service/ShippingServiceManager.php');
        self::assertStringContainsString('ShippingProviderManager', $src);
        self::assertStringContainsString('->quote(', $src);
        self::assertStringContainsString('checkAvailability', $src);
        self::assertDoesNotMatchRegularExpression('/if\s*\([^)]*[\'"]yanwen[\'"]/', $src);
        self::assertDoesNotMatchRegularExpression('/if\s*\([^)]*[\'"]local[\'"].*quote/i', $src);
    }

    public function testShellPhpHasNoVendorLiterals(): void
    {
        $root = $this->moduleRoot();
        $paths = [
            $root . '/Service/ShippingServiceManager.php',
            $root . '/Service/ShippingFacade.php',
            $root . '/Service/ShippingProviderManager.php',
            $root . '/Service/TrackingService.php',
            $root . '/Service/ShippingProviderScanner.php',
        ];
        foreach ($paths as $path) {
            $src = (string)file_get_contents($path);
            self::assertDoesNotMatchRegularExpression(
                '/yanwen|yw56|Yanwen/i',
                $src,
                'Shell must not hardcode vendor: ' . basename($path),
            );
        }
    }

    public function testTrackingRoutesViaFacade(): void
    {
        $src = (string)file_get_contents($this->moduleRoot() . '/Service/TrackingService.php');
        self::assertStringContainsString('ShippingFacade', $src);
        self::assertStringContainsString('queryTracking', $src);
        self::assertStringNotContainsString('北京分拨中心', $src);
    }

    public function testCarrierHasProviderCode(): void
    {
        $src = (string)file_get_contents($this->moduleRoot() . '/Model/Carrier.php');
        self::assertStringContainsString('PROVIDER_CODE', $src);
        self::assertStringContainsString('provider_code', $src);
    }

    public function testModuleVersion250(): void
    {
        $module = (string)file_get_contents($this->moduleRoot() . '/etc/module.php');
        self::assertStringContainsString("'2.5.2'", $module);
    }
}
