<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * Multi-select trigger must show domain capsules, not "N domains selected" summaries.
 */
final class DomainSelectSelectedCapsuleContractTest extends TestCase
{
    public function testDomainSelectRendersSelectedDomainCapsulesAndHydratesOnLoad(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/DomainSelect.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("'selected-domains' => false", $source);
        self::assertStringContainsString('data-selected-domains=', $source);
        self::assertStringContainsString('weline-domain-select-tag', $source);
        self::assertStringContainsString('function hydrateInitialSelection()', $source);
        self::assertStringContainsString('hydrateInitialSelection();', $source);
        self::assertStringNotContainsString("__('已选择 %s 个域名')", $source);
        self::assertStringNotContainsString('$t_selected', $source);
    }

    public function testWebsiteFormPassesSelectedDomainNamesInsteadOfCountSummary(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Admin/Website/form.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('selected-domains="domainSelectSelectedDomainsEscaped"', $source);
        self::assertStringContainsString('website-id="domainSelectWebsiteId"', $source);
        self::assertStringContainsString("__('点击选择域名（可多选）')", $source);
        self::assertStringNotContainsString("__('已选择 %{1} 个域名'", $source);
    }
}
