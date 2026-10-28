<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Service\ResponsiveImageService;

final class ResponsiveImageServiceTest extends TestCase
{
    private static string $mediaDir = '';
    private static string $relative = 'weline-test/hero.jpg';

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
        $image = imagecreatetruecolor(1200, 600);
        $fill = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, 1199, 599, $fill);
        imagejpeg($image, self::$mediaDir . '/' . self::$relative, 80);
        imagedestroy($image);

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

    public function testNaturalWidthIsEmittedAndNoUpscaleHappens(): void
    {
        $srcset = (new ResponsiveImageService())->srcset('/media/image/' . self::$relative);

        self::assertStringContainsString(' 320w', $srcset);
        self::assertStringContainsString(' 1200w', $srcset);
        self::assertStringNotContainsString(' 1280w', $srcset);
        self::assertStringNotContainsString(' 1600w', $srcset);
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

    public function testEveryCandidateCarriesBothWidthAndHeight(): void
    {
        $relative = self::$relative;
        $srcset = (new ResponsiveImageService())->srcset('/media/image/' . $relative);

        self::assertNotSame('', $srcset);
        $candidates = array_map('trim', explode(',', $srcset));
        self::assertLessThanOrEqual(5, count($candidates));
        foreach ($candidates as $candidate) {
            self::assertMatchesRegularExpression(
                '#^/media/image/weline-test/hero\.jpg\?w=\d+&h=\d+ \d+w$#',
                $candidate
            );
            self::assertMatchesRegularExpression('/[?&]w=\d+/', $candidate);
            self::assertMatchesRegularExpression('/[?&]h=[1-9]\d*/', $candidate);
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
