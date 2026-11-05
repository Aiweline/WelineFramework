<?php

declare(strict_types=1);

namespace Weline\Currency\Service\Preload;

use Weline\Currency\Data\CurrencyData;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;

/**
 * Publishes the active currency rows into process memory before Worker READY.
 *
 * Root cause this removes: `CurrencyData::getCurrencies()` is the single read
 * authority for "active currencies", and a cold Worker paid its shared-cache
 * lookup plus ORM + local-description join inside the first request that
 * rendered the header currency switcher (or any other catalogue consumer).
 * The rows are a global fact per locale — never request-private — so the build
 * belongs in worker boot, per the project rule that global data must be in
 * process memory before READY rather than lazily on the first request.
 *
 * Scope: one locale per storefront Website's `default_language`, capped. A
 * currency switcher shows localised names for one locale, and warming every
 * enabled locale would trade startup time and RSS for entries nobody reads.
 *
 * Registered by the module itself:
 *   'worker_preload_provider.Weline_Currency' => this class
 * The framework runtime only schedules providers; it carries no currency code.
 */
final class CurrencyCatalogPreloadProvider extends AbstractWorkerPreloadProvider
{
    /** Control-plane / non-serving roles never serve storefront requests. */
    private const EXCLUDED_ROLES = ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'];

    /** Hard cap on warmed locales: one per storefront site, bounded. */
    private const MAX_WARM_LOCALES = 8;

    public function code(): string
    {
        return 'currency_catalog';
    }

    /**
     * Runs after the website catalogue: the locales to warm come from each
     * site's `default_language`, which that provider publishes.
     */
    public function priority(): int
    {
        return 62;
    }

    public function dependsOn(): array
    {
        return ['website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        if ($context->runtimeMode() !== RuntimeInterface::MODE_WLS) {
            return false;
        }

        return !\in_array(\strtolower(\trim($context->role())), self::EXCLUDED_ROLES, true);
    }

    public function invalidationKeys(): array
    {
        return ['currency'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $warmed = 0;
        $skipped = [];
        foreach ($this->warmLocales() as $locale) {
            if (CurrencyData::warmLocale($locale)) {
                ++$warmed;
                continue;
            }
            $skipped[] = $locale;
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($warmed === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                'no_locale_warmed' . ($skipped === [] ? '' : ':' . \implode(',', $skipped)),
                $durationMs,
                $memoryDelta,
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $warmed,
            $durationMs,
            $memoryDelta,
            ['skipped' => $skipped],
        );
    }

    /**
     * Locales to warm: each storefront site's default language, else the
     * framework default. Never "every enabled locale".
     *
     * @return list<string>
     */
    private function warmLocales(): array
    {
        $locales = [];
        try {
            if (\class_exists(\Weline\Websites\Model\Website::class)) {
                foreach (\Weline\Websites\Model\Website::fetchCatalogRows() as $row) {
                    $locale = \trim((string)($row['default_language'] ?? ''));
                    if ($locale !== '') {
                        $locales[\strtolower($locale)] = $locale;
                    }
                }
            }
        } catch (\Throwable) {
            // Fail-open: fall back to the framework default locale below.
        }

        if ($locales === []) {
            $fallback = \trim((string)Env::default_LANGUAGE_CODE);
            $locales[\strtolower($fallback)] = $fallback;
        }

        return \array_slice(\array_values($locales), 0, self::MAX_WARM_LOCALES);
    }
}
