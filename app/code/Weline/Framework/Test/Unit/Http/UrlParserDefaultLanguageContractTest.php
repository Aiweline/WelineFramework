<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Http\Url;

/**
 * Bare-path Url::parser must follow website.default_language, not associated-list[0].
 */
final class UrlParserDefaultLanguageContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Url::$parserServer = [];
        Url::resetWebsiteParserSites();
        Url::resetParserRequestCaches();
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
    }

    protected function tearDown(): void
    {
        Url::$parserServer = [];
        Url::resetWebsiteParserSites();
        Url::resetParserRequestCaches();
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
        parent::tearDown();
    }

    public function test_parser_source_avoids_premature_state_lang_seed(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Http/Url.php'
        );

        self::assertStringContainsString(
            '// Do not seed USER_LANG/CURRENCY from State before website match.',
            $source
        );
        self::assertStringContainsString(
            "self::\$parserServer['WELINE_USER_LANG'] = '';",
            $source
        );
        self::assertStringContainsString(
            '// Prefer website defaults for bare-path; path locale detection overwrites later.',
            $source
        );

        self::assertDoesNotMatchRegularExpression(
            "/WELINE_AREA' = 'frontend';\\s*\\n\\s*self::\\$parserServer\['WELINE_USER_CURRENCY'\] = State::getCurrency\(\);\\s*\\n\\s*self::\\$parserServer\['WELINE_USER_LANG'\] = State::getLang\(\);/",
            $source
        );
    }

    public function test_website_language_codes_source_prefers_default_language(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Websites/Model/WebsiteLanguage.php'
        );

        self::assertStringContainsString(
            'Keep website.default_language first',
            $source
        );
        self::assertStringContainsString(
            'getDefaultLanguage()',
            $source
        );
    }
}
