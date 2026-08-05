<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Hook\HookRenderResult;
use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Api\ProductSceneRendererInterface;
use Weline\Product\Service\Capability\DefaultProductInventoryCapability;
use Weline\Product\Service\Capability\DefaultProductPricingCapability;
use Weline\Product\Service\Capability\DefaultProductRendererCapability;
use Weline\Product\Service\DefaultProductProvider;
use Weline\Product\Service\ProductProviderConflictException;
use Weline\Product\Service\ProductProviderRegistry;
use Weline\Product\Service\ProductSceneRenderer;

/**
 * TEST-P2C-RENDER-01 / 02 / 03 + HookRenderResult contract.
 */
final class ProductSceneRendererTest extends TestCase
{
    public function testDefaultAndMissingCustomFallBackToDefaultTemplate(): void
    {
        $registry = ProductProviderRegistry::forTesting([], autoEnsureDefault: true);
        $renderer = new ProductSceneRenderer($registry);
        $ctx = new ProductSceneContext(
            scene: 'detail',
            productType: 'simple',
            websiteId: 0,
            storeId: 0,
            product: [
                'name' => 'Demo',
                'sku' => 'SKU-1',
                'description' => 'Hello',
                'price_label' => '¥10',
            ],
        );
        $result = $renderer->render($ctx);
        self::assertFalse($result->usedFallback);
        self::assertStringContainsString('w-product--detail', $result->html);
        self::assertStringContainsString('Demo', $result->html);
        self::assertNotSame('', $result->cacheKey);
        self::assertSame('default', $result->providerCode);
    }

    public function testDuplicateProviderFailsAndMissingRendererUsesDefault(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        try {
            $registry->register(new DefaultProductProvider());
            self::fail('duplicate expected');
        } catch (ProductProviderConflictException $e) {
            self::assertSame('product_provider_code_duplicate', $e->errorCode());
        }

        $custom = $this->providerWithRenderer('gift-ext', 'gift', new DefaultProductRendererCapability(
            rendererClass: '\\Does\\Not\\Exist\\Renderer',
        ));
        $registry->register($custom);
        $renderer = new ProductSceneRenderer($registry);
        $result = $renderer->render(new ProductSceneContext(
            scene: 'list',
            productType: 'gift',
            product: ['name' => 'Gift', 'sku' => 'G1'],
        ));
        self::assertTrue($result->usedFallback);
        self::assertSame(ProductSceneRenderer::ERROR_CUSTOM_EXCEPTION, $result->errorCode);
        self::assertStringContainsString('Gift', $result->html);
    }

    public function testEmptyHandledEmptyAndExceptionSemantics(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());

        $registry->register($this->providerWithRenderer(
            'empty-bug',
            'empty_bug',
            new DefaultProductRendererCapability(rendererClass: EmptyBugRenderer::class),
        ));
        $registry->register($this->providerWithRenderer(
            'empty-ok',
            'empty_ok',
            new DefaultProductRendererCapability(rendererClass: HandledEmptyRenderer::class),
        ));
        $registry->register($this->providerWithRenderer(
            'boom',
            'boom',
            new DefaultProductRendererCapability(rendererClass: ThrowingRenderer::class),
        ));

        $renderer = new ProductSceneRenderer($registry);

        $bug = $renderer->render(new ProductSceneContext(
            scene: 'detail',
            productType: 'empty_bug',
            product: ['name' => 'ShouldFallback', 'sku' => 'E1'],
        ));
        self::assertTrue($bug->usedFallback);
        self::assertSame(ProductSceneRenderer::ERROR_CUSTOM_EMPTY, $bug->errorCode);
        self::assertStringContainsString('ShouldFallback', $bug->html);

        $ok = $renderer->render(new ProductSceneContext(
            scene: 'detail',
            productType: 'empty_ok',
            product: ['name' => 'Hidden', 'sku' => 'E2'],
        ));
        self::assertTrue($ok->handledEmpty);
        self::assertFalse($ok->usedFallback);
        self::assertSame('', $ok->html);

        $boom = $renderer->render(new ProductSceneContext(
            scene: 'detail',
            productType: 'boom',
            product: ['name' => 'AfterCrash', 'sku' => 'E3'],
        ));
        self::assertTrue($boom->usedFallback);
        self::assertSame(ProductSceneRenderer::ERROR_CUSTOM_EXCEPTION, $boom->errorCode);
        self::assertStringContainsString('AfterCrash', $boom->html);
        $loggedErrors = $renderer->drainLoggedErrors();
        self::assertContains(ProductSceneRenderer::ERROR_CUSTOM_EXCEPTION, $loggedErrors);
        self::assertSame([], array_values(array_filter(
            $loggedErrors,
            static fn (string $code): bool => str_contains($code, 'boom'),
        )), 'Exception messages must not enter the public diagnostic buffer');
        self::assertSame([], $renderer->drainLoggedErrors(), 'Diagnostic drain must be destructive');
    }

    public function testHtmlEscapedAndTemplatePathRejected(): void
    {
        $renderer = ProductSceneRenderer::forTesting();
        $xss = $renderer->render(new ProductSceneContext(
            scene: 'detail',
            product: [
                'name' => '<script>alert(1)</script>',
                'sku' => '"onload="',
                'description' => '<img src=x onerror=alert(1)>',
            ],
        ));
        self::assertStringNotContainsString('<script>', $xss->html);
        self::assertStringContainsString('&lt;script&gt;', $xss->html);
        self::assertStringContainsString('&lt;img', $xss->html);

        $rejected = $renderer->render(new ProductSceneContext(
            scene: 'list',
            product: ['name' => 'X', 'sku' => '1'],
            options: ['template_path' => '/etc/passwd'],
        ));
        self::assertSame(ProductSceneRenderer::ERROR_TEMPLATE_PATH_REJECTED, $rejected->errorCode);
        self::assertTrue($rejected->usedFallback);
        self::assertStringContainsString('w-product--list', $rejected->html);
    }

    public function testUnsupportedSceneFailsClosedAndDisabledProviderUsesDefault(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $registry->register($this->providerWithRenderer(
            'disabled-gift',
            'disabled_gift',
            new DefaultProductRendererCapability(),
            enabled: false,
        ));
        $renderer = new ProductSceneRenderer($registry);

        $unsupported = $renderer->render(new ProductSceneContext(
            scene: '../detail',
            product: ['name' => 'MustNotRender', 'sku' => 'BAD'],
        ));
        self::assertSame(ProductSceneRenderer::ERROR_SCENE_UNSUPPORTED, $unsupported->errorCode);
        self::assertFalse($unsupported->usedFallback);
        self::assertSame('', $unsupported->html);

        $disabled = $renderer->render(new ProductSceneContext(
            scene: 'list',
            productType: 'disabled_gift',
            product: ['name' => 'Safe default', 'sku' => 'DG-1'],
        ));
        self::assertSame(ProductSceneRenderer::ERROR_PROVIDER_DISABLED, $disabled->errorCode);
        self::assertTrue($disabled->usedFallback);
        self::assertSame('default', $disabled->providerCode);
        self::assertStringContainsString('Safe default', $disabled->html);
    }

    public function testCustomRendererUsesContainerAndCacheIdentityCoversRenderedInput(): void
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $registry->register($this->providerWithRenderer(
            'injected',
            'injected',
            new DefaultProductRendererCapability(rendererClass: InjectedRenderer::class),
        ));
        $renderer = new ProductSceneRenderer($registry);
        $base = new ProductSceneContext(
            scene: 'list',
            productType: 'injected',
            product: ['sku' => 'I-1', 'name' => 'Injected'],
            options: ['badge' => 'new', 'flags' => ['a' => true, 'b' => false]],
        );

        $custom = $renderer->render($base);
        self::assertFalse($custom->usedFallback);
        self::assertSame('<strong>dependency-ready</strong>', $custom->html);

        $same = new ProductSceneContext(
            scene: 'list',
            productType: 'injected',
            product: ['name' => 'Injected', 'sku' => 'I-1'],
            options: ['flags' => ['b' => false, 'a' => true], 'badge' => 'new'],
        );
        self::assertSame(
            $renderer->buildCacheKey($base, 'injected'),
            $renderer->buildCacheKey($same, 'injected'),
            'Associative key order must not change cache identity',
        );

        $changed = new ProductSceneContext(
            scene: 'list',
            productType: 'injected',
            product: ['sku' => 'I-1', 'name' => 'Changed'],
            options: $base->options,
        );
        self::assertNotSame(
            $renderer->buildCacheKey($base, 'injected'),
            $renderer->buildCacheKey($changed, 'injected'),
            'Rendered field changes must invalidate cache identity',
        );

        $edge = new ProductSceneContext(
            scene: 'list',
            productType: 'injected',
            product: ['sku' => 'I-2', 'name' => "\xB1\x31"],
            options: ['score' => INF],
        );
        self::assertStringStartsWith('product.scene.', $renderer->buildCacheKey($edge, 'injected'));
    }

    public function testHookRenderResultContract(): void
    {
        $emptyFallback = new HookRenderResult(html: '', handledEmpty: false, useFallback: true, fileCount: 1);
        self::assertTrue($emptyFallback->shouldUseFallback());

        $handled = new HookRenderResult(html: '', handledEmpty: true, useFallback: true, fileCount: 1);
        self::assertFalse($handled->shouldUseFallback());

        $ok = new HookRenderResult(html: '<div/>', handledEmpty: false, useFallback: false, fileCount: 2);
        self::assertFalse($ok->shouldUseFallback());
        self::assertSame(2, $ok->toArray()['file_count']);
    }

    private function providerWithRenderer(
        string $code,
        string $type,
        ProductRendererCapabilityInterface $rendererCap,
        bool $enabled = true,
    ): ProductProviderInterface {
        return new class ($code, $type, $rendererCap, $enabled) implements ProductProviderInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $type,
                private readonly ProductRendererCapabilityInterface $rendererCap,
                private readonly bool $enabled,
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
                return ['name', 'sku'];
            }

            public function getCapabilityMap(): array
            {
                return [
                    ProductPricingCapabilityInterface::class => true,
                    ProductInventoryCapabilityInterface::class => true,
                    ProductRendererCapabilityInterface::class => true,
                ];
            }

            public function getPricingCapability(): ?ProductPricingCapabilityInterface
            {
                return new DefaultProductPricingCapability();
            }

            public function getInventoryCapability(): ?ProductInventoryCapabilityInterface
            {
                return new DefaultProductInventoryCapability();
            }

            public function getRendererCapability(): ?ProductRendererCapabilityInterface
            {
                return $this->rendererCap;
            }

            public function getMetadata(): array
            {
                return ['code' => $this->code, 'type' => $this->type];
            }
        };
    }
}

final class EmptyBugRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(html: '');
    }
}

final class HandledEmptyRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(html: '', handledEmpty: true);
    }
}

final class ThrowingRenderer implements ProductSceneRendererInterface
{
    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        throw new \RuntimeException('boom');
    }
}

final class InjectedRendererDependency
{
    public function status(): string
    {
        return 'dependency-ready';
    }
}

final class InjectedRenderer implements ProductSceneRendererInterface
{
    public function __construct(
        private readonly InjectedRendererDependency $dependency,
    ) {
    }

    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        return new ProductSceneRenderResult(
            html: '<strong>' . $this->dependency->status() . '</strong>',
        );
    }
}
