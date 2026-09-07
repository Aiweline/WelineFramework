<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: account-center quotes menu + empty JS badge shells (no SSR counts).
 */
final class ProductAccountQuotesSignalContractTest extends TestCase
{
    public function testModelExposesCustomerReplySeenFieldsAndStableCodes(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Product/Model/ProductQuoteRequest.php');
        self::assertStringContainsString("schema_fields_CUSTOMER_ID = 'customer_id'", $src);
        self::assertStringContainsString("schema_fields_ADMIN_REPLY_AT = 'admin_reply_at'", $src);
        self::assertStringContainsString("schema_fields_CUSTOMER_LAST_SEEN_AT = 'customer_last_seen_at'", $src);
        self::assertStringContainsString("MENU_SIGNAL_CODE = 'product.quotes'", $src);
        self::assertStringContainsString("ACCOUNT_SECTION = 'product-quotes'", $src);
    }

    public function testServiceMarksReplyAndSupportsCustomerLifecycle(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php');
        self::assertStringContainsString('schema_fields_CUSTOMER_ID', $src);
        self::assertStringContainsString('schema_fields_ADMIN_REPLY_AT', $src);
        self::assertStringContainsString('function listForCustomer', $src);
        self::assertStringContainsString('function countUnreadForCustomer', $src);
        self::assertStringContainsString('function markSeenForCustomer', $src);
        self::assertStringContainsString('function claimOrphanQuotesForCustomer', $src);
        self::assertStringNotContainsString('Weline\\Inquiry', $src);
    }

    public function testSignalProviderRegistersProductQuotesCode(): void
    {
        $path = BP . 'app/code/Weline/Product/extends/module/Weline_Customer/AccountMenuSignalProvider/ProductQuoteMenuSignalProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('AccountMenuSignalProviderInterface', $src);
        self::assertStringContainsString('MENU_SIGNAL_CODE', $src);
        self::assertStringContainsString('countUnreadForCustomer', $src);
    }

    public function testAccountHooksUseEmptySignalShellsAndStableSection(): void
    {
        $commerce = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/account.sidebar.group.commerce.phtml'
        );
        $content = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/account.sidebar.content.phtml'
        );
        $header = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/header-account-links.phtml'
        );

        foreach ([$commerce, $header] as $src) {
            self::assertStringContainsString('data-account-menu-signal', $src);
            self::assertStringContainsString('MENU_SIGNAL_CODE', $src);
            self::assertStringContainsString('account-menu-signal-badge', $src);
            self::assertStringContainsString('hidden', $src);
            self::assertDoesNotMatchRegularExpression('/account-menu-signal-badge[^>]*>\s*\d+/', $src);
        }

        self::assertStringContainsString('ACCOUNT_SECTION', $commerce);
        self::assertStringContainsString('data-account-nav-link', $commerce);
        self::assertStringContainsString('data-account-section', $content);
        self::assertStringContainsString('markSeenForCustomer', $content);
        self::assertStringContainsString('listForCustomer', $content);
        self::assertStringContainsString('data-account-menu-auth="signed-in"', $header);
        self::assertStringNotContainsString('createFrontendSession', $header);
    }

    public function testCustomerMenuSignalsAndJsPaintRemainSsrFree(): void
    {
        $query = (string)file_get_contents(
            BP . 'app/code/Weline/Customer/extends/module/Weline_Framework/Query/AccountQueryProvider.php'
        );
        $js = (string)file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-account.js'
        );
        $header = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/account/default.phtml'
        );
        $accountJs = (string)file_get_contents(
            BP . 'app/code/Weline/Customer/view/statics/js/account-index.js'
        );

        self::assertStringContainsString("'menuSignals'", $query);
        self::assertStringContainsString('AccountMenuSignalAggregator', $query);
        self::assertStringContainsString('paintAccountMenuSignals', $js);
        self::assertStringContainsString('refreshAccountMenuSignals', $js);
        self::assertStringContainsString('data-account-menu-signal-total', $header);
        self::assertDoesNotMatchRegularExpression('/data-account-menu-signal-total[^>]*>\s*\d+/', $header);
        self::assertStringContainsString('refreshAccountMenuSignals', $accountJs);
    }

    public function testModuleVersionAndOptionalCustomer(): void
    {
        $module = include BP . 'app/code/Weline/Product/etc/module.php';
        self::assertSame('1.0.137', $module['version'] ?? null);
        self::assertSame('*', $module['optional']['Weline_Customer'] ?? null);
    }
}
