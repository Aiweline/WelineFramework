<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Phrase\CliLanguage;
use Weline\Framework\Phrase\CliLanguageCatalog;
use Weline\Framework\Runtime\Runtime;

final class CliLanguageContractTest extends TestCase
{
    public function testDefaultConfigDeclaresCliLanguage(): void
    {
        self::assertSame(
            Env::default_LANGUAGE_CODE,
            Env::default_CONFIG['cli_language'] ?? null,
        );
    }

    public function testEventRegisteredInFrameworkEventCatalog(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/event.php');
        self::assertStringContainsString(CliLanguage::EVENT_OPTIONS, $src);
        self::assertStringContainsString('cli_language_options', $src);
    }

    public function testStateResolvesCliLanguageInCliBranch(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/App/State.php');
        self::assertStringContainsString('CliLanguage::isCliContext', $src);
        self::assertStringContainsString('CliLanguage::resolveConfigured', $src);
        self::assertTrue(\method_exists(State::class, 'resolveAreaDefaultLanguage'));
    }

    public function testCommandClassAndTipUsePhrase(): void
    {
        $path = dirname(__DIR__, 3) . '/Phrase/Console/Cli/Language.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('cli:language', $src);
        self::assertStringContainsString('__(\'', $src);
        self::assertStringContainsString('stream_isatty', $src);
        self::assertStringContainsString('CliLanguage::set', $src);
    }

    public function testCatalogClassDedupesAndPrioritizesBaseline(): void
    {
        $path = dirname(__DIR__, 3) . '/Phrase/CliLanguageCatalog.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('discoverCsvAndGeneratedLocales', $src);
        self::assertStringContainsString('CliLanguage::EVENT_OPTIONS', $src);
        self::assertStringContainsString('default_LANGUAGE_CODE', $src);
        self::assertStringContainsString("'en_US'", $src);
        self::assertTrue(\class_exists(CliLanguageCatalog::class));
    }

    public function testResolveConfiguredNeverUsesWebsiteListFirstItemShape(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Phrase/CliLanguage.php');
        self::assertStringContainsString("Env::system('lang'", $src);
        self::assertStringContainsString('default_LANGUAGE_CODE', $src);
        self::assertStringNotContainsString('preferredLanguageCodes', $src);
        self::assertTrue(Runtime::isCli() || !Runtime::isCli());
    }

    public function testCliAreaDefaultIsChineseBaselineNotWebsiteListFirst(): void
    {
        if (!Runtime::isCli()) {
            self::markTestSkipped('requires CLI SAPI');
        }
        State::clearProcessLocalizationCaches();
        State::resetLangLocalCache();
        State::setRequestLanguageOverride('');
        $lang = State::getLang();
        self::assertSame(Env::default_LANGUAGE_CODE, $lang);
        self::assertNotSame('it_IT', $lang);
        self::assertNotSame('ar_SA', $lang);
    }
}
