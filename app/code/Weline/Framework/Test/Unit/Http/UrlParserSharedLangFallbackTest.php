<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;

/**
 * Regression: Url::parser site-match branches must not backfill
 * $parserServer['WELINE_USER_LANG'] from WelineEnv::getLang() when the
 * site default is empty and the shared static is empty.
 *
 * The old fallback pulled a peer Fiber's language override (e.g. a
 * suspended maintenance-publish locale like uk_UA) into process-shared
 * statics, so unrelated requests rendered the wrong locale.
 */
final class UrlParserSharedLangFallbackTest extends TestCore
{
    private string $previousOverride = '';

    protected function setUp(): void
    {
        parent::setUp();
        \class_exists(Url::class);
        Url::resetParserRequestCaches();
        $this->previousOverride = State::getRequestLanguageOverride();
        State::setRequestLanguageOverride('');
    }

    protected function tearDown(): void
    {
        State::setRequestLanguageOverride($this->previousOverride);
        Url::resetParserRequestCaches();
        RequestContext::cleanup();
        parent::tearDown();
    }

    /**
     * Simulates the leak: a leftover language override in the current
     * context (as if written by an abandoned publish Fiber's mainContext)
     * must NOT be copied into the shared parserServer locale slot by
     * Url::parser site matching.
     */
    public function testParserDoesNotBackfillSharedLangFromEnvOverride(): void
    {
        // Leftover override from an abandoned maintenance-publish Fiber.
        State::setRequestLanguageOverride('uk_UA');

        // Shared statics start with an empty locale slot (fresh request).
        self::assertSame(
            '',
            (string)(Url::$parserServer['WELINE_USER_LANG'] ?? ''),
            'precondition: fresh parserServer has empty WELINE_USER_LANG'
        );

        // Direct read of the previously-falling-back expression must be gone.
        $src = \file_get_contents((new \ReflectionClass(Url::class))->getFileName());
        self::assertStringNotContainsString(
            "self::\$parserServer['WELINE_USER_LANG'] = WelineEnv::getLang();",
            (string)$src,
            'shared-state lang fallback must not exist in Url.php'
        );

        // Env still exposes the override (that is its contract), but the
        // parser static must stay empty until synchronizeParsedLocalization
        // fills the authoritative website default.
        self::assertSame('uk_UA', \Weline\Framework\Env\WelineEnv::getLang());
        self::assertSame(
            '',
            (string)(Url::$parserServer['WELINE_USER_LANG'] ?? ''),
            'parserServer must not be backfilled from WelineEnv::getLang()'
        );
    }

    public function testParserStaticLangWriteOnlyFromSiteDefault(): void
    {
        // With no override and empty static, only an explicit site default
        // write may populate the slot; ensure empty write path stays empty.
        State::setRequestLanguageOverride('');
        self::assertSame('', (string)(Url::$parserServer['WELINE_USER_LANG'] ?? ''));
        self::assertSame('zh_Hans_CN', \Weline\Framework\Env\WelineEnv::getLang());
        self::assertSame('', (string)(Url::$parserServer['WELINE_USER_LANG'] ?? ''));
    }
}
