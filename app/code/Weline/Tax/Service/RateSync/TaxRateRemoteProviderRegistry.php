<?php

declare(strict_types=1);

namespace Weline\Tax\Service\RateSync;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Api\TaxRateRemoteProviderInterface;

/**
 * Built-in free/pro providers + ExtendsData scan for foreign modules.
 */
final class TaxRateRemoteProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_tax/taxrateremoteprovider/';

    /** @var array<string, TaxRateRemoteProviderInterface> */
    private array $byCode = [];

    private bool $booted = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<TaxRateRemoteProviderInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        foreach ($providers as $provider) {
            $reg->register($provider);
        }
        $reg->booted = true;

        return $reg;
    }

    public function register(TaxRateRemoteProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->code()));
        if ($code === '') {
            throw new \InvalidArgumentException('tax_rate_provider_code_empty');
        }
        if (isset($this->byCode[$code])) {
            throw new \InvalidArgumentException('tax_rate_provider_code_duplicate:' . $code);
        }
        $this->byCode[$code] = $provider;
    }

    /** @return list<TaxRateRemoteProviderInterface> */
    public function all(): array
    {
        $this->boot();

        return array_values($this->byCode);
    }

    public function get(string $code): ?TaxRateRemoteProviderInterface
    {
        $this->boot();

        return $this->byCode[strtolower(trim($code))] ?? null;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->registerBuiltIns();
        if ($this->autoLoadExtends) {
            $this->loadExtends();
        }
    }

    private function registerBuiltIns(): void
    {
        foreach ([
            StaticSeedTaxRateProvider::class,
            VatComplyEuTaxRateProvider::class,
            GenericHttpTaxRateProvider::class,
        ] as $class) {
            try {
                $instance = ObjectManager::getInstance($class);
            } catch (\Throwable) {
                $instance = new $class();
            }
            if (!$instance instanceof TaxRateRemoteProviderInterface) {
                continue;
            }
            $code = strtolower(trim($instance->code()));
            if ($code === '' || isset($this->byCode[$code])) {
                continue;
            }
            $this->byCode[$code] = $instance;
        }
    }

    private function loadExtends(): void
    {
        if (!class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Tax') as $sourceModule => $extensions) {
            if (!is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $rel = strtolower((string)($extension['relative_path'] ?? $extension['file_path'] ?? ''));
                if ($rel === '' || !str_starts_with(str_replace('\\', '/', $rel), self::EXTENDS_PREFIX)) {
                    continue;
                }
                $class = (string)($extension['class'] ?? $extension['class_name'] ?? '');
                if ($class === '' || !class_exists($class)) {
                    $sourceFile = (string)($extension['source_file'] ?? '');
                    if ($sourceFile !== '' && is_file($sourceFile)) {
                        require_once $sourceFile;
                    }
                    $class = (string)($extension['class'] ?? $extension['class_name'] ?? '');
                }
                if ($class === '' || !class_exists($class)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($class);
                } catch (\Throwable) {
                    continue;
                }
                if (!$instance instanceof TaxRateRemoteProviderInterface) {
                    continue;
                }
                $code = strtolower(trim($instance->code()));
                if ($code === '' || isset($this->byCode[$code])) {
                    continue;
                }
                $this->byCode[$code] = $instance;
            }
        }
    }
}
