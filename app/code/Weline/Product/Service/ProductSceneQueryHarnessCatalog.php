<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\ProductProviderInterface;
use Weline\Product\Service\Capability\DefaultProductInventoryCapability;
use Weline\Product\Service\Capability\DefaultProductPricingCapability;
use Weline\Product\Service\Capability\DefaultProductRendererCapability;
use Weline\Product\Service\Harness\EmptyBugHarnessRenderer;
use Weline\Product\Service\Harness\HandledEmptyHarnessRenderer;
use Weline\Product\Service\Harness\InjectedHarnessRenderer;
use Weline\Product\Service\Harness\ThrowingHarnessRenderer;

/**
 * E2E / DEV Product SceneRenderer 夹具（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * @phpstan-type ProviderSpec array{
 *   code:string,
 *   type:string,
 *   enabled?:bool,
 *   renderer_mode?:string
 * }
 * @phpstan-type HarnessState array{
 *   providers: list<ProviderSpec>,
 *   product: array<string, mixed>
 * }
 */
final class ProductSceneQueryHarnessCatalog
{
    private const FILE = 'state.json';

    public const MODE_NONE = 'none';
    public const MODE_MISSING_CLASS = 'missing_class';
    public const MODE_EMPTY_BUG = 'empty_bug';
    public const MODE_HANDLED_EMPTY = 'handled_empty';
    public const MODE_THROW = 'throw';
    public const MODE_INJECTED = 'injected';

    /**
     * @param HarnessState $state
     */
    public static function put(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create product_scene_query_harness dir');
        }
        $providers = [];
        foreach (is_array($state['providers'] ?? null) ? $state['providers'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            $type = trim((string)($row['type'] ?? ''));
            if ($code === '' || $type === '') {
                continue;
            }
            $providers[] = [
                'code' => $code,
                'type' => $type,
                'enabled' => array_key_exists('enabled', $row) ? (bool)$row['enabled'] : true,
                'renderer_mode' => (string)($row['renderer_mode'] ?? self::MODE_NONE),
            ];
        }
        $payload = [
            'providers' => $providers,
            'product' => is_array($state['product'] ?? null) ? $state['product'] : [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write product_scene_query_harness');
        }
    }

    /**
     * @return HarnessState|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'providers' => array_values(is_array($decoded['providers'] ?? null) ? $decoded['providers'] : []),
            'product' => is_array($decoded['product'] ?? null) ? $decoded['product'] : [],
        ];
    }

    public static function isActive(): bool
    {
        return is_file(self::path());
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 构建隔离 registry + renderer（不污染生产 Extends registry）。
     *
     * @return array{registry: ProductProviderRegistry, renderer: ProductSceneRenderer}
     */
    public static function buildRendererStack(): array
    {
        $state = self::load() ?? ['providers' => [], 'product' => []];
        $registry = ProductProviderRegistry::forTesting([], autoEnsureDefault: true);
        foreach ($state['providers'] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $registry->register(self::buildProvider($spec));
        }

        return [
            'registry' => $registry,
            'renderer' => new ProductSceneRenderer($registry),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function buildProvider(array $spec): ProductProviderInterface
    {
        $code = trim((string)($spec['code'] ?? ''));
        $type = trim((string)($spec['type'] ?? ''));
        $enabled = array_key_exists('enabled', $spec) ? (bool)$spec['enabled'] : true;
        $mode = (string)($spec['renderer_mode'] ?? self::MODE_NONE);
        $rendererClass = match ($mode) {
            self::MODE_MISSING_CLASS => '\\Does\\Not\\Exist\\HarnessRenderer',
            self::MODE_EMPTY_BUG => EmptyBugHarnessRenderer::class,
            self::MODE_HANDLED_EMPTY => HandledEmptyHarnessRenderer::class,
            self::MODE_THROW => ThrowingHarnessRenderer::class,
            self::MODE_INJECTED => InjectedHarnessRenderer::class,
            default => '',
        };
        $cap = new DefaultProductRendererCapability(rendererClass: $rendererClass);

        return new class ($code, $type, $cap, $enabled) implements ProductProviderInterface {
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
                return ['code' => $this->code, 'type' => $this->type, 'harness' => true];
            }
        };
    }

    /** @return array<string, mixed> */
    public static function defaultProduct(): array
    {
        $state = self::load();
        $product = is_array($state['product'] ?? null) ? $state['product'] : [];

        return $product !== [] ? $product : [
            'name' => 'Harness Demo',
            'sku' => 'HARNESS-1',
            'description' => 'Harness product',
            'price_label' => '¥10',
        ];
    }

    private static function dir(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'product_scene_query_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
