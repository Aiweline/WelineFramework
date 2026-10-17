<?php

declare(strict_types=1);

namespace Weline\Index\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllIndexControllerContractsPass().
 */
final class IndexControllerContractsTest extends TestCase
{
    public function testAllIndexControllerContractsPass(): void
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
            'IndexHomepageLayout::testIndexBindsThemeHomepageLayoutWhenFrontendThemeActive' => function () {
            $this->assert_IndexHomepageLayout_testIndexBindsThemeHomepageLayoutWhenFrontendThemeActive();
        },
        ];
    }

private function assert_IndexHomepageLayout_testIndexBindsThemeHomepageLayoutWhenFrontendThemeActive(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Index' . '/Controller/Index.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("\$this->layoutType = 'homepage'", $src);
    self::assertStringNotContainsString('homepage-shell', $src);
    self::assertStringContainsString('shouldUseThemeHomepageLayout', $src);
    self::assertStringContainsString('ThemeContextProviderInterface', $src);
    self::assertStringContainsString("Weline_Index::templates/Index.phtml", $src);
    self::assertSame(1, \substr_count($src, "Weline_Index::templates/Index.phtml"), 'themed homepage and fallback share one Index.phtml fetch; Theme skips content when layoutType=homepage');
}
}
