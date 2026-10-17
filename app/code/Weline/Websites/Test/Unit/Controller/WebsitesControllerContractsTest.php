<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesControllerContractsPass().
 */
final class WebsitesControllerContractsTest extends TestCase
{
    public function testAllWebsitesControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WebsiteDomainSelectionSave::testEditAndAddUseDomainValuesWhenBuildingAddressList' => function () {
            $this->assert_WebsiteDomainSelectionSave_testEditAndAddUseDomainValuesWhenBuildingAddressList();
        },
        ];
    }

private function assert_WebsiteDomainSelectionSave_testEditAndAddUseDomainValuesWhenBuildingAddressList(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('WebsiteDomainSelectionAddressListBuilder', $source);
    self::assertStringContainsString('buildAddressListFromDomainSelection', $source);
    self::assertStringContainsString('preserveExistingDomainSubPathsUnlessUnifiedChanged', $source);
    self::assertStringContainsString('domainPrimaryPreferenceScore', $source);
    self::assertStringContainsString("\$data['domain_values']", $source);
    self::assertSame(2, substr_count($source, 'buildAddressListFromDomainSelection($poolIds, $domainValues, $subPath)'));
}
}
