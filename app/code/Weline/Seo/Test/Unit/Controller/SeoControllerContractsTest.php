<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoControllerContractsPass().
 */
final class SeoControllerContractsTest extends TestCase
{
    public function testAllSeoControllerContractsPass(): void
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
            'WebsiteAccountDefaultSite::testManageAndSaveAcceptDefaultWebsiteIdZero' => function () {
            $this->assert_WebsiteAccountDefaultSite_testManageAndSaveAcceptDefaultWebsiteIdZero();
        },
        ];
    }

private function assert_WebsiteAccountDefaultSite_testManageAndSaveAcceptDefaultWebsiteIdZero(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Controller/Backend/WebsiteAccount.php');
    self::assertStringContainsString('if ($websiteId < 0)', $src);
    self::assertStringNotContainsString('if ($websiteId <= 0)', $src);
}
}
