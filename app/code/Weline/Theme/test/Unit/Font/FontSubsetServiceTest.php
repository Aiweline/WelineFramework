<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Font;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Font\FontSubsetService;
use Weline\Theme\Font\FontWarmupService;
use Weline\Theme\Font\LanguageCharsetResolver;

final class FontSubsetServiceTest extends TestCase
{
    private string $fixtureFont;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->fixtureFont = dirname(__DIR__, 3) . '/Font/test/fixtures/ahem.ttf';
        self::assertFileExists($this->fixtureFont);

        $this->cacheDir = sys_get_temp_dir() . '/weline-font-subset-test-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
    }

    public function testLanguageCharsetResolverPrefersSpecificCharsetFile(): void
    {
        $resolver = new LanguageCharsetResolver();
        $chars = $resolver->resolve('zh_Hans_CN');
        self::assertStringContainsString('的', $chars);
        self::assertStringContainsString('A', $chars);
        self::assertSame(['zh_Hans_CN', 'zh_Hans', 'zh'], $resolver->candidates('zh_Hans_CN'));
    }

    public function testFontLoadParsesAhem(): void
    {
        $font = \Weline\Theme\Font\Font::load($this->fixtureFont);
        self::assertNotNull($font);
        $font->parse();
        self::assertNotSame('', (string)$font->getFontName());
        $font->close();
    }

    public function testSubsetCreatesCacheAndReusesIt(): void
    {
        $service = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);

        $first = $service->getSubsetPath($this->fixtureFont, 'en', 'XYZ');
        self::assertFileExists($first);
        self::assertGreaterThan(0, filesize($first));
        self::assertStringContainsString('.en.', basename($first));

        $mtime = filemtime($first);
        clearstatcache(true, $first);

        $second = $service->getSubsetPath($this->fixtureFont, 'en', 'XYZ');
        self::assertSame($first, $second);
        self::assertSame($mtime, filemtime($second));

        $otherLang = $service->getSubsetPath($this->fixtureFont, 'ja');
        self::assertNotSame($first, $otherLang);
        self::assertFileExists($otherLang);
        self::assertStringContainsString('.ja.', basename($otherLang));
    }

    public function testEnsureLangSubsetSkipsWhenCacheExists(): void
    {
        $service = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);

        $built = $service->ensureLangSubset($this->fixtureFont, 'en');
        self::assertFalse($built['skipped']);
        self::assertTrue($built['built']);
        self::assertFileExists($built['path']);

        $again = $service->ensureLangSubset($this->fixtureFont, 'en');
        self::assertTrue($again['skipped']);
        self::assertFalse($again['built']);
        self::assertSame($built['path'], $again['path']);
        self::assertTrue($service->hasLangSubset($this->fixtureFont, 'en'));
    }

    public function testExtractCharsCachesByCharsetFingerprint(): void
    {
        $service = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);

        $chars = 'Hello子集测试';
        $first = $service->extractChars($this->fixtureFont, $chars);
        self::assertFileExists($first);
        self::assertStringContainsString('.chars.', basename($first));

        $ensured = $service->ensureCharsSubset($this->fixtureFont, $chars);
        self::assertTrue($ensured['skipped']);
        self::assertSame($first, $ensured['path']);

        $other = $service->extractChars($this->fixtureFont, 'DifferentChars');
        self::assertNotSame($first, $other);
        self::assertFileExists($other);
    }

    public function testWarmupSkipsExistingLanguageSubsets(): void
    {
        $subset = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);
        $warmup = new class ($subset, $this->fixtureFont) extends FontWarmupService {
            public function __construct(
                FontSubsetService $subsetService,
                private string $fontPath
            ) {
                parent::__construct($subsetService, null);
            }

            public function collect(): array
            {
                return [
                    'fonts' => [
                        ['path' => $this->fontPath, 'languages' => ['en', 'ja']],
                    ],
                    'languages' => ['en', 'ja'],
                ];
            }
        };

        $first = $warmup->warmup();
        self::assertSame(2, $first['built']);
        self::assertSame(0, $first['skipped']);
        self::assertSame(0, $first['failed']);

        $second = $warmup->warmup();
        self::assertSame(0, $second['built']);
        self::assertSame(2, $second['skipped']);
        self::assertSame(0, $second['failed']);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
