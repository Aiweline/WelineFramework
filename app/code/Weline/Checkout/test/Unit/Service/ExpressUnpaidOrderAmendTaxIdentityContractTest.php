<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ExpressUnpaidOrderAmendTaxIdentityContractTest extends TestCase
{
    public function testAmendMergesBuyerTaxIntoTaxSnapshotJson(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressUnpaidOrderAmend.php');
        self::assertStringContainsString('mergeBuyerTaxIdentity', $src);
        self::assertStringContainsString('TAX_SNAPSHOT_JSON', $src);
        self::assertStringContainsString('BuyerTaxIdentityService', $src);
        self::assertStringContainsString('mergeIntoTaxSnapshot', $src);
    }

    public function testExpressFlowPassesTaxIdentityOnStartAndConfirm(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php');
        self::assertStringContainsString("'tax_identity'", $src);
        self::assertGreaterThanOrEqual(2, substr_count($src, "'tax_identity'"));
    }

    public function testConfirmQueryAcceptsTaxIdentity(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString("'name' => 'confirmExpressCheckout'", $src);
        self::assertMatchesRegularExpression(
            "/'name' => 'confirmExpressCheckout'[\s\S]*?'tax_identity' => \['type' => 'array'/",
            $src,
        );
    }
}
