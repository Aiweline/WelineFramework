<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutFaultSnapshotContractTest extends TestCase
{
    public function testGetDataReturnsQuoteTokenAndUsesResponseDiagnostics(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString("'quote_token' => \$quoteToken", $src);
        self::assertStringContainsString('existingQuoteToken:', $src);
        self::assertStringContainsString("'quote_diagnostics' => \$quoteDiagnostics", $src);
        self::assertStringNotContainsString('getLastQuoteDiagnostics()', $src);
        self::assertStringContainsString("'name' => 'getData'", $src);
        self::assertStringContainsString("'quote_token' => ['type' => 'string', 'required' => false, 'max_length' => 64]", $src);
    }

    public function testStorefrontPersistsQuoteToken(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml',
        );
        self::assertStringContainsString('quote_token: readStoredQuoteToken()', $tpl);
        self::assertStringContainsString('persistQuoteToken', $tpl);
        self::assertStringContainsString('weline_checkout_quote_token_w', $tpl);
    }

    public function testAdminSessionFilterHasErrorOnly(): void
    {
        $ctrl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Session.php',
        );
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Session/index.phtml',
        );
        self::assertStringContainsString("where(CheckoutSession::schema_fields_ERROR_CODE, null, 'is not null')", $ctrl);
        self::assertStringContainsString('只看卡住的结账', $tpl);
        self::assertStringContainsString('checkout-session-error-snapshot', $tpl);
        self::assertStringContainsString('技术明细', $tpl);
        self::assertStringContainsString('CheckoutSessionAdminPresenter', $ctrl);
        self::assertStringContainsString('schema_fields_CHECKOUT_ENTRY', $ctrl);
        self::assertStringContainsString('checkout-session-entry-filter', $tpl);
        self::assertStringContainsString('checkout-session-entry', $tpl);
        self::assertStringContainsString('data-w-width="auto"', $tpl);
        self::assertStringNotContainsString('weline_checkout_fault_snapshot', $ctrl);
    }

    public function testFreezeQuotePassesCheckoutEntry(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString('checkoutEntry: $this->resolveCheckoutEntry($params)', $src);
        self::assertStringContainsString('function resolveCheckoutEntry', $src);
        $express = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
        self::assertStringContainsString("CheckoutEntry::EXPRESS", $express);
    }
}
