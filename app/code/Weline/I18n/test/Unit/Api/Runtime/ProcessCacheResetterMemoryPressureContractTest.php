<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\I18n\Api\Runtime\ProcessCacheResetter;
use Weline\I18n\Taglib\LanguageSelect;

final class ProcessCacheResetterMemoryPressureContractTest extends TestCase
{
    public function testSoftMemoryPressureClearsChromeBagsButNotFullPhraseWipe(): void
    {
        LanguageSelect::clearProcessCaches();
        $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
        $prop->setAccessible(true);
        $prop->setValue(null, ['zh_Hans_CN' => ['CN' => '中国']]);

        $resetter = new ProcessCacheResetter();
        $cleared = $resetter->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false)
        );

        // Soft: LanguageSwitcher + LanguageSelect chrome only (2); Phrase cold locales via Guard.
        self::assertSame(2, $cleared);
        self::assertSame([], $prop->getValue());

        $src = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/ProcessCacheResetter.php'
        );
        self::assertStringContainsString('isSoftContent()', $src);
        self::assertMatchesRegularExpression(
            '/isSoftContent\(\)[\s\S]*?LanguageSwitcher::clearProcessCaches\(\)[\s\S]*?return 2;/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/isSoftContent\(\)[\s\S]*?Parser::clearWorkerCaches\(\)[\s\S]*?return 2;/',
            $src,
        );
        LanguageSelect::clearProcessCaches();
    }

    public function testAggressiveMemoryPressureClearsLanguageSelectProcessCaches(): void
    {
        LanguageSelect::clearProcessCaches();
        $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
        $prop->setAccessible(true);
        $prop->setValue(null, ['zh_Hans_CN' => ['CN' => '中国']]);

        $resetter = new ProcessCacheResetter();
        $cleared = $resetter->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true)
        );

        self::assertGreaterThan(0, $cleared);
        self::assertSame([], $prop->getValue());
    }

    public function testSoftUnknownReasonDoesNotClearLanguageCaches(): void
    {
        $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
        $prop->setAccessible(true);
        $prop->setValue(null, ['en' => ['US' => 'United States']]);

        $resetter = new ProcessCacheResetter();
        $cleared = $resetter->resetProcessCaches(
            new ProcessCacheResetContext('noop_soft', false)
        );

        self::assertSame(0, $cleared);
        self::assertSame(['en' => ['US' => 'United States']], $prop->getValue());
        LanguageSelect::clearProcessCaches();
    }
}
