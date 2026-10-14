<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Currency order drag: soft catalog invalidation only — no website-level changed / FPC wipe.
 */
final class CurrencyCatalogReorderSoftInvalidationContractTest extends TestCase
{
    public function testReorderWebsiteUsesSoftPathNotSetWebsiteCurrencies(): void
    {
        $controller = \file_get_contents(
            BP . '/app/code/Weline/Websites/Controller/Admin/ScopeCurrency.php'
        );
        self::assertIsString($controller);
        self::assertStringContainsString('reorderWebsiteCurrencies', $controller);
        self::assertStringContainsString('ResponseTerminateException', $controller);
        if (!\preg_match(
            '/function postReorderWebsite\(\)\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /',
            $controller,
            $m
        )) {
            self::fail('postReorderWebsite body not found');
        }
        $body = $m[1];
        self::assertStringContainsString('reorderWebsiteCurrencies', $body);
        self::assertStringNotContainsString('->setWebsiteCurrencies(', $body);
    }

    public function testReorderModelSkipsHeavyInvalidate(): void
    {
        $model = \file_get_contents(BP . '/app/code/Weline/Websites/Model/WebsiteCurrency.php');
        self::assertIsString($model);
        self::assertStringContainsString('function reorderWebsiteCurrencies', $model);
        self::assertStringContainsString('CurrencyCatalogInvalidationService', $model);
        if (!\preg_match(
            '/function reorderWebsiteCurrencies\([^)]*\): self\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /',
            $model,
            $m
        )) {
            self::fail('reorderWebsiteCurrencies body not found');
        }
        $body = $m[1];
        self::assertStringContainsString('invalidateAfterReorder', $body);
        self::assertStringNotContainsString('invalidateWebsiteCurrency', $body);
    }

    public function testSoftServiceAvoidsWebsiteChanged(): void
    {
        $service = \file_get_contents(
            BP . '/app/code/Weline/Websites/Service/Localization/CurrencyCatalogInvalidationService.php'
        );
        self::assertIsString($service);
        self::assertStringContainsString('bumpCurrencyCatalogOnly', $service);
        self::assertStringNotContainsString('->invalidateWebsite(', $service);
        self::assertStringNotContainsString('invalidateWebsite(', $service);
    }

    public function testCurrencySelectAsyncSavesOnTagDrag(): void
    {
        $js = \file_get_contents(BP . '/app/code/Weline/Currency/view/statics/js/currency-select.js');
        $css = \file_get_contents(BP . '/app/code/Weline/Currency/view/statics/css/currency-select.css');
        $form = \file_get_contents(BP . '/app/code/Weline/Websites/view/templates/Admin/Website/form.phtml');
        self::assertIsString($js);
        self::assertIsString($css);
        self::assertIsString($form);
        self::assertStringContainsString('scheduleReorderSave', $js);
        self::assertStringContainsString('resolveReorderUrl', $js);
        self::assertStringContainsString('data-w-currency-reorder-url', $js);
        self::assertStringContainsString('is-sortable', $js);
        self::assertStringContainsString('w-currency-select__drag-handle', $js);
        self::assertStringContainsString('cursor: grab', $css);
        self::assertStringContainsString("data-w-currency-reorder-url=\"@backend-url('*/admin/scope-currency/reorder-website')\"", $form);
        $taglib = \file_get_contents(BP . '/app/code/Weline/Currency/Taglib/CurrencySelect.php');
        self::assertIsString($taglib);
        self::assertStringContainsString('role="button"', $taglib);
        self::assertStringContainsString('w-currency-select__trigger', $taglib);
        self::assertDoesNotMatchRegularExpression('/<button\\s+[^>]*w-currency-select__trigger/', $taglib);
    }

    public function testBumpCurrencyCatalogOnlySkipsApplyCommittedLegacyPath(): void
    {
        $svc = \file_get_contents(
            BP . '/app/code/Weline/Websites/Service/WebsiteCacheInvalidationService.php'
        );
        self::assertIsString($svc);
        self::assertStringContainsString('function bumpCurrencyCatalogOnly', $svc);
        if (!\preg_match(
            '/function bumpCurrencyCatalogOnly\([\s\S]*?\n    (?:public |private |protected |\/\*\*)/',
            $svc,
            $m
        )) {
            self::fail('bumpCurrencyCatalogOnly body not found');
        }
        $body = $m[0];
        self::assertStringContainsString('COMPONENT_CURRENCY', $body);
        self::assertStringNotContainsString('invalidatePaths', $body);
    }
}
