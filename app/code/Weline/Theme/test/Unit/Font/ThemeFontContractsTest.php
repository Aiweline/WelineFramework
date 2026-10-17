<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Font;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Font\FontSubsetService;
use Weline\Theme\Font\FontWarmupService;
use Weline\Theme\Font\LanguageCharsetResolver;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeFontContractsPass().
 */
final class ThemeFontContractsTest extends TestCase
{
    public function testAllThemeFontContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'FontWarmupWebsiteLocales::testCollectWebsiteLanguageCodesUsesWebsiteDataSnapshot' => function () {
            $this->setUp_FontWarmupWebsiteLocales();
            try {
                $this->assert_FontWarmupWebsiteLocales_testCollectWebsiteLanguageCodesUsesWebsiteDataSnapshot();
            } finally {
                $this->tearDown_FontWarmupWebsiteLocales();
            }
        },
            'FontWarmupWebsiteLocales::testDefaultLanguagesUnionsCharsetFilesWithWebsiteLocales' => function () {
            $this->setUp_FontWarmupWebsiteLocales();
            try {
                $this->assert_FontWarmupWebsiteLocales_testDefaultLanguagesUnionsCharsetFilesWithWebsiteLocales();
            } finally {
                $this->tearDown_FontWarmupWebsiteLocales();
            }
        },
            'FontWarmupWebsiteLocales::testWarmupOverrideLanguagesBuildsOnlyRequestedLocales' => function () {
            $this->setUp_FontWarmupWebsiteLocales();
            try {
                $this->assert_FontWarmupWebsiteLocales_testWarmupOverrideLanguagesBuildsOnlyRequestedLocales();
            } finally {
                $this->tearDown_FontWarmupWebsiteLocales();
            }
        },
        ];
    }

private string $fixtureFont_FontWarmupWebsiteLocales;

private string $cacheDir_FontWarmupWebsiteLocales;

private function setUp_FontWarmupWebsiteLocales(): void
{
    $this->fixtureFont_FontWarmupWebsiteLocales = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Font/test/fixtures/ahem.ttf';
    self::assertFileExists($this->fixtureFont_FontWarmupWebsiteLocales);
    $this->cacheDir_FontWarmupWebsiteLocales = sys_get_temp_dir() . '/weline-font-warmup-web-' . bin2hex(random_bytes(4));
    mkdir($this->cacheDir_FontWarmupWebsiteLocales, 0775, true);
}

private function tearDown_FontWarmupWebsiteLocales(): void
{
    $this->removeTree_FontWarmupWebsiteLocales($this->cacheDir_FontWarmupWebsiteLocales);
}

private function assert_FontWarmupWebsiteLocales_testCollectWebsiteLanguageCodesUsesWebsiteDataSnapshot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Font/FontWarmupService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $src);
    self::assertStringNotContainsString('WebsiteLanguage::schema_fields_LANGUAGE_CODE', $src, 'Font warmup must not full-table select WebsiteLanguage.');
    self::assertStringNotContainsString('->select()' . "\n" . '                    ->fetch()', $src);
}

private function assert_FontWarmupWebsiteLocales_testDefaultLanguagesUnionsCharsetFilesWithWebsiteLocales(): void
{
    $subset = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir_FontWarmupWebsiteLocales);
    $warmup = new class($subset) extends FontWarmupService
    {
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

private function assert_FontWarmupWebsiteLocales_testWarmupOverrideLanguagesBuildsOnlyRequestedLocales(): void
{
    $subset = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir_FontWarmupWebsiteLocales);
    $warmup = new class($subset, $this->fixtureFont_FontWarmupWebsiteLocales) extends FontWarmupService
    {
        public function __construct(FontSubsetService $subsetService, private string $fontPath)
        {
            parent::__construct($subsetService, null);
        }
        public function collect(): array
        {
            return ['fonts' => [['path' => $this->fontPath, 'languages' => []]], 'languages' => ['en', 'ja']];
        }
    };
    $result = $warmup->warmup(['bg_BG']);
    self::assertSame(1, $result['built']);
    self::assertSame(0, $result['failed']);
    self::assertSame('bg_BG', $result['items'][0]['lang'] ?? '');
    self::assertTrue($subset->hasLangSubset($this->fixtureFont_FontWarmupWebsiteLocales, 'bg_BG'));
}

private function removeTree_FontWarmupWebsiteLocales(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
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
