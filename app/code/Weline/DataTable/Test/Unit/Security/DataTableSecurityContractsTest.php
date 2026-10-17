<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Extends\Module\Weline_Framework\Security\Csp\DataTablePublicCdnCsp;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDataTableSecurityContractsPass().
 */
final class DataTableSecurityContractsTest extends TestCase
{
    public function testAllDataTableSecurityContractsPass(): void
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
            'DataTablePublicCdnCsp::testContributionDeclaresPublicCdnHosts' => function () {
            $this->assert_DataTablePublicCdnCsp_testContributionDeclaresPublicCdnHosts();
        },
        ];
    }

private function assert_DataTablePublicCdnCsp_testContributionDeclaresPublicCdnHosts(): void
{
    $directives = (new DataTablePublicCdnCsp())->contribution()->directives;
    self::assertContains('https://cdn.jsdelivr.net', $directives['script-src'] ?? []);
    self::assertContains('https://cdnjs.cloudflare.com', $directives['style-src'] ?? []);
    self::assertContains('https://unpkg.com', $directives['script-src'] ?? []);
    self::assertContains('https://fonts.googleapis.com', $directives['style-src'] ?? []);
}
}
