<?php

declare(strict_types=1);

namespace Weline\Product\Service\Storefront;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;

/**
 * Discovers StorefrontPriceAdjustmentProviderInterface via Product extends registry.
 */
final class StorefrontPriceAdjustmentProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_product/storefrontpriceadjustmentprovider/';

    /** @var array<string, StorefrontPriceAdjustmentProviderInterface> */
    private array $byCode = [];

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<StorefrontPriceAdjustmentProviderInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        foreach ($providers as $provider) {
            $reg->register($provider);
        }
        $reg->extendsLoaded = true;

        return $reg;
    }

    public function register(StorefrontPriceAdjustmentProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->getCode()));
        if ($code === '') {
            throw new \InvalidArgumentException('Storefront price adjustment provider code must not be empty');
        }
        if (isset($this->byCode[$code])) {
            throw new \InvalidArgumentException(sprintf(
                'Duplicate storefront price adjustment provider code: %s',
                $code,
            ));
        }
        $this->byCode[$code] = $provider;
    }

    /**
     * @return list<StorefrontPriceAdjustmentProviderInterface>
     */
    public function all(): array
    {
        $this->boot();
        $providers = array_values($this->byCode);
        usort(
            $providers,
            static function (
                StorefrontPriceAdjustmentProviderInterface $left,
                StorefrontPriceAdjustmentProviderInterface $right,
            ): int {
                $byPriority = $right->getPriority() <=> $left->getPriority();
                if ($byPriority !== 0) {
                    return $byPriority;
                }

                return strcmp($left->getCode(), $right->getCode());
            },
        );

        return $providers;
    }

    private function boot(): void
    {
        if ($this->extendsLoaded || !$this->autoLoadExtends) {
            return;
        }
        $this->extendsLoaded = true;
        if (!class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Product') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, StorefrontPriceAdjustmentProviderInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof StorefrontPriceAdjustmentProviderInterface) {
                        $this->register($instance);
                    }
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Storefront price adjustment provider load failed: ' . $className . ' ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $extension */
    private function extensionClass(string $sourceModule, array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $className = trim((string)($extension[$key] ?? ''));
            if ($className !== '') {
                return ltrim($className, '\\');
            }
        }

        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        if (!str_starts_with(strtolower($relativePath), 'extends/module/')) {
            return '';
        }
        $classPath = substr($relativePath, strlen('extends/module/'));
        if (!str_ends_with(strtolower($classPath), '.php')) {
            return '';
        }
        $classPath = substr($classPath, 0, -4);
        $moduleNamespace = str_replace('_', '\\', trim($sourceModule));
        if ($moduleNamespace === '' || $classPath === '') {
            return '';
        }

        return $moduleNamespace . '\\Extends\\Module\\' . str_replace('/', '\\', $classPath);
    }
}
