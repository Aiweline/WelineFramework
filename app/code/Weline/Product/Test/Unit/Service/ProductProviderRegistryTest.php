<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Service\DefaultProductProvider;
use Weline\Product\Service\Capability\DefaultProductPricingCapability;
use Weline\Product\Service\ProductProviderConflictException;
use Weline\Product\Service\ProductProviderRegistry;

/**
 * TEST-P2A-SPI-01：capability 可发现；重复 code/type 硬失败；required attributes 固定；禁用扩展后默认 type 继续。
 * 不调用 Renderer。
 */
final class ProductProviderRegistryTest extends TestCase
{
    public function testDefaultProviderMetadataAndCapabilities(): void
    {
        $registry = ProductProviderRegistry::forTesting([], autoEnsureDefault: true);
        $meta = $registry->listMetadata(true);
        self::assertCount(5, $meta);
        self::assertSame(
            ['simple', 'configurable', 'virtual', 'downloadable', 'bundle'],
            array_column($meta, 'type'),
        );
        self::assertSame(ProductProviderInterface::CODE_DEFAULT, $meta[0]['code']);
        self::assertSame(ProductProviderInterface::TYPE_SIMPLE, $meta[0]['type']);
        self::assertSame(DefaultProductProvider::REQUIRED_ATTRIBUTES, $meta[0]['required_attributes']);
        self::assertContains(ProductPricingCapabilityInterface::class, $meta[0]['capabilities']);
        self::assertContains(ProductInventoryCapabilityInterface::class, $meta[0]['capabilities']);
        self::assertContains(ProductRendererCapabilityInterface::class, $meta[0]['capabilities']);

        foreach (['simple', 'configurable', 'virtual', 'downloadable', 'bundle'] as $type) {
            self::assertNotNull($registry->getByType($type, true), 'Missing built-in type: ' . $type);
        }

        $provider = $registry->getByType('simple');
        self::assertInstanceOf(DefaultProductProvider::class, $provider);
        self::assertTrue($provider->getPricingCapability()?->supportsCurrency('CNY'));
        self::assertSame('strict', $provider->getInventoryCapability()?->strategy());
        self::assertFalse($provider->getRendererCapability()?->hasCustomRenderer());
        self::assertTrue($provider->getRendererCapability()?->supportsScene('detail'));
    }

    public function testDuplicateCodeFailsFast(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $this->expectException(ProductProviderConflictException::class);
        try {
            $registry->register(new DefaultProductProvider());
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_code_duplicate', $e->errorCode());
            throw $e;
        }
    }

    public function testDuplicateTypeFailsFast(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $custom = $this->providerStub('custom-a', ProductProviderInterface::TYPE_SIMPLE);
        $this->expectException(ProductProviderConflictException::class);
        try {
            $registry->register($custom);
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_type_duplicate', $e->errorCode());
            throw $e;
        }
    }

    public function testDisabledCustomKeepsDefaultType(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $registry->register($this->providerStub('bundle-ext', 'bundle', enabled: false));

        self::assertNull($registry->getByType('bundle', true));
        self::assertNotNull($registry->getByType('bundle', false));
        self::assertInstanceOf(DefaultProductProvider::class, $registry->getByType('simple', true));
        self::assertSame(DefaultProductProvider::REQUIRED_ATTRIBUTES, $registry->requiredAttributesForType('simple'));
    }

    public function testRequiredAttributesEmptyRejected(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $this->expectException(ProductProviderConflictException::class);
        $registry->register($this->providerStub('bad', 'bad-type', required: []));
    }

    public function testCustomProviderDiscoverable(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $registry->register($this->providerStub('virtual', 'virtual', required: ['name', 'sku', 'download_url']));
        $all = $registry->listMetadata(true);
        self::assertCount(2, $all);
        $codes = array_column($all, 'code');
        self::assertContains('virtual', $codes);
        self::assertSame(['name', 'sku', 'download_url'], $registry->requiredAttributesForType('virtual'));
    }

    public function testRegisteredContractCannotDriftOrSpoofCanonicalMetadata(): void
    {
        $provider = $this->providerStub(
            'mutable',
            'mutable-type',
            required: ['name', 'sku'],
            metadataOverrides: [
                'code' => 'spoofed',
                'type' => 'spoofed-type',
                'required_attributes' => ['unsafe'],
                'capabilities' => ['UnsafeCapability'],
            ],
        );
        $registry = ProductProviderRegistry::forTesting([$provider]);

        $provider->code = 'changed';
        $provider->type = 'changed-type';
        $provider->required = ['unsafe'];
        $provider->capabilityMap = [
            ProductPricingCapabilityInterface::class => true,
        ];

        $metadata = $registry->listMetadata(true);
        self::assertCount(1, $metadata);
        self::assertSame('mutable', $metadata[0]['code']);
        self::assertSame('mutable-type', $metadata[0]['type']);
        self::assertSame(['name', 'sku'], $metadata[0]['required_attributes']);
        self::assertSame([], $metadata[0]['capabilities']);
        self::assertSame(['name', 'sku'], $registry->requiredAttributesForType('mutable-type'));
        self::assertSame($provider, $registry->getByType('mutable-type'));
        self::assertNull($registry->getByType('changed-type'));
    }

    public function testCapabilityDeclarationMustMatchTypedCapability(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $provider = $this->providerStub(
            'mismatch',
            'mismatch',
            capabilityMap: [ProductPricingCapabilityInterface::class => true],
        );

        $this->expectException(ProductProviderConflictException::class);
        try {
            $registry->register($provider);
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_capability_mismatch', $e->errorCode());
            throw $e;
        }
    }

    public function testRequiredAttributeEntriesAreNormalizedAndUnique(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $this->expectException(ProductProviderConflictException::class);
        try {
            $registry->register($this->providerStub(
                'duplicate-required',
                'duplicate-required',
                required: ['name', ' NAME '],
            ));
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_required_attribute_duplicate', $e->errorCode());
            throw $e;
        }
    }

    public function testSeededDisabledCustomStillEnsuresDefaultType(): void
    {
        $registry = ProductProviderRegistry::forTesting(
            [$this->providerStub('disabled-custom', 'disabled-custom', enabled: false)],
            autoEnsureDefault: true,
        );

        self::assertNull($registry->getByType('disabled-custom', true));
        self::assertNotNull($registry->getByType('disabled-custom', false));
        self::assertInstanceOf(DefaultProductProvider::class, $registry->getByType('simple', true));
        self::assertSame(DefaultProductProvider::REQUIRED_ATTRIBUTES, $registry->requiredAttributesForType('simple'));
    }

    public function testMatchedExtensionWithInvalidContractFailsFast(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $method = new \ReflectionMethod(ProductProviderRegistry::class, 'instantiateExtension');

        $this->expectException(ProductProviderConflictException::class);
        try {
            $method->invoke($registry, ['class_name' => DefaultProductPricingCapability::class]);
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_extension_contract_invalid', $e->errorCode());
            throw $e;
        }
    }

    /**
     * @param list<string> $required
     * @param array<class-string, bool> $capabilityMap
     * @param array<string, mixed> $metadataOverrides
     */
    private function providerStub(
        string $code,
        string $type,
        bool $enabled = true,
        array $required = ['name'],
        array $capabilityMap = [],
        ?ProductPricingCapabilityInterface $pricingCapability = null,
        ?ProductInventoryCapabilityInterface $inventoryCapability = null,
        ?ProductRendererCapabilityInterface $rendererCapability = null,
        array $metadataOverrides = [],
    ): ProductProviderInterface {
        return new class (
            $code,
            $type,
            $enabled,
            $required,
            $capabilityMap,
            $pricingCapability,
            $inventoryCapability,
            $rendererCapability,
            $metadataOverrides,
        ) implements ProductProviderInterface {
            /**
             * @param list<string> $required
             * @param array<class-string, bool> $capabilityMap
             * @param array<string, mixed> $metadataOverrides
             */
            public function __construct(
                public string $code,
                public string $type,
                public bool $enabled,
                public array $required,
                public array $capabilityMap,
                public ?ProductPricingCapabilityInterface $pricingCapability,
                public ?ProductInventoryCapabilityInterface $inventoryCapability,
                public ?ProductRendererCapabilityInterface $rendererCapability,
                public array $metadataOverrides,
            ) {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getType(): string
            {
                return $this->type;
            }

            public function getLabel(): string
            {
                return $this->code;
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function getSortOrder(): int
            {
                return 10;
            }

            public function getRequiredAttributes(): array
            {
                return $this->required;
            }

            public function getCapabilityMap(): array
            {
                return $this->capabilityMap;
            }

            public function getPricingCapability(): ?ProductPricingCapabilityInterface
            {
                return $this->pricingCapability;
            }

            public function getInventoryCapability(): ?ProductInventoryCapabilityInterface
            {
                return $this->inventoryCapability;
            }

            public function getRendererCapability(): ?ProductRendererCapabilityInterface
            {
                return $this->rendererCapability;
            }

            public function getMetadata(): array
            {
                return array_replace([
                    'code' => $this->code,
                    'type' => $this->type,
                    'label' => $this->code,
                    'enabled' => $this->enabled,
                    'sort_order' => 10,
                    'required_attributes' => $this->required,
                    'capabilities' => array_keys(array_filter($this->capabilityMap)),
                ], $this->metadataOverrides);
            }
        };
    }
}
