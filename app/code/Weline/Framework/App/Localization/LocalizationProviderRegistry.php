<?php

declare(strict_types=1);

namespace Weline\Framework\App\Localization;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class LocalizationProviderRegistry implements ProcessSharedInterface
{
    public const CAPABILITY_PREFIX = 'localization_provider.';

    /**
     * Providers at this priority and above are backend-tier only.
     * Storefront/website defaults must use preferredDefaultLanguage() (excludes this tier);
     * backend personal language uses preferredDefaultLanguageWithMinPriority(self::BACKEND_TIER_MIN_PRIORITY).
     */
    public const BACKEND_TIER_MIN_PRIORITY = 200;

    /** @var list<LocalizationProviderInterface>|null */
    private ?array $providers = null;

    /**
     * Process-scoped memo for compiled catalogue answers.
     *
     * installedLanguageCodes() walks every provider and, for the storefront
     * provider, reads the installed-language catalogue from the database. The
     * storefront render context asks for it on every request
     * (StorefrontRenderContextInstaller::resolveLocaleCatalog), where it measured
     * ~4 ms per request with no caching in between. This registry is a
     * process-level singleton (ObjectManager), so a plain instance memo is
     * process-scoped. Dropped by {@see self::clearCaches()}.
     *
     * @var array<string, array<int, string>>
     */
    private array $memo = [];

    /**
     * Catalogue version the memo was built under.
     *
     * The memo is process-scoped, so without a version guard a worker that
     * answered before a catalogue change keeps that answer until someone clears
     * it locally. Every language/currency catalogue change already bumps the
     * i18n namespace generation, so keying the memo on it gives cross-worker
     * invalidation instead of "stale until restart".
     */
    private string $memoToken = '';

    public function __construct(
        private readonly ServiceProviderRegistry $serviceProviders,
    ) {
    }

    /**
     * Drop memoised catalogue answers and the provider list (catalogue changed).
     */
    public function clearCaches(): void
    {
        $this->memo = [];
        $this->memoToken = '';
        $this->providers = null;
    }

    /** @return list<string> */
    public function preferredLanguageCodes(): array
    {
        $memoized = $this->memoGet('language_codes');
        if ($memoized !== null) {
            return $memoized;
        }
        foreach ($this->providers() as $provider) {
            $codes = $this->normalizeLanguageCodes($provider->languageCodes());
            if ($codes !== []) {
                return $this->memoSet('language_codes', $codes);
            }
        }
        return $this->memoSet('language_codes', []);
    }

    /**
     * Union of every provider's language codes.
     *
     * Used where the question is "which locale segments are legitimate?", not
     * "what is the preferred ordering?". Narrow scopes (e.g. a resolved website
     * with only two languages) must not hide locales that another provider
     * already installed, otherwise those route segments fall back to the
     * default language.
     *
     * @return list<string>
     */
    public function availableLanguageCodes(): array
    {
        $memoized = $this->memoGet('available_language_codes');
        if ($memoized !== null) {
            return $memoized;
        }

        $union = [];
        foreach ($this->providers() as $provider) {
            foreach ($this->normalizeLanguageCodes($provider->languageCodes()) as $code) {
                $union[\strtolower($code)] = $code;
            }
        }

        return $this->memoSet('available_language_codes', \array_values($union));
    }

    /** @return list<string> */
    public function preferredCurrencyCodes(): array
    {
        $memoized = $this->memoGet('currency_codes');
        if ($memoized !== null) {
            return $memoized;
        }
        foreach ($this->providers() as $provider) {
            $codes = $this->normalizeCurrencyCodes($provider->currencyCodes());
            if ($codes !== []) {
                return $this->memoSet('currency_codes', $codes);
            }
        }

        return $this->memoSet('currency_codes', []);
    }

    /**
     * First non-empty website/storefront default language (excludes backend tier ≥ BACKEND_TIER_MIN_PRIORITY).
     */
    public function preferredDefaultLanguage(): ?string
    {
        foreach ($this->providers() as $provider) {
            if ($provider->priority() >= self::BACKEND_TIER_MIN_PRIORITY) {
                continue;
            }
            $codes = $this->normalizeLanguageCodes([(string)($provider->defaultLanguage() ?? '')]);
            if ($codes !== []) {
                return $codes[0];
            }
        }

        return null;
    }

    /**
     * First non-empty website/storefront default currency (excludes backend tier ≥ BACKEND_TIER_MIN_PRIORITY).
     */
    public function preferredDefaultCurrency(): ?string
    {
        foreach ($this->providers() as $provider) {
            if ($provider->priority() >= self::BACKEND_TIER_MIN_PRIORITY) {
                continue;
            }
            $codes = $this->normalizeCurrencyCodes([(string)($provider->defaultCurrency() ?? '')]);
            if ($codes !== []) {
                return $codes[0];
            }
        }

        return null;
    }

    public function supportsLanguage(string $code): bool
    {
        foreach ($this->providers() as $provider) {
            $supported = $provider->supportsLanguage($code);
            if ($supported !== null) {
                return $supported;
            }
        }
        return false;
    }

    public function supportsCurrency(string $code): bool
    {
        foreach ($this->providers() as $provider) {
            $supported = $provider->supportsCurrency($code);
            if ($supported !== null) {
                return $supported;
            }
        }
        return false;
    }

    /**
     * First non-empty installed locale catalog from providers (narrow scope wins).
     *
     * @return list<string>
     */
    public function preferredInstalledLanguageCodes(): array
    {
        $memoized = $this->memoGet('installed_language_codes');
        if ($memoized !== null) {
            return $memoized;
        }

        foreach ($this->providers() as $provider) {
            $codes = $provider->installedLanguageCodes();
            if ($codes === null) {
                continue;
            }
            $normalized = $this->normalizeLanguageCodes($codes);
            if ($normalized !== []) {
                return $this->memoSet('installed_language_codes', $normalized);
            }
        }

        return $this->memoSet('installed_language_codes', []);
    }

    /**
     * First non-empty default language among providers with priority >= $minPriority.
     * Used for backend personal language (Backend LocalizationProvider tier) so
     * storefront Website providers do not answer as backend default.
     */
    public function preferredDefaultLanguageWithMinPriority(int $minPriority): ?string
    {
        foreach ($this->providers() as $provider) {
            if ($provider->priority() < $minPriority) {
                continue;
            }
            $codes = $this->normalizeLanguageCodes([(string)($provider->defaultLanguage() ?? '')]);
            if ($codes !== []) {
                return $codes[0];
            }
        }

        return null;
    }

    /**
     * Read a memoised catalogue answer, or null when the memo is empty / stale.
     *
     * A null generation means "inside an open transaction": the catalogue cannot
     * be trusted there, so the memo is bypassed instead of freezing a dirty read
     * for the rest of the process life.
     */
    private function memoGet(string $key): ?array
    {
        $token = $this->currentMemoToken();
        if ($token === '') {
            $this->memo = [];
            $this->memoToken = '';
            return null;
        }
        if ($this->memoToken !== $token) {
            $this->memo = [];
            $this->memoToken = $token;
            return null;
        }

        return $this->memo[$key] ?? null;
    }

    /** @param array<int, string> $value @return list<string> */
    private function memoSet(string $key, array $value): array
    {
        $token = $this->currentMemoToken();
        if ($token === '') {
            return $value;
        }
        if ($this->memoToken !== $token) {
            $this->memo = [];
            $this->memoToken = $token;
        }

        return $this->memo[$key] = $value;
    }

    private function currentMemoToken(): string
    {
        return DictionaryCacheNamespace::generationStamp();
    }

    /** @return list<LocalizationProviderInterface> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach ($this->serviceProviders->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $implementation) {
            try {
                $provider = ObjectManager::getInstance($implementation);
                if ($provider instanceof LocalizationProviderInterface) {
                    $providers[] = $provider;
                }
            } catch (\Throwable $throwable) {
                if (function_exists('w_log_error')) {
                    w_log_error(
                        "[Localization] Provider {$implementation} failed: {$throwable->getMessage()}",
                        ['implementation' => $implementation],
                        'runtime',
                    );
                }
            }
        }
        usort(
            $providers,
            static fn(LocalizationProviderInterface $left, LocalizationProviderInterface $right): int =>
                $right->priority() <=> $left->priority(),
        );

        return $this->providers = $providers;
    }

    /** @param array<int, mixed> $codes @return list<string> */
    private function normalizeLanguageCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = str_replace('-', '_', trim((string)$code));
            if ($code !== '') {
                $normalized[] = $code;
            }
        }
        return array_values(array_unique($normalized));
    }

    /** @param array<int, mixed> $codes @return list<string> */
    private function normalizeCurrencyCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code !== '') {
                $normalized[] = $code;
            }
        }
        return array_values(array_unique($normalized));
    }
}
