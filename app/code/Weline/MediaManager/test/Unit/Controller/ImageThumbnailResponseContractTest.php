<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * /media/image/* must emit framework Response bytes, not PHP header()+HTML-normalized string.
 */
final class ImageThumbnailResponseContractTest extends TestCase
{
    public function testImageControllerUsesFrameworkResponseTerminate(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Image.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Response::fromContent', $src);
        self::assertStringContainsString('ResponseTerminateException', $src);
        self::assertStringContainsString('mimeForExtension', $src);
        self::assertStringNotContainsString("header('Content-Type:image/jpeg')", $src);
        self::assertDoesNotMatchRegularExpression('/header\\(\\s*[\'"]Content-Type:/', $src);
    }

    public function testModuleVersionIs133OrNewer(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        $version = (string)($module['version'] ?? '');
        self::assertTrue(version_compare($version, '1.3.4', '>='), 'expected MediaManager >= 1.3.4, got ' . $version);
    }
}
