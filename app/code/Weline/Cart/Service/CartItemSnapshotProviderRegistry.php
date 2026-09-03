<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartItemSnapshotProviderInterface;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Provider registry：规范化 provider code → O(1)；重复 code fail-closed.
 */
final class CartItemSnapshotProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_cart/cartitemsnapshotprovider/';

    public const ERROR_CODE_EMPTY = 'cart_provider_code_empty';
    public const ERROR_CODE_DUPLICATE = 'cart_provider_code_duplicate';
    public const ERROR_NOT_FOUND = 'cart_provider_not_found';

    /** @var array<string, CartItemSnapshotProviderInterface> */
    private array $byCode = [];

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<CartItemSnapshotProviderInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        foreach ($providers as $p) {
            $reg->register($p);
        }
        $reg->extendsLoaded = true;
        return $reg;
    }

    public function register(CartItemSnapshotProviderInterface $provider): void
    {
        $code = $this->normalize($provider->getProviderCode());
        if ($code === '') {
            throw new CartConflictException(
                self::ERROR_CODE_EMPTY,
                __('Cart Provider code 不能为空'),
            );
        }
        if (isset($this->byCode[$code])) {
            throw new CartConflictException(
                self::ERROR_CODE_DUPLICATE,
                __('重复的 Cart Provider code：%{1}', [$code]),
                [
                    'code' => $code,
                    'existing' => $this->byCode[$code]::class,
                    'incoming' => $provider::class,
                ],
            );
        }
        $this->byCode[$code] = $provider;
    }

    public function get(string $code): ?CartItemSnapshotProviderInterface
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

        throw new CartConflictException(
            self::ERROR_NOT_FOUND,
            __('未找到 Cart Provider：%{1}', [$offer->providerCode]),
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
                if ($className === '' || !is_subclass_of($className, CartItemSnapshotProviderInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof CartItemSnapshotProviderInterface) {
                        $this->register($instance);
                    }
                } catch (CartConflictException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Cart provider load failed: ' . $className . ' ' . $e->getMessage());
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

        // ExtendsData 提供 relative_path / file_path，没有 legacy `file` 字段。
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
