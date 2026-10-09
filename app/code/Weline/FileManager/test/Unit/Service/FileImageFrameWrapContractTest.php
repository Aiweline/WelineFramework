<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Service\FileAssetManager;

final class FileImageFrameWrapContractTest extends TestCase
{
    public function testNormalizeFrameRatioWhitelist(): void
    {
        self::assertSame('1', FileAssetManager::normalizeFrameRatio(800, 800));
        self::assertSame('16/9', FileAssetManager::normalizeFrameRatio(1600, 900));
        self::assertSame('4/3', FileAssetManager::normalizeFrameRatio(400, 300));
    }

    public function testResolveImageSourceDeclaresFrameHelper(): void
    {
        $src = dirname(__DIR__, 3) . '/Service/FileAssetManager.php';
        self::assertFileExists($src);
        $php = (string)file_get_contents($src);
        self::assertStringContainsString('maybeWrapLayoutFrame', $php);
        self::assertStringContainsString('class="w-frame"', $php);
        self::assertStringContainsString('normalizeFrameRatio', $php);
    }

    public function testTaglibPassesFrameAttribute(): void
    {
        $src = dirname(__DIR__, 3) . '/Taglib/Image.php';
        $php = (string)file_get_contents($src);
        self::assertStringContainsString("'frame' => false", $php);
        self::assertStringContainsString('Taglib__frame', $php);
    }
}
