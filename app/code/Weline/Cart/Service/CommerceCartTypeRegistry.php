<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CommerceCartTypeInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * Cart 售卖类型 Registry：内置 toc + extends 扫描；O(1) 查找；热路径不探测 B2B。
 */
final class CommerceCartTypeRegistry
{
    public const CODE_TOC = TocCommerceCartType::CODE;

    private const EXTENDS_PREFIX = 'extends/module/weline_cart/commercecarttype/';

    public const ERROR_EMPTY = 'cart_commerce_type_code_empty';
    public const ERROR_DUPLICATE = 'cart_commerce_type_code_duplicate';
    public const ERROR_UNKNOWN = 'cart_commerce_type_unregistered';

    /** @var array<string, CommerceCartTypeInterface> */
    private array $byCode = [];

    private bool $booted = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<CommerceCartTypeInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        $reg->register(new TocCommerceCartType());
        foreach ($providers as $provider) {
            if ($provider->getCode() === self::CODE_TOC) {
                continue;
            }
            $reg->register($provider);
        }
        $reg->booted = true;
        return $reg;
    }

    public function register(CommerceCartTypeInterface $provider): void
    {
        $code = $this->normalize($provider->getCode());
        if ($code === '') {
            throw new CartConflictException(self::ERROR_EMPTY, __('Cart 售卖类型 code 不能为空'));
        }
        if (isset($this->byCode[$code])) {
            throw new CartConflictException(
                self::ERROR_DUPLICATE,
                __('重复的 Cart 售卖类型 code：%{1}', [$code]),
                ['code' => $code],
            );
        }
        $this->byCode[$code] = $provider;
    }

    public function has(string $code): bool
    {
        $this->boot();
        return isset($this->byCode[$this->normalize($code)]);
    }

    public function get(string $code): ?CommerceCartTypeInterface
    {
        $this->boot();
        return $this->byCode[$this->normalize($code)] ?? null;
    }

    public function require(string $code): CommerceCartTypeInterface
    {
        $type = $this->get($code);
        if ($type === null) {
            throw new CartConflictException(
                self::ERROR_UNKNOWN,
                __('未注册的购物车售卖类型：%{1}', [$code]),
                ['code' => $code],
            );
        }
        return $type;
    }

    /** @return list<string> */
    public function codes(): array
    {
        $this->boot();
        return array_keys($this->byCode);
    }

    /**
     * @return list<array{code:string,label:string,badge_tone:string}>
     */
    public function labelRows(): array
    {
        $this->boot();
        $rows = [];
        foreach ($this->byCode as $code => $type) {
            $rows[] = [
                'code' => $code,
                'label' => $type->getLabel(),
                'badge_tone' => $type->getBadgeTone(),
            ];
        }
        return $rows;
    }

    public function resolveLabel(string $code): string
    {
        $type = $this->get($code);
        if ($type !== null) {
            return $type->getLabel();
        }
        $normalized = $this->normalize($code);
        if ($normalized === '' || $normalized === self::CODE_TOC) {
            return (string)__('零售');
        }
        return (string)__('历史批发');
    }

    public function resolveBadgeTone(string $code): string
    {
        $type = $this->get($code);
        return $type !== null ? $type->getBadgeTone() : 'warning';
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if (!isset($this->byCode[self::CODE_TOC])) {
            $this->register(new TocCommerceCartType());
        }
        if (!$this->autoLoadExtends || !class_exists(ExtendsData::class)) {
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
                if ($className === '' || !is_subclass_of($className, CommerceCartTypeInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof CommerceCartTypeInterface
                        && $this->normalize($instance->getCode()) !== self::CODE_TOC
                    ) {
                        $this->register($instance);
                    }
                } catch (CartConflictException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Cart commerce type load failed: ' . $className . ' ' . $e->getMessage());
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
