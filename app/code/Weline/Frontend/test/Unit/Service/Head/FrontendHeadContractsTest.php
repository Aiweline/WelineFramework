<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Frontend\Service\Head\TitleComposer;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFrontendHeadContractsPass().
 */
final class FrontendHeadContractsTest extends TestCase
{
    public function testAllFrontendHeadContractsPass(): void
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
            'TitleComposerFit::testComposedBlogTitleFitsSoftSerpBudgetWithShortBrand' => function () {
            $this->assert_TitleComposerFit_testComposedBlogTitleFitsSoftSerpBudgetWithShortBrand();
        },
            'TitleComposerFit::testShortTitlesStillAppendFullSiteName' => function () {
            $this->assert_TitleComposerFit_testShortTitlesStillAppendFullSiteName();
        },
        ];
    }

private function assert_TitleComposerFit_testComposedBlogTitleFitsSoftSerpBudgetWithShortBrand(): void
{
    $composer = new TitleComposer();
    $title = $composer->compose(null, ['page_title' => 'Jino Occasions & Craft: Festival vs Daily Dress', 'site_name' => "Chang'an Hanfu"]);
    self::assertLessThanOrEqual(65, mb_strlen($title));
    self::assertGreaterThanOrEqual(30, mb_strlen($title));
    self::assertStringContainsString('Jino Occasions', $title);
    self::assertStringContainsString("Chang'an Hanfu", $title);
    self::assertStringNotContainsString('...', $title);
}

private function assert_TitleComposerFit_testShortTitlesStillAppendFullSiteName(): void
{
    $composer = new TitleComposer();
    $title = $composer->compose(null, ['page_title' => 'Blog', 'site_name' => "Chang'an Hanfu"]);
    self::assertSame("Blog | Chang'an Hanfu", $title);
}
}
