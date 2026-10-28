<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Service\ResponsiveImageService;

final class ResponsiveImageServiceTest extends TestCase
{
    private static string $mediaDir = '';
    private static string $relative = 'weline-test/hero.jpg';
    private static string $plainRelative = 'weline-test/plain.jpg';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD extension is required to build the fixture image.');
        }
        self::$mediaDir = sys_get_temp_dir() . '/weline-responsive-image-' . bin2hex(random_bytes(6));
        $targetDir = self::$mediaDir . '/' . dirname(self::$relative);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            self::fail('Unable to create temp media dir: ' . $targetDir);
        }
        self::makeImage(self::$mediaDir . '/' . self::$relative);
        // Same size, no pre-generated variants: a one-candidate srcset must stay empty.
        self::makeImage(self::$mediaDir . '/' . self::$plainRelative);

        // Pre-generated variants use the Image controller's thumbnail naming.
        foreach ([[320, 160], [640, 320]] as [$width, $height]) {
            self::makeImage(
                self::$mediaDir . '/thumbnail/weline-test/hero_w_' . $width . '_h_' . $height . '_c_o.jpg',
                $width,
                $height
            );
        }

        if (!defined('MEDIA_DIR')) {
            define('MEDIA_DIR', self::$mediaDir);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$mediaDir === '' || !is_dir(self::$mediaDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$mediaDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir(self::$mediaDir);
    }

    private static function makeImage(string $path, int $width = 1200, int $height = 600): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Unable to create temp media dir: ' . $dir);
        }
        $image = imagecreatetruecolor($width, $height);
        $fill = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $fill);
        imagejpeg($image, $path, 80);
        imagedestroy($image);
    }

    public function testOnlyExistingStaticFilesAreEmitted(): void
    {
        $srcset = (new ResponsiveImageService())->srcset('/media/image/' . self::$relative);

        self::assertStringContainsString(
            '/pub/media/thumbnail/weline-test/hero_w_320_h_160_c_o.jpg 320w',
            $srcset
        );
        self::assertStringContainsString(
            '/pub/media/thumbnail/weline-test/hero_w_640_h_320_c_o.jpg 640w',
            $srcset
        );
        self::assertStringContainsString('/pub/media/weline-test/hero.jpg 1200w', $srcset);
        // The on-demand endpoint needs GD inside the worker; never emit it in srcset.
        self::assertStringNotContainsString('/media/image/', $srcset);
        self::assertStringNotContainsString(' 1280w', $srcset);
        self::assertStringNotContainsString(' 1600w', $srcset);
    }

    public function testSingleCandidateIsNotEmitted(): void
    {
        self::assertSame('', (new ResponsiveImageService())->srcset('/media/image/' . self::$plainRelative));
    }

    public function testMissingFileReturnsEmptyString(): void
    {
        self::assertSame('', (new ResponsiveImageService())->srcset('/media/image/weline-test/missing.jpg'));
    }

    public function testExternalHttpUrlReturnsEmptyString(): void
    {
        $service = new ResponsiveImageService();

        self::assertSame('', $service->srcset('https://cdn.example.com/a/b.jpg'));
        self::assertSame('', $service->srcset('http://cdn.example.com/a/b.jpg'));
    }

    public function testSvgReturnsEmptyString(): void
    {
        $service = new ResponsiveImageService();

        self::assertSame('', $service->srcset('/media/image/' . dirname(self::$relative) . '/logo.svg'));
        self::assertSame('', $service->srcset('/media/image/' . dirname(self::$relative) . '/logo.SVG'));
    }

    public function testEveryCandidateIsAStaticPubMediaUrl(): void
    {
        $srcset = (new ResponsiveImageService())->srcset('/media/image/' . self::$relative);

        self::assertNotSame('', $srcset);
        $candidates = array_map('trim', explode(',', $srcset));
        self::assertLessThanOrEqual(5, count($candidates));
        foreach ($candidates as $candidate) {
            self::assertMatchesRegularExpression('#^/pub/media/[^\s?]+ \d+w$#', $candidate);
        }
    }

    public function testAllFourInputSpellingsNormalizeToTheSameSrcset(): void
    {
        $service = new ResponsiveImageService();
        $relative = self::$relative;
        $expected = $service->srcset('/media/image/' . $relative);

        self::assertNotSame('', $expected);
        self::assertSame($expected, $service->srcset('/pub/media/' . $relative));
        self::assertSame($expected, $service->srcset('/media/' . $relative));
        self::assertSame($expected, $service->srcset($relative));
    }

    public function testSizesSlots(): void
    {
        $service = new ResponsiveImageService();

        self::assertSame('100vw', $service->sizesFor('hero'));
        self::assertSame(
            '(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw',
            $service->sizesFor('card')
        );
        self::assertSame('(min-width: 1024px) 720px, 100vw', $service->sizesFor('detail'));
        self::assertSame('', $service->sizesFor('unknown'));
    }
}
