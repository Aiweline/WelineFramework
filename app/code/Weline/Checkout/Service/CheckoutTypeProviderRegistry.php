<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutTypeProviderInterface;
use Weline\Checkout\CheckoutType\StandardCheckoutTypeProvider;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * 结账 Type Registry：内置 standard + extends 扫描；列出切换项时由调用方结合已有 session 桶。
 */
final class CheckoutTypeProviderRegistry
{
    public const CODE_STANDARD = StandardCheckoutTypeProvider::CODE;

    private const EXTENDS_PREFIX = 'extends/module/weline_checkout/checkouttype/';

    /** @var array<string, CheckoutTypeProviderInterface> */
    private array $byCode = [];

    private bool $booted = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<CheckoutTypeProviderInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        $reg->register(new StandardCheckoutTypeProvider());
        foreach ($providers as $provider) {
            if ($provider->getCode() === self::CODE_STANDARD) {
                continue;
            }
            $reg->register($provider);
        }
        $reg->booted = true;

        return $reg;
    }

    public function register(CheckoutTypeProviderInterface $provider): void
    {
        $code = $this->normalize($provider->getCode());
        if ($code === '') {
            throw new \InvalidArgumentException('Checkout Type code 不能为空');
        }
        if ($code === 'continue_pay') {
            throw new \InvalidArgumentException('禁止将 continue_pay 注册为 CheckoutType');
        }
        if (isset($this->byCode[$code])) {
            throw new \InvalidArgumentException('重复的 Checkout Type code：' . $code);
        }
        $this->byCode[$code] = $provider;
    }

    public function has(string $code): bool
    {
        $this->boot();

        return isset($this->byCode[$this->normalize($code)]);
    }

    public function get(string $code): ?CheckoutTypeProviderInterface
    {
        $this->boot();

        return $this->byCode[$this->normalize($code)] ?? null;
    }

    /**
     * @return list<CheckoutTypeProviderInterface>
     */
    public function all(): array
    {
        $this->boot();
        $list = array_values($this->byCode);
        usort(
            $list,
            static fn (CheckoutTypeProviderInterface $a, CheckoutTypeProviderInterface $b): int
                => $a->getSortOrder() <=> $b->getSortOrder()
        );

        return $list;
    }

    /**
     * cart_type (toc|tob) → checkout type_code (standard|tob).
     */
    public function typeCodeFromCartType(string $cartType): string
    {
        $cart = strtolower(trim($cartType));
        if ($cart === '' || $cart === 'toc') {
            return self::CODE_STANDARD;
        }
        $this->boot();
        foreach ($this->byCode as $code => $provider) {
            if ($provider->getCartTypeCode() === $cart) {
                return $code;
            }
        }

        return self::CODE_STANDARD;
    }

    public function cartTypeFromTypeCode(string $typeCode): string
    {
        $provider = $this->get($typeCode);

        return $provider !== null ? $provider->getCartTypeCode() : 'toc';
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if (!isset($this->byCode[self::CODE_STANDARD])) {
            $this->register(new StandardCheckoutTypeProvider());
        }
        if (!$this->autoLoadExtends || !class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Checkout') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, CheckoutTypeProviderInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof CheckoutTypeProviderInterface
                        && $this->normalize($instance->getCode()) !== self::CODE_STANDARD
                        && !isset($this->byCode[$this->normalize($instance->getCode())])
                    ) {
                        $this->register($instance);
                    }
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Checkout type load failed: ' . $className . ' ' . $e->getMessage());
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
