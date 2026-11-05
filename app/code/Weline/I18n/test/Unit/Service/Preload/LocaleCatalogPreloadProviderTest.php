<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\Preload;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\I18n\Service\Preload\LocaleCatalogPreloadProvider;

/**
 * Contract for the module-owned locale-catalogue warm-up.
 *
 * The provider exists to keep the "which language segments are legal" answer off
 * the request path: a cold Worker previously paid a full `w_i18n_locals` select
 * while parsing the first URL. Two properties must hold or the warm-up is either
 * pointless (wrong process) or wrong (a key requests never read):
 *
 *  1. it runs only on serving WLS workers, after the website/currency catalogues;
 *  2. the process-bag key it writes at boot is the same key a request reads.
 */
final class LocaleCatalogPreloadProviderTest extends TestCase
{
    public function testCodeAndInvalidationAreStable(): void
    {
        $provider = new LocaleCatalogPreloadProvider();

        self::assertSame('i18n_locale_catalog', $provider->code());
        self::assertSame(['i18n', 'locale_catalog'], $provider->invalidationKeys());
    }

    public function testRunsAfterWebsiteAndCurrencyCatalogues(): void
    {
        $provider = new LocaleCatalogPreloadProvider();

        // Providers answering "which locales does this site expose" read the
        // website directory; localized currency names are keyed by site language.
        self::assertContains('website_directory', $provider->dependsOn());
        self::assertContains('currency_catalog', $provider->dependsOn());
        self::assertGreaterThan(62, $provider->priority(), 'must order after currency_catalog');
    }

    public function testEnabledOnServingWlsWorkersOnly(): void
    {
        $provider = new LocaleCatalogPreloadProvider();

        self::assertTrue(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_WLS, 'worker')),
            'A serving WLS worker must warm the locale catalogue',
        );
        self::assertFalse(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_WLS, 'dispatcher')),
            'Control-plane roles must not warm storefront catalogues',
        );
        self::assertFalse(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_FPM, 'worker')),
            'FPM has no long-lived process to warm',
        );
    }

    /**
     * Boot has no RequestContext, a request has one. The two must produce the same
     * process-bag key, otherwise the preload writes a bag nothing ever reads.
     */
    public function testProcessScopeKeyIsContextIndependent(): void
    {
        $bootKey = DictionaryCacheNamespace::processScopeKey('installed-active-locales');

        self::assertNotSame('', $bootKey, 'generation stamp must be readable at boot');
        self::assertSame(
            $bootKey,
            DictionaryCacheNamespace::processScopeKey('installed-active-locales'),
            'repeated reads must not change the key',
        );
        self::assertNotSame(
            $bootKey,
            DictionaryCacheNamespace::processScopeKey('installed-locales'),
            'distinct catalogues must not share a bag entry',
        );
    }

    private function context(string $runtimeMode, string $role): WorkerPreloadContext
    {
        return new WorkerPreloadContext(
            WorkerPreloadContext::PHASE_BOOTSTRAP,
            $role,
            1,
            'default',
            12345,
            $runtimeMode,
        );
    }
}
