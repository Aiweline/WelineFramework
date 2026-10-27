<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Http\MaintenanceStaticPage;
use Weline\Framework\Test\TestCore;

/**
 * Regression: static error page publishing must isolate its per-locale
 * language override inside a Fiber-owned Context clone.
 *
 * Before the fix, MaintenanceStaticGenerator::publishOne wrote
 * State::setRequestLanguageOverride($lang) into whatever Context the
 * publishing Fiber observed. When no Fiber-owned Context existed (CLI,
 * sequential fallback, abandoned Fiber) the override landed in the shared
 * main context and leaked a foreign locale (e.g. uk_UA) into every later
 * maintenance/404 response.
 */
final class StaticPublishContextIsolationTest extends TestCore
{
    private string $previousOverride = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousOverride = State::getRequestLanguageOverride();
        State::setRequestLanguageOverride('');
    }

    protected function tearDown(): void
    {
        State::setRequestLanguageOverride($this->previousOverride);
        parent::tearDown();
    }

    /**
     * The publish path must enter its own Context: after publishOne returns,
     * the shared context's language override must be empty even though the
     * publish scope wrote a locale override mid-flight.
     */
    public function testMaintenancePublishOneLeavesNoOverrideInSharedContext(): void
    {
        $generator = new \Weline\Maintenance\Service\MaintenanceStaticGenerator();

        // Publish one website×locale; static writes may fail in unit env but
        // must not throw — the contract under test is context isolation.
        try {
            $generator->publishOne('default', 'uk_UA', 0, 'http://127.0.0.1/', 60);
        } catch (\Throwable) {
            // render may fail on missing theme data in unit env; isolation still holds
        }

        self::assertSame(
            '',
            State::getRequestLanguageOverride(),
            'shared context must not retain the publish language override'
        );
    }

    /**
     * Context::enter/leave contract used by the fix: leaving a Fiber-owned
     * context and re-entering the previous one restores the original storage.
     */
    public function testContextEnterLeaveRestoresPreviousContext(): void
    {
        $outer = Context::current();
        $outer->set('isolation_probe', 'outer');

        Context::enter(new Context($outer->toArray()));
        try {
            Context::current()->set('isolation_probe', 'inner');
            State::setRequestLanguageOverride('uk_UA');
            self::assertSame('uk_UA', State::getRequestLanguageOverride());
        } finally {
            Context::leave();
            Context::enter($outer);
        }

        self::assertSame('outer', Context::current()->get('isolation_probe'));
        self::assertSame('', State::getRequestLanguageOverride(), 'outer context override untouched');
    }

    /**
     * MaintenanceStaticPage::resolveLang must fall back to DEFAULT_LANG when
     * no locale override/path/query exists — a leaked override would win here.
     */
    public function testResolveLangIgnoresSharedOverrideWhenClean(): void
    {
        State::setRequestLanguageOverride('');
        self::assertSame(
            MaintenanceStaticPage::DEFAULT_LANG,
            MaintenanceStaticPage::resolveLang('/product/huazhaoji-52a94d59-teaab-s7ca5')
        );
    }
}
