<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Production OM leaves `?CheckoutTaxAdvisorInterface $taxAdvisor = null` as null.
 * freeze/submit must lazy-resolve Tax when installed (same pattern as discount).
 */
final class CheckoutTaxAdvisorRuntimeResolveContractTest extends TestCase
{
    public function testFreezePathResolvesTaxAdvisorAtRuntime(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertStringContainsString('resolveRuntimeTax', $src);
        self::assertStringContainsString('taxAdvisorService', $src);
        self::assertStringContainsString('CheckoutTaxAdvisorInterface::class', $src);
        self::assertStringContainsString('DutyEstimateService::NOTICE_DDU', $src);
        self::assertMatchesRegularExpression(
            '/\$taxAdvisor\s*=\s*\$this->taxAdvisorService\(\)/',
            $src
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$tax\s*=\s*\$this->taxAdvisor\s*!==\s*null/',
            $src
        );
    }
}
