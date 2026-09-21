<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Taglib\Image;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;

class FileImageStaticMirrorContractTest extends TestCase
{
    public function testImplementsStaticMirrorCapable(): void
    {
        self::assertTrue(is_a(Image::class, StaticMirrorCapableInterface::class, true));
    }

    public function testDynamicUsageFallsThroughToPhpEmit(): void
    {
        $callback = Image::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'usage' => '<?= $heroUsage ?>',
            'width' => '16',
            'height' => '9',
        ]);

        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('FileImageRenderer', $result);
    }

    public function testLiteralWithoutLayoutDoesNotMirror(): void
    {
        $callback = Image::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'asset' => 'some-asset-id',
            'alt' => 'demo',
        ]);

        // Missing width/height/aspect_ratio → keep runtime PHP (CLS gate).
        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('FileImageRenderer', $result);
    }

    public function testTryStaticMirrorReturnsNullWhenRequestContextMissing(): void
    {
        // Without a live ScopeIdentity/locale, mirror must refuse rather than throw.
        $mirrored = Image::tryStaticMirror('tag-self-close-with-attrs', [], [
            'asset' => 'nonexistent-asset-for-mirror-test',
            'alt' => 'demo',
            'width' => '16',
            'height' => '9',
            'locale' => 'zh_Hans_CN',
        ]);

        self::assertNull($mirrored);
    }
}
