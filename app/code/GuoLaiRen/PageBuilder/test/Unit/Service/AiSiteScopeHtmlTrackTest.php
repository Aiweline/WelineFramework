<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteScopeHtmlTrackTest extends TestCase
{
    public function testHtmlTrackCompleteWhenAllTypesHaveBlocks(): void
    {
        $svc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $pts = [Page::TYPE_HOME, Page::TYPE_ABOUT];
        $vp = [
            Page::TYPE_HOME => ['blocks' => [['block_id' => 'a', 'type' => 'h', 'html' => '<p>x</p>']]],
            Page::TYPE_ABOUT => ['blocks' => [['block_id' => 'b', 'type' => 'h', 'html' => '<p>y</p>']]],
        ];
        self::assertTrue($svc->htmlTrackHasCompleteBlocks($vp, $pts));
    }

    public function testNormalizeScopeDefaultsSiteReady(): void
    {
        $svc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $n = $svc->normalizeScope([]);
        self::assertSame(1, (int)($n['site_ready'] ?? 0));
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME, $n['workspace_track']);
    }
}
