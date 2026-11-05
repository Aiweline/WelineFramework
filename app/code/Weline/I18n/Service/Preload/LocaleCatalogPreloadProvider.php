<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Preload;

use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

/**
 * Publishes the installed/active locale catalogue into process memory before Worker READY.
 *
 * Root cause this removes: `Url::loadKnownLanguageCodes()` asks the localization
 * registry for "which language segments are legitimate" while parsing the URL, and
 * the answer walked providers down to a full `w_i18n_locals` select. On a cold
 * Worker the first request paid 3.2–4.4 ms for it (measured: `trace_db_top` top
 * entry `w_i18n_locals` 3.81 ms), and every worker paid it again after a deploy.
 *
 * The catalogue is a global fact (not request-private), so it belongs in worker
 * boot. Both holders are process-scoped and keyed by the i18n namespace
 * generation, so a language install/enable/disable bumps the generation and every
 * worker rebuilds on its next read — preloading does not freeze a stale answer.
 */
final class LocaleCatalogPreloadProvider extends AbstractWorkerPreloadProvider
{
    /** Control-plane / non-serving roles never serve storefront requests. */
    private const EXCLUDED_ROLES = ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'];

    public function code(): string
    {
        return 'i18n_locale_catalog';
    }

    /**
     * After the website catalogue and currency catalogue: providers that answer
     * "which locales does this site expose" read those warm-ups, and the currency
     * provider publishes currency names keyed by site default language.
     */
    public function priority(): int
    {
        return 66;
    }

    public function dependsOn(): array
    {
        return ['website_directory', 'currency_catalog'];
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
        return ['i18n', 'locale_catalog'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $warmed = 0;
        $failures = [];

        $registry = null;
        try {
            $registry = ObjectManager::getInstance(LocalizationProviderRegistry::class);
        } catch (\Throwable $throwable) {
            $failures[] = 'registry:' . $throwable->getMessage();
        }

        if ($registry instanceof LocalizationProviderRegistry) {
            // 逐个独立打点：某个 provider 坏掉不该让其它答案一起缺席。
            foreach ([
                'preferred_language_codes' => static fn(): array => $registry->preferredLanguageCodes(),
                'available_language_codes' => static fn(): array => $registry->availableLanguageCodes(),
                'installed_language_codes' => static fn(): array => $registry->preferredInstalledLanguageCodes(),
                'preferred_currency_codes' => static fn(): array => $registry->preferredCurrencyCodes(),
            ] as $label => $warm) {
                try {
                    $warm();
                    ++$warmed;
                } catch (\Throwable $throwable) {
                    $failures[] = $label . ':' . $throwable->getMessage();
                }
            }
        }

        try {
            /** @var ActiveLocaleCodeProvider $active */
            $active = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
            $active->getInstalledActiveCodes();
            ++$warmed;
            $active->getInstalledCodes();
            ++$warmed;
        } catch (\Throwable $throwable) {
            $failures[] = 'active_locales:' . $throwable->getMessage();
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($warmed === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                $failures === [] ? 'no_catalogue_warmed' : \implode('; ', $failures),
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
            ['failures' => $failures],
        );
    }
}
