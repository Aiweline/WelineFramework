<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Framework\Security\Csp\ProductMediaEmbedCsp;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductSecurityContractsPass().
 */
final class ProductSecurityContractsTest extends TestCase
{
    public function testAllProductSecurityContractsPass(): void
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
            'ProductMediaEmbedCsp::testContributionDeclaresYoutubeAndVimeoHosts' => function () {
            $this->assert_ProductMediaEmbedCsp_testContributionDeclaresYoutubeAndVimeoHosts();
        },
        ];
    }

private function assert_ProductMediaEmbedCsp_testContributionDeclaresYoutubeAndVimeoHosts(): void
{
    $directives = (new ProductMediaEmbedCsp())->contribution()->directives;
    self::assertContains('https://www.youtube.com', $directives['frame-src'] ?? []);
    self::assertContains('https://www.youtube-nocookie.com', $directives['frame-src'] ?? []);
    self::assertContains('https://player.vimeo.com', $directives['frame-src'] ?? []);
    self::assertContains('https://i.ytimg.com', $directives['img-src'] ?? []);
}
}
