<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\ProductProviderInterface;

/**
 * Product Provider registry：code/type 唯一；小接口 capability 可发现；不调用 Renderer。
 *
 * Discovery：manual register + extends/module/Weline_Product/ProductProvider/
 * 禁用扩展 Provider 后，默认 type=simple 仍可通过 getByType 解析（若默认仍 enabled）。
 */
final class ProductProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_product/productprovider/';

    /** @var array<string, ProductProviderInterface> code => provider */
    private array $byCode = [];

    /** @var array<string, string> type => code */
    private array $typeIndex = [];

    /**
     * Registry-owned immutable contract snapshot.
     *
     * @var array<string, array{
     *     code: string,
     *     type: string,
     *     required_attributes: list<string>,
     *     capabilities: list<class-string>
     * }>
     */
    private array $contractsByCode = [];

    private bool $extendsLoaded = false;
    private bool $defaultEnsured = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
        private readonly bool $autoEnsureDefault = true,
    ) {
    }

    /**
     * Unit-test factory：不走 Extends / 不自动种 default（由调用方 register）。
     *
     * @param list<ProductProviderInterface> $providers
     */
    public static function forTesting(array $providers = [], bool $autoEnsureDefault = false): self
    {
        $registry = new self(autoLoadExtends: false, autoEnsureDefault: $autoEnsureDefault);
        foreach ($providers as $provider) {
            $registry->register($provider);
        }
        $registry->extendsLoaded = true;
        $registry->defaultEnsured = !$autoEnsureDefault;
        return $registry;
    }

    public function register(ProductProviderInterface $provider): void
    {
        $code = $this->normalizeKey($provider->getCode());
        $type = $this->normalizeKey($provider->getType());
        if ($code === '') {
            throw new ProductProviderConflictException(
                'product_provider_code_empty',
                __('Product Provider code 不能为空'),
            );
        }
        if ($type === '') {
            throw new ProductProviderConflictException(
                'product_provider_type_empty',
                __('Product Provider type 不能为空'),
                ['code' => $code],
            );
        }

        if (isset($this->byCode[$code])) {
            throw new ProductProviderConflictException(
                'product_provider_code_duplicate',
                __('重复的 Product Provider code：%{1}', [$code]),
                [
                    'code' => $code,
                    'existing' => $this->byCode[$code]::class,
                    'incoming' => $provider::class,
                ],
            );
        }
        if (isset($this->typeIndex[$type])) {
            throw new ProductProviderConflictException(
                'product_provider_type_duplicate',
                __('重复的 Product Provider type：%{1}', [$type]),
                [
                    'type' => $type,
                    'existing_code' => $this->typeIndex[$type],
                    'incoming_code' => $code,
                ],
            );
        }

        $required = $this->normalizeRequiredAttributes(
            $provider->getRequiredAttributes(),
            $code,
        );
        $capabilities = $this->normalizeCapabilityContract($provider, $code);

        $this->byCode[$code] = $provider;
        $this->typeIndex[$type] = $code;
        $this->contractsByCode[$code] = [
            'code' => $code,
            'type' => $type,
            'required_attributes' => $required,
            'capabilities' => $capabilities,
        ];
    }

    public function get(string $code): ?ProductProviderInterface
    {
        $this->boot();
        return $this->byCode[$this->normalizeKey($code)] ?? null;
    }

    public function getByType(string $type, bool $onlyEnabled = true): ?ProductProviderInterface
    {
        $this->boot();
        $code = $this->typeIndex[$this->normalizeKey($type)] ?? null;
        if ($code === null) {
            return null;
        }
        $provider = $this->byCode[$code] ?? null;
        if ($provider === null) {
            return null;
        }
        if ($onlyEnabled && !$provider->isEnabled()) {
            return null;
        }
        return $provider;
    }

    /**
     * @return array<string, ProductProviderInterface>
     */
    public function all(bool $onlyEnabled = false): array
    {
        $this->boot();
        if (!$onlyEnabled) {
            return $this->byCode;
        }
        return array_filter(
            $this->byCode,
            static fn (ProductProviderInterface $p): bool => $p->isEnabled(),
        );
    }

    /**
     * Metadata for all providers (enabled filter optional). Never invokes renderer.
     *
     * @return list<array<string, mixed>>
     */
    public function listMetadata(bool $onlyEnabled = true): array
    {
        $out = [];
        foreach ($this->all($onlyEnabled) as $code => $provider) {
            $contract = $this->contractsByCode[$code] ?? null;
            if ($contract === null) {
                throw new ProductProviderConflictException(
                    'product_provider_contract_missing',
                    __('Product Provider 注册契约缺失：%{1}', [$code]),
                    ['code' => $code],
                );
            }
            $metadata = $provider->getMetadata();
            $metadata['code'] = $contract['code'];
            $metadata['type'] = $contract['type'];
            $metadata['label'] = $provider->getLabel();
            $metadata['enabled'] = $provider->isEnabled();
            $metadata['sort_order'] = $provider->getSortOrder();
            $metadata['required_attributes'] = $contract['required_attributes'];
            $metadata['capabilities'] = $contract['capabilities'];
            $out[] = $metadata;
        }
        usort(
            $out,
            static fn (array $a, array $b): int => [$a['sort_order'] ?? 0, $a['code'] ?? '']
                <=> [$b['sort_order'] ?? 0, $b['code'] ?? ''],
        );
        return $out;
    }

    /**
     * Fixed required attributes for a type (fail-closed if missing/disabled).
     *
     * @return list<string>
     */
    public function requiredAttributesForType(string $type): array
    {
        $provider = $this->getByType($type, true);
        if ($provider === null) {
            throw new ProductProviderConflictException(
                'product_provider_type_unavailable',
                __('Product Provider type 不可用：%{1}', [$type]),
                ['type' => $type],
            );
        }
        $code = $this->typeIndex[$this->normalizeKey($type)] ?? '';
        $contract = $this->contractsByCode[$code] ?? null;
        if ($contract === null) {
            throw new ProductProviderConflictException(
                'product_provider_contract_missing',
                __('Product Provider 注册契约缺失：%{1}', [$code]),
                ['code' => $code, 'type' => $type],
            );
        }
        return $contract['required_attributes'];
    }

    public function clear(): void
    {
        $this->byCode = [];
        $this->typeIndex = [];
        $this->contractsByCode = [];
        $this->extendsLoaded = false;
        $this->defaultEnsured = false;
    }

    private function boot(): void
    {
        if ($this->autoEnsureDefault && !$this->defaultEnsured) {
            $this->defaultEnsured = true;
            if (!isset($this->byCode[ProductProviderInterface::CODE_DEFAULT])) {
                $this->register(new DefaultProductProvider());
            }
        }
        if ($this->autoLoadExtends && !$this->extendsLoaded) {
            $this->extendsLoaded = true;
            $this->loadFromExtends();
        }
    }

    private function loadFromExtends(): void
    {
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Product');
        } catch (\Throwable) {
            return;
        }
        if (!is_array($extendedBy)) {
            return;
        }

        foreach ($extendedBy as $extensions) {
            if (!is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $provider = $this->instantiateExtension($extension);
                $this->register($provider);
            }
        }
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function instantiateExtension(array $extension): ProductProviderInterface
    {
        $className = trim((string)($extension['class_name'] ?? $extension['class'] ?? ''));
        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($className === '' && $sourceFile !== '' && is_file($sourceFile)) {
            $content = (string)file_get_contents($sourceFile);
            $namespace = null;
            $class = null;
            if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
                $namespace = trim($m[1]);
            }
            if (preg_match('/class\s+(\w+)/', $content, $m)) {
                $class = $m[1];
            }
            if ($namespace && $class) {
                $className = $namespace . '\\' . $class;
            }
        }
        if ($className === '') {
            throw new ProductProviderConflictException(
                'product_provider_extension_class_missing',
                __('Product Provider 扩展缺少可解析类名'),
                ['source_file' => $sourceFile],
            );
        }
        if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
            require_once $sourceFile;
        }
        if (!class_exists($className)) {
            throw new ProductProviderConflictException(
                'product_provider_extension_class_missing',
                __('Product Provider 扩展类不存在：%{1}', [$className]),
                ['class' => $className, 'source_file' => $sourceFile],
            );
        }
        try {
            $instance = ObjectManager::create($className, [], false);
        } catch (\Throwable $e) {
            throw new ProductProviderConflictException(
                'product_provider_extension_instantiation_failed',
                __('Product Provider 扩展实例化失败：%{1}', [$className]),
                ['class' => $className, 'source_file' => $sourceFile],
                $e,
            );
        }
        if (!$instance instanceof ProductProviderInterface) {
            throw new ProductProviderConflictException(
                'product_provider_extension_contract_invalid',
                __('Product Provider 扩展未实现接口：%{1}', [$className]),
                ['class' => $className, 'source_file' => $sourceFile],
            );
        }
        return $instance;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * @param array<mixed> $attributes
     * @return list<string>
     */
    private function normalizeRequiredAttributes(array $attributes, string $code): array
    {
        if ($attributes === []) {
            throw new ProductProviderConflictException(
                'product_provider_required_attributes_empty',
                __('Product Provider required attributes 不能为空：%{1}', [$code]),
                ['code' => $code],
            );
        }

        $normalized = [];
        $seen = [];
        foreach ($attributes as $attribute) {
            if (!is_string($attribute) || trim($attribute) === '') {
                throw new ProductProviderConflictException(
                    'product_provider_required_attribute_invalid',
                    __('Product Provider required attribute 非法：%{1}', [$code]),
                    ['code' => $code, 'attribute' => $attribute],
                );
            }
            $attribute = trim($attribute);
            $identity = strtolower($attribute);
            if (isset($seen[$identity])) {
                throw new ProductProviderConflictException(
                    'product_provider_required_attribute_duplicate',
                    __('Product Provider required attribute 重复：%{1}', [$attribute]),
                    ['code' => $code, 'attribute' => $attribute],
                );
            }
            $seen[$identity] = true;
            $normalized[] = $attribute;
        }
        return $normalized;
    }

    /**
     * @return list<class-string>
     */
    private function normalizeCapabilityContract(
        ProductProviderInterface $provider,
        string $code,
    ): array {
        $declared = $provider->getCapabilityMap();
        $capabilities = [];
        foreach ($declared as $capability => $attached) {
            if (!is_string($capability) || trim($capability) === '' || !is_bool($attached)) {
                throw new ProductProviderConflictException(
                    'product_provider_capability_map_invalid',
                    __('Product Provider capability map 非法：%{1}', [$code]),
                    ['code' => $code, 'capability' => $capability],
                );
            }
            $capability = trim($capability);
            if ($attached && !interface_exists($capability)) {
                throw new ProductProviderConflictException(
                    'product_provider_capability_interface_missing',
                    __('Product Provider capability 接口不存在：%{1}', [$capability]),
                    ['code' => $code, 'capability' => $capability],
                );
            }
            if ($attached) {
                $capabilities[] = $capability;
            }
        }

        $known = [
            ProductPricingCapabilityInterface::class => $provider->getPricingCapability(),
            ProductInventoryCapabilityInterface::class => $provider->getInventoryCapability(),
            ProductRendererCapabilityInterface::class => $provider->getRendererCapability(),
        ];
        foreach ($known as $capability => $instance) {
            $declaredAttached = ($declared[$capability] ?? false) === true;
            $actualAttached = $instance instanceof $capability;
            if ($declaredAttached !== $actualAttached) {
                throw new ProductProviderConflictException(
                    'product_provider_capability_mismatch',
                    __('Product Provider capability 声明与实例不一致：%{1}', [$capability]),
                    [
                        'code' => $code,
                        'capability' => $capability,
                        'declared' => $declaredAttached,
                        'actual' => $actualAttached,
                    ],
                );
            }
        }

        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }
}
