<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Widget\Service\WidgetPreviewService;

final class WidgetPreviewSanitizeTrustedEmbedTest extends TestCase
{
    public function testKeepsTrustedYoutubeIframeAndStripsOthers(): void
    {
        $service = (new ReflectionClass(WidgetPreviewService::class))->newInstanceWithoutConstructor();
        $html = $service->sanitizePreviewHtml(
            '<div class="wrap">'
            . '<iframe class="video-iframe" src="https://www.youtube.com/embed/CF_afGTGgUY?autoplay=1&mute=1" allow="autoplay"></iframe>'
            . '<iframe src="https://evil.example/embed"></iframe>'
            . '</div>'
        );

        self::assertStringContainsString('youtube.com/embed/CF_afGTGgUY', $html);
        self::assertStringContainsString('sandbox=', $html);
        self::assertStringNotContainsString('evil.example', $html);
    }
}
