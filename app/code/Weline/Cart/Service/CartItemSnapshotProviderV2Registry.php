<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartItemSnapshotProviderV2Interface;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * V2 provider registry：规范化 provider code → O(1)；重复 code fail-closed.
 * 旧 {@see CartItemSnapshotProviderRegistry} 不改。
 */
final class CartItemSnapshotProviderV2Registry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_cart/cartitemsnapshotproviderv2/';

    public const ERROR_CODE_EMPTY = 'cart_provider_code_empty';
    public const ERROR_CODE_DUPLICATE = 'cart_provider_code_duplicate';
    public const ERROR_NOT_FOUND = 'cart_provider_not_found';

    /** @var array<string, CartItemSnapshotProviderV2Interface> */
    private array $byCode = [];

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
        private readonly ?LegacyCartItemSnapshotProviderV2Adapter $legacyAdapter = null,
    ) {
    }

    private function legacy(): ?LegacyCartItemSnapshotProviderV2Adapter
    {
        if ($this->legacyAdapter !== null) {
            return $this->legacyAdapter;
        }
        try {
            $adapter = ObjectManager::getInstance(LegacyCartItemSnapshotProviderV2Adapter::class);
            return $adapter instanceof LegacyCartItemSnapshotProviderV2Adapter ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<CartItemSnapshotProviderV2Interface> $providers
     */
    public static function forTesting(array $providers = [], ?LegacyCartItemSnapshotProviderV2Adapter $legacy = null): self
    {
        $reg = new self(autoLoadExtends: false, legacyAdapter: $legacy);
        foreach ($providers as $p) {
            $reg->register($p);
        }
        $reg->extendsLoaded = true;
        return $reg;
    }

    public function register(CartItemSnapshotProviderV2Interface $provider): void
    {
        $code = $this->normalize($provider->getProviderCode());
        if ($code === '') {
            throw new CartV2ConflictException(
                self::ERROR_CODE_EMPTY,
                __('Cart V2 Provider code 不能为空'),
            );
        }
        if (isset($this->byCode[$code])) {
            throw new CartV2ConflictException(
                self::ERROR_CODE_DUPLICATE,
                __('重复的 Cart V2 Provider code：%{1}', [$code]),
                [
                    'code' => $code,
                    'existing' => $this->byCode[$code]::class,
                    'incoming' => $provider::class,
                ],
            );
        }
        $this->byCode[$code] = $provider;
    }

    public function get(string $code): ?CartItemSnapshotProviderV2Interface
    {
        $this->boot();
        return $this->byCode[$this->normalize($code)] ?? null;
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    public function resolve(
        OfferIdentity $offer,
        ScopeIdentity $scope,
        array $selection = [],
    ): CartItemSnapshot {
        $this->boot();
        $code = $this->normalize($offer->providerCode);
        $provider = $this->byCode[$code] ?? null;
        if ($provider !== null) {
            $snapshot = $provider->resolveCartItemSnapshot($offer, $scope, $selection);
            if ($snapshot instanceof CartItemSnapshot) {
                return $snapshot;
            }
        }

        // V2 无匹配时：仅当 OfferIdentity 明含 legacyProductId 才走旧 adapter
        $legacy = $this->legacy();
        if ($offer->legacyProductId !== null && $legacy !== null) {
            $adapted = $legacy->resolveCartItemSnapshot($offer, $scope, $selection);
            if ($adapted instanceof CartItemSnapshot) {
                return $adapted;
            }
        }

        throw new CartV2ConflictException(
            self::ERROR_NOT_FOUND,
            __('未找到 Cart V2 Provider：%{1}', [$offer->providerCode]),
            ['provider_code' => $offer->providerCode, 'offer' => $offer->toArray()],
        );
    }

    /** @return list<string> */
    public function codes(): array
    {
        $this->boot();
        return array_keys($this->byCode);
    }

    public function clear(): void
    {
        $this->byCode = [];
        $this->extendsLoaded = false;
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
        foreach (ExtendsData::getExtendedBy('Weline_Cart') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, CartItemSnapshotProviderV2Interface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof CartItemSnapshotProviderV2Interface) {
                        $this->register($instance);
                    }
                } catch (CartV2ConflictException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Cart V2 provider load failed: ' . $className . ' ' . $e->getMessage());
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

    private function normalize(string $code): string
    {
        return strtolower(trim($code));
    }
}
