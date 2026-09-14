<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class MaintenanceStaticLocaleCopyContractTest extends TestCase
{
    /** @var list<string> */
    private const PUBLIC_SOURCES = [
        '系统升级维护中',
        '网站正在维护中...',
        '请稍等片刻',
        '正在尝试现场抢修，恢复后会自动进入，请耐心等待',
        '为什么网站会处于维护模式？',
        '多久能够恢复？',
        '需要帮助吗？',
        '返回首页',
        '刷新当前页面',
    ];

    /** @var list<string> */
    private const TARGET_LOCALES = [
        'bn_BD',
        'hi_IN',
        'ar_SA',
        'es_ES',
        'fr_FR',
        'id_ID',
        'pt_BR',
        'ur_PK',
        'de_DE',
        'ja_JP',
        'ko_KR',
        'th_TH',
        'vi_VN',
        'ru_RU',
        'zh_Hant_TW',
    ];

    public function testGeneratorDoesNotFallbackToChineseCsvForNonChineseLocales(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/MaintenanceStaticGenerator.php',
        );

        self::assertStringContainsString('function isUsableTranslation', $source);
        self::assertStringContainsString('function readModuleCsv', $source);
        self::assertStringNotContainsString(
            "i18nFile = BP . 'app/code/Weline/Maintenance/i18n/' . self::DEFAULT_LANG . '.csv'",
            $source,
        );
        self::assertStringNotContainsString('loadModuleTranslations', $source);
    }

    public function testPublicSurfaceCatalogsAreNotChineseIdentity(): void
    {
        $root = dirname(__DIR__, 3) . '/i18n';
        foreach (self::TARGET_LOCALES as $locale) {
            $path = $root . '/' . $locale . '.csv';
            self::assertFileExists($path, "Missing Maintenance {$locale}.csv");
            $catalog = $this->loadCatalog($path);
            foreach (self::PUBLIC_SOURCES as $source) {
                self::assertArrayHasKey($source, $catalog, "Missing {$locale}: {$source}");
                $translated = trim($catalog[$source]);
                self::assertNotSame('', $translated, "Empty {$locale}: {$source}");
                // Non-Chinese locales must not keep the Simplified Chinese source as identity.
                // Japanese/Korean/Traditional may still use Han characters legitimately.
                if (!str_starts_with($locale, 'zh')) {
                    self::assertNotSame($source, $translated, "Untranslated {$locale}: {$source}");
                }
            }
        }
    }

    public function testBengaliPublicSurfaceUsesBengaliScript(): void
    {
        $catalog = $this->loadCatalog(dirname(__DIR__, 3) . '/i18n/bn_BD.csv');
        self::assertSame('সিস্টেম আপগ্রেড চলছে', $catalog['系统升级维护中'] ?? '');
        self::assertMatchesRegularExpression('/\\p{Bengali}/u', $catalog['系统升级维护中']);
        self::assertMatchesRegularExpression('/\\p{Bengali}/u', $catalog['返回首页'] ?? '');
        self::assertMatchesRegularExpression('/\\p{Bengali}/u', $catalog['刷新当前页面'] ?? '');
    }

    /** @return array<string, string> */
    private function loadCatalog(string $path): array
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle, "Unable to open {$path}");
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $catalog = [];
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $source = trim((string)($row[0] ?? ''));
            if ($source === '') {
                continue;
            }
            $catalog[$source] = (string)($row[1] ?? '');
        }
        fclose($handle);

        return $catalog;
    }
}
