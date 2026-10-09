<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Website add/edit must merge domain_values when pool_ids is empty (chip display ≠ pool bind).
 */
final class WebsiteDomainSelectionSaveContractTest extends TestCase
{
    public function testEditAndAddUseDomainValuesWhenBuildingAddressList(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Admin/Website.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('WebsiteDomainSelectionAddressListBuilder', $source);
        self::assertStringContainsString('buildAddressListFromDomainSelection', $source);
        self::assertStringContainsString('preserveExistingDomainSubPathsUnlessUnifiedChanged', $source);
        self::assertStringContainsString('domainPrimaryPreferenceScore', $source);
        self::assertStringContainsString("\$data['domain_values']", $source);
        self::assertSame(
            2,
            substr_count($source, 'buildAddressListFromDomainSelection($poolIds, $domainValues, $subPath)')
        );
    }
}
