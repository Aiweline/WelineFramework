<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\WarmupProvider;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnWarmupProviderContractsPass().
 */
final class CdnWarmupProviderContractsTest extends TestCase
{
    public function testAllCdnWarmupProviderContractsPass(): void
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
            'FpcExtraDeclaredUrls::testSkipsProductPatternsInSource' => function () {
            $this->assert_FpcExtraDeclaredUrls_testSkipsProductPatternsInSource();
        },
            'FpcExtraDeclaredUrls::testExecuteMethodExists' => function () {
            $this->assert_FpcExtraDeclaredUrls_testExecuteMethodExists();
        },
        ];
    }

private function assert_FpcExtraDeclaredUrls_testSkipsProductPatternsInSource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/WarmupProvider/FpcExtraDeclaredUrls.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('isProductPattern', $src);
    $this->assertStringContainsString('/product', $src);
    $this->assertStringContainsString('BlogSitemapUrlProvider', $src);
    $this->assertStringContainsString('FaqSitemapUrlProvider', $src);
    $this->assertStringContainsString('WarmupLocaleUrlExpander', $src);
    $this->assertStringContainsString('SOURCE_MODULE', $src);
    $this->assertStringContainsString('覆盖站点已启用语种', $src);
}

private function assert_FpcExtraDeclaredUrls_testExecuteMethodExists(): void
{
    $this->assertTrue(method_exists(FpcExtraDeclaredUrls::class, 'execute'));
    $this->assertSame('静态文档（FPC 公开页）', FpcExtraDeclaredUrls::UI_LABEL);
}
}
