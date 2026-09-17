<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class VideoPlayerEmbedFallbackContractTest extends TestCase
{
    public function testWidgetUsesResolverAndHonestEmptyCopy(): void
    {
        $file = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/video/video-player/default.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);

        self::assertStringContainsString('VideoEmbedResolver', $src);
        self::assertStringContainsString('coalesceSourceUrl', $src);
        self::assertStringContainsString('请填写视频地址', $src);
        self::assertStringNotContainsString('视频加载中...', $src);
        self::assertStringContainsString('$playbackMuted = $muted', $src);
        self::assertStringNotContainsString('$playbackMuted = $muted || $autoplay', $src);
        self::assertStringNotContainsString('bindVideoAutoplayMuteCoupling', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js'
        ));
        self::assertStringContainsString('type="url"', $src);
        self::assertStringContainsString('label="视频地址"', $src);
        self::assertStringNotContainsString('已关闭静音', $src);
    }
}
