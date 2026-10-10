<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductExpressPaymentWidgetContractTest extends TestCase
{
    public function testProductExpressRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        $tpl = 'Weline_Payment::templates/Frontend/widgets/product-express-payment.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/product-express-payment.phtml');
        self::assertStringContainsString('@widget.code {product-express-payment}', $src);
        self::assertStringContainsString('@widget.slot {product-express-payment}', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"slot":"product-express-payment"', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('"enabled":true', $src);
        self::assertStringContainsString('@param enabled', $src);
        self::assertStringContainsString('@param logo', $src);
    }

    public function testProductExpressTemplateUsesShellAndPdpBridge(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/product-express-payment.phtml'
        );
        self::assertStringContainsString('data-testid="product-express-payment"', $tpl);
        self::assertStringContainsString('LegacyMediaUrl::sanitize', $tpl);
        self::assertStringContainsString('logo_file_html', $tpl);
        self::assertStringContainsString('PaymentExpressFacadeInterface', $tpl);
        self::assertStringContainsString('listExpressMethods', $tpl);
        self::assertStringContainsString('data-weline-load="productExpressPay"', $tpl);
        self::assertStringContainsString('data-product-express-pay', $tpl);
        self::assertStringContainsString('weline-pixel::express_pay', $tpl);
        self::assertStringContainsString('data-pixel-event="express_pay"', $tpl);
        self::assertStringContainsString('data-method-label=', $tpl);
        self::assertStringContainsString('w-payment-express--collapsible', $tpl);
        self::assertStringContainsString('w-payment-express__disclosure', $tpl);
        self::assertStringContainsString('w-payment-express__methods--always', $tpl);
        self::assertStringContainsString('w-payment-express__summary--more', $tpl);
        self::assertStringContainsString('w-payment-express__more-label', $tpl);
        self::assertStringContainsString('flex-direction: row', $tpl);
        self::assertStringNotContainsString('w-payment-express__summary--chevron-only', $tpl);
        self::assertStringNotContainsString('flex-direction: column', $tpl);
        self::assertStringNotContainsString('clip: rect(0, 0, 0, 0)', $tpl);
        self::assertStringContainsString('<details', $tpl);
        self::assertStringContainsString('<summary', $tpl);
        self::assertStringContainsString('w-payment-express__hint', $tpl);
        // PayPal / express buttons must stay outside <details> (always visible).
        $methodsPos = strpos($tpl, 'w-payment-express__methods--always');
        $detailsPos = strpos($tpl, '<details');
        self::assertNotFalse($methodsPos);
        self::assertNotFalse($detailsPos);
        self::assertLessThan($detailsPos, $methodsPos);
    }

    public function testProductInfoDeclaresExpressSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 4) . '/Product/extends/module/Weline_Widget/Weline_Product/widget.php';
        $tpl = 'Weline_Product::templates/frontend/widgets/product-info.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/Product/view/templates/frontend/widgets/product-info.phtml');
        self::assertStringContainsString('"product-express-payment"', $src);
    }

    public function testModulesRegistryListsProductExpressPay(): void
    {
        $mod = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('productExpressPay', $mod);
        self::assertStringContainsString('product-express-pay.js', $mod);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/js/product-express-pay.js');
    }

    public function testMorePaymentDetailsIsTranslatedForDefaultWebsiteLocales(): void
    {
        $expected = [
            'zh_Hans_CN' => '更多支付说明',
            'en_US' => 'More payment details',
            'ar_SA' => 'المزيد من تفاصيل الدفع',
            'bn_BD' => 'আরও পেমেন্ট তথ্য',
            'es_ES' => 'Más información de pago',
            'fr_FR' => "Plus d'infos sur le paiement",
            'hi_IN' => 'और भुगतान जानकारी',
            'id_ID' => 'Info pembayaran lainnya',
            'pt_BR' => 'Mais informações de pagamento',
            'ur_PK' => 'مزید ادائیگی کی تفصیل',
        ];
        $dir = dirname(__DIR__, 3) . '/i18n';
        foreach ($expected as $locale => $translate) {
            $map = $this->loadCsvMap($dir . '/' . $locale . '.csv');
            self::assertSame($translate, $map['更多支付说明'] ?? null, $locale);
            if ($locale !== 'zh_Hans_CN') {
                self::assertNotSame('更多支付说明', $map['更多支付说明'] ?? '更多支付说明', $locale);
            }
        }
    }

    /** @return array<string, string> */
    private function loadCsvMap(string $path): array
    {
        self::assertFileExists($path);
        $fh = fopen($path, 'rb');
        self::assertNotFalse($fh);
        $map = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (!isset($row[0], $row[1])) {
                continue;
            }
            $map[(string) $row[0]] = (string) $row[1];
        }
        fclose($fh);

        return $map;
    }
}
