<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service\Preload;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\Preload\CurrencyCatalogPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\RuntimeInterface;

/**
 * Contract for the module-owned currency warm-up.
 *
 * The provider exists only to move a global fact (active currency rows per
 * locale) into process memory before Worker READY. If it ever starts running on
 * the control plane or on FPM it would warm a process that serves no storefront
 * request, so the enablement matrix is asserted rather than assumed.
 */
final class CurrencyCatalogPreloadProviderTest extends TestCase
{
    public function testCodeAndInvalidationAreStable(): void
    {
        $provider = new CurrencyCatalogPreloadProvider();

        self::assertSame('currency_catalog', $provider->code());
        self::assertSame(['currency'], $provider->invalidationKeys());
    }

    public function testRunsAfterTheWebsiteCatalogue(): void
    {
        $provider = new CurrencyCatalogPreloadProvider();

        // The locales to warm come from each site's default_language, which the
        // website directory provider publishes.
        self::assertContains('website_directory', $provider->dependsOn());
    }

    public function testEnabledOnServingWlsWorkersOnly(): void
    {
        $provider = new CurrencyCatalogPreloadProvider();

        self::assertTrue(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_WLS, 'worker')),
            'A serving WLS worker must warm the catalogue',
        );
        self::assertFalse(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_WLS, 'dispatcher')),
            'Control-plane roles must not warm storefront currency rows',
        );
        self::assertFalse(
            $provider->isEnabled($this->context(RuntimeInterface::MODE_FPM, 'worker')),
            'FPM has no long-lived process to warm',
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
