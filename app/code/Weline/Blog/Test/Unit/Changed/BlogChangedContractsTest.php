<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\InvalidationEffect;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogChangedContractsPass().
 */
final class BlogChangedContractsTest extends TestCase
{
    public function testAllBlogChangedContractsPass(): void
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
            'BlogFpcDirectedPurge::testPostAndCategoryRecipesUseDirectedPurgeOnly' => function () {
            $this->assert_BlogFpcDirectedPurge_testPostAndCategoryRecipesUseDirectedPurgeOnly();
        },
            'BlogFpcDirectedPurge::testMutationImpactAlwaysIncludesBlogIndex' => function () {
            $this->assert_BlogFpcDirectedPurge_testMutationImpactAlwaysIncludesBlogIndex();
        },
        ];
    }

private function assert_BlogFpcDirectedPurge_testPostAndCategoryRecipesUseDirectedPurgeOnly(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Blog';
    foreach (['BlogPostChangedType.php', 'BlogCategoryChangedType.php'] as $file) {
        $src = (string) file_get_contents($root . '/extends/module/Weline_Framework/Changed/Type/' . $file);
        self::assertStringContainsString('CODE_PURGE_FPC_URLS', $src, $file);
        self::assertStringContainsString('CODE_BUMP_NAMESPACES', $src, $file);
        self::assertStringNotContainsString('CODE_PURGE_FPC_ALL', $src, $file);
        self::assertStringNotContainsString(InvalidationEffect::CODE_PURGE_FPC_ALL, $src, $file);
    }
}

private function assert_BlogFpcDirectedPurge_testMutationImpactAlwaysIncludesBlogIndex(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentMutation.php');
    self::assertStringContainsString("'/blog' => '/blog'", $src);
    self::assertStringContainsString("'/blog/'", $src);
    self::assertStringContainsString("'/blog/category/'", $src);
}
}
