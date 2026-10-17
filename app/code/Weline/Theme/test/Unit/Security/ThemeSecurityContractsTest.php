<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Extends\Module\Weline_Framework\Security\Csp\ThemeEditorSiblingFrameCsp;
use Weline\Theme\Extends\Module\Weline_Framework\Security\Csp\ThemeVideoEmbedCsp;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeSecurityContractsPass().
 */
final class ThemeSecurityContractsTest extends TestCase
{
    public function testAllThemeSecurityContractsPass(): void
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
            'ThemeEditorSiblingFrameCsp::testAllowsLocalProjectSiblingHostsForEditorCanvas' => function () {
            $this->assert_ThemeEditorSiblingFrameCsp_testAllowsLocalProjectSiblingHostsForEditorCanvas();
        },
            'ThemeVideoEmbedCsp::testContributionDeclaresYoutubeAndVimeoHosts' => function () {
            $this->assert_ThemeVideoEmbedCsp_testContributionDeclaresYoutubeAndVimeoHosts();
        },
            'ThemeVideoEmbedCsp::testContributionDeclaresBilibiliHosts' => function () {
            $this->assert_ThemeVideoEmbedCsp_testContributionDeclaresBilibiliHosts();
        },
        ];
    }

private function assert_ThemeEditorSiblingFrameCsp_testAllowsLocalProjectSiblingHostsForEditorCanvas(): void
{
    $directives = (new ThemeEditorSiblingFrameCsp())->contribution()->directives;
    self::assertContains('https://*.test.weline.com', $directives['frame-src'] ?? []);
    self::assertContains('http://*.test.weline.com', $directives['frame-src'] ?? []);
    self::assertContains('https://*.weline.test', $directives['frame-src'] ?? []);
    self::assertContains('http://*.weline.test', $directives['frame-src'] ?? []);
}

private function assert_ThemeVideoEmbedCsp_testContributionDeclaresYoutubeAndVimeoHosts(): void
{
    $directives = (new ThemeVideoEmbedCsp())->contribution()->directives;
    self::assertContains('https://www.youtube.com', $directives['frame-src'] ?? []);
    self::assertContains('https://www.youtube-nocookie.com', $directives['frame-src'] ?? []);
    self::assertContains('https://player.vimeo.com', $directives['frame-src'] ?? []);
    self::assertContains('https://i.ytimg.com', $directives['img-src'] ?? []);
}

private function assert_ThemeVideoEmbedCsp_testContributionDeclaresBilibiliHosts(): void
{
    $directives = (new ThemeVideoEmbedCsp())->contribution()->directives;
    self::assertContains('https://player.bilibili.com', $directives['frame-src'] ?? []);
    self::assertContains('https://player.bilibili.com', $directives['connect-src'] ?? []);
    self::assertContains('https://i0.hdslb.com', $directives['img-src'] ?? []);
    self::assertContains('https://i1.hdslb.com', $directives['img-src'] ?? []);
    self::assertContains('https://i2.hdslb.com', $directives['img-src'] ?? []);
}
}
