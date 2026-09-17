<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\StaticErrorPageMap;
use Weline\Framework\Php\FiberTaskRunner;

final class StaticErrorPageMapTest extends TestCase
{
    public function testSanitizeWebsiteCodeRejectsTraversal(): void
    {
        self::assertSame('shop', StaticErrorPageMap::sanitizeWebsiteCode('shop'));
        self::assertSame('shop_a', StaticErrorPageMap::sanitizeWebsiteCode('shop_a'));
        self::assertSame('', StaticErrorPageMap::sanitizeWebsiteCode('../etc'));
        self::assertSame('', StaticErrorPageMap::sanitizeWebsiteCode('a/b'));
        self::assertSame('', StaticErrorPageMap::sanitizeWebsiteCode(''));
    }

    public function testSameHostDifferentSubPathResolvesDistinctCodes(): void
    {
        $map = [
            'shop.example.com' => 'shop',
            'shop.example.com|/store-a' => 'shop_a',
            'shop.example.com|/store-a/nested' => 'shop_nested',
        ];

        self::assertSame(
            'shop_a',
            StaticErrorPageMap::resolveWebsiteCodeFromMap($map, 'shop.example.com', '/store-a/products')
        );
        self::assertSame(
            'shop_nested',
            StaticErrorPageMap::resolveWebsiteCodeFromMap($map, 'SHOP.EXAMPLE.COM', '/store-a/nested/x')
        );
        self::assertSame(
            'shop',
            StaticErrorPageMap::resolveWebsiteCodeFromMap($map, 'shop.example.com', '/')
        );
    }

    public function testLoadWithFallbackPrefersWebsiteThenDefaultThenFlat(): void
    {
        if (!\defined('BP') || !\defined('PUB')) {
            self::markTestSkipped('BP/PUB not defined');
        }

        $kind = 'maintenance';
        $dir = StaticErrorPageMap::errorsBaseDir($kind);
        $websiteDir = $dir . \DIRECTORY_SEPARATOR . 'shop_fb';
        $defaultDir = $dir . \DIRECTORY_SEPARATOR . 'default';
        @\mkdir($websiteDir, 0755, true);
        @\mkdir($defaultDir, 0755, true);

        // Use a unique locale so langFallbackChain cannot hit real zh_Hans_CN/en_US snapshots.
        $lang = 'zz_ZZ';
        $flat = $dir . \DIRECTORY_SEPARATOR . $lang . '.html';
        $defaultFile = $defaultDir . \DIRECTORY_SEPARATOR . $lang . '.html';
        $websiteFile = $websiteDir . \DIRECTORY_SEPARATOR . $lang . '.html';

        // Hide default website's common-locale fallbacks for the duration of this assertion.
        $hidden = [];
        foreach (['zh_Hans_CN.html', 'en_US.html'] as $noise) {
            $noisePath = $defaultDir . \DIRECTORY_SEPARATOR . $noise;
            if (\is_file($noisePath)) {
                $tmp = $noisePath . '.bak_test';
                @\rename($noisePath, $tmp);
                $hidden[] = [$tmp, $noisePath];
            }
            $flatNoise = $dir . \DIRECTORY_SEPARATOR . $noise;
            if (\is_file($flatNoise)) {
                $tmp = $flatNoise . '.bak_test';
                @\rename($flatNoise, $tmp);
                $hidden[] = [$tmp, $flatNoise];
            }
        }

        @\file_put_contents($flat, 'FLAT');
        @\file_put_contents($defaultFile, 'DEFAULT');
        @\file_put_contents($websiteFile, 'WEBSITE');

        StaticErrorPageMap::writeHostMapAtomic($dir, [
            'fb.example.com' => 'shop_fb',
        ]);

        try {
            self::assertSame(
                'WEBSITE',
                StaticErrorPageMap::loadWithFallback($kind, $lang, 'fb.example.com', '/')
            );
            @\unlink($websiteFile);
            self::assertSame(
                'DEFAULT',
                StaticErrorPageMap::loadWithFallback($kind, $lang, 'fb.example.com', '/')
            );
            @\unlink($defaultFile);
            self::assertSame(
                'FLAT',
                StaticErrorPageMap::loadWithFallback($kind, $lang, 'fb.example.com', '/')
            );
        } finally {
            @\unlink($websiteFile);
            @\unlink($defaultFile);
            @\unlink($flat);
            @\unlink($dir . \DIRECTORY_SEPARATOR . StaticErrorPageMap::HOST_MAP_FILENAME);
            @\rmdir($websiteDir);
            foreach ($hidden as [$tmp, $orig]) {
                if (\is_file($tmp)) {
                    @\rename($tmp, $orig);
                }
            }
        }
    }

    public function testHostMapAtomicWriteAndFiberTasksDoNotCrossMarkers(): void
    {
        if (!\defined('BP') || !\defined('PUB')) {
            self::markTestSkipped('BP/PUB not defined');
        }

        $kind = 'storefront-not-found';
        $dir = StaticErrorPageMap::errorsBaseDir($kind);
        @\mkdir($dir, 0755, true);

        $markers = [];
        $runner = new FiberTaskRunner(4, true);
        $tasks = [];
        foreach (['alpha' => 'MARK-A', 'beta' => 'MARK-B'] as $code => $marker) {
            $tasks[$code] = static function () use ($code, $marker, $kind, &$markers): string {
                FiberTaskRunner::yield();
                $path = StaticErrorPageMap::websiteLocaleFile($kind, $code, 'en_US');
                $dirName = \dirname($path);
                if (!\is_dir($dirName)) {
                    @\mkdir($dirName, 0755, true);
                }
                @\file_put_contents($path, $marker);
                $markers[$code] = (string)@\file_get_contents($path);
                FiberTaskRunner::yield();

                return $marker;
            };
        }

        $results = $runner->run($tasks, 4);
        self::assertSame('MARK-A', $results['alpha']);
        self::assertSame('MARK-B', $results['beta']);
        self::assertSame('MARK-A', $markers['alpha']);
        self::assertSame('MARK-B', $markers['beta']);

        $map = [
            'dual.example.com' => 'alpha',
            'dual.example.com|/b' => 'beta',
        ];
        self::assertTrue(StaticErrorPageMap::writeHostMapAtomic($dir, $map));
        $loaded = StaticErrorPageMap::loadHostMap($dir);
        self::assertSame('alpha', $loaded['dual.example.com']);
        self::assertSame('beta', $loaded['dual.example.com|/b']);

        @\unlink(StaticErrorPageMap::websiteLocaleFile($kind, 'alpha', 'en_US'));
        @\unlink(StaticErrorPageMap::websiteLocaleFile($kind, 'beta', 'en_US'));
        @\rmdir($dir . \DIRECTORY_SEPARATOR . 'alpha');
        @\rmdir($dir . \DIRECTORY_SEPARATOR . 'beta');
        @\unlink($dir . \DIRECTORY_SEPARATOR . StaticErrorPageMap::HOST_MAP_FILENAME);
    }
}
