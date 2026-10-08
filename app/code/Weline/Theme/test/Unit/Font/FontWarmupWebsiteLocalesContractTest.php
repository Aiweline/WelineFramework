<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Font;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Font\FontSubsetService;
use Weline\Theme\Font\FontWarmupService;
use Weline\Theme\Font\LanguageCharsetResolver;

final class FontWarmupWebsiteLocalesContractTest extends TestCase
{
    private string $fixtureFont;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->fixtureFont = dirname(__DIR__, 3) . '/Font/test/fixtures/ahem.ttf';
        self::assertFileExists($this->fixtureFont);

        $this->cacheDir = sys_get_temp_dir() . '/weline-font-warmup-web-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
    }

    public function testCollectWebsiteLanguageCodesUsesWebsiteDataSnapshot(): void
    {
        $path = dirname(__DIR__, 3) . '/Font/FontWarmupService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $src);
        self::assertStringNotContainsString(
            'WebsiteLanguage::schema_fields_LANGUAGE_CODE',
            $src,
            'Font warmup must not full-table select WebsiteLanguage.',
        );
        self::assertStringNotContainsString(
            '->select()' . "\n" . '                    ->fetch()',
            $src,
        );
    }

    public function testDefaultLanguagesUnionsCharsetFilesWithWebsiteLocales(): void
    {
        $subset = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);
        $warmup = new class ($subset) extends FontWarmupService {
            public function __construct(FontSubsetService $subsetService)
            {
                parent::__construct($subsetService, null);
            }

            public function collectWebsiteLanguageCodes(): array
            {
                return ['bg_BG', 'en_US'];
            }
        };

        $langs = $warmup->defaultLanguages();
        self::assertContains('en', $langs);
        self::assertContains('bg_BG', $langs);
        self::assertContains('en_US', $langs);
    }

    public function testWarmupOverrideLanguagesBuildsOnlyRequestedLocales(): void
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
                        ['path' => $this->fontPath, 'languages' => []],
                    ],
                    'languages' => ['en', 'ja'],
                ];
            }
        };

        $result = $warmup->warmup(['bg_BG']);
        self::assertSame(1, $result['built']);
        self::assertSame(0, $result['failed']);
        self::assertSame('bg_BG', $result['items'][0]['lang'] ?? '');
        self::assertTrue($subset->hasLangSubset($this->fixtureFont, 'bg_BG'));
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
