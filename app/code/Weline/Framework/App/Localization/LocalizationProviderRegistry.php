<?php

declare(strict_types=1);

namespace Weline\Framework\App\Localization;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class LocalizationProviderRegistry implements ProcessSharedInterface
{
    public const CAPABILITY_PREFIX = 'localization_provider.';

    /** @var list<LocalizationProviderInterface>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ServiceProviderRegistry $serviceProviders,
    ) {
    }

    /** @return list<string> */
    public function preferredLanguageCodes(): array
    {
        foreach ($this->providers() as $provider) {
            $codes = $this->normalizeLanguageCodes($provider->languageCodes());
            if ($codes !== []) {
                return $codes;
            }
        }
        return [];
    }

    /** @return list<string> */
    public function preferredCurrencyCodes(): array
    {
        foreach ($this->providers() as $provider) {
            $codes = $this->normalizeCurrencyCodes($provider->currencyCodes());
            if ($codes !== []) {
                return $codes;
            }
        }
        return [];
    }

    /**
     * First non-empty default language from providers (narrow scope wins by priority).
     */
    public function preferredDefaultLanguage(): ?string
    {
        foreach ($this->providers() as $provider) {
            $codes = $this->normalizeLanguageCodes([(string)($provider->defaultLanguage() ?? '')]);
            if ($codes !== []) {
                return $codes[0];
            }
        }

        return null;
    }

    /**
     * First non-empty default currency from providers (narrow scope wins by priority).
     */
    public function preferredDefaultCurrency(): ?string
    {
        foreach ($this->providers() as $provider) {
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
        foreach ($this->providers() as $provider) {
            $codes = $provider->installedLanguageCodes();
            if ($codes === null) {
                continue;
            }
            $normalized = $this->normalizeLanguageCodes($codes);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
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
