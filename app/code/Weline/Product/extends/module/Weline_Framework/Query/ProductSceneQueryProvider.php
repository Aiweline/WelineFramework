<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Service\DefaultProductProvider;
use Weline\Product\Service\ProductProviderConflictException;
use Weline\Product\Service\ProductProviderRegistry;
use Weline\Product\Service\ProductSceneQueryHarnessCatalog;
use Weline\Product\Service\ProductSceneRenderer;

/**
 * 前台 Product SceneRenderer Facade（TEST-P2C-RENDER-01/02/03）。
 *
 * 仅用隔离 registry（forTesting），不污染生产 Extends registry。
 */
class ProductSceneQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'product_scene';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'configureHarness' => $this->configureHarness($params),
            'renderScene' => $this->renderScene($params),
            'tryRegisterDuplicate' => $this->tryRegisterDuplicate($params),
            'clearHarness' => $this->clearHarness(),
            default => throw new \InvalidArgumentException((string)__('商品场景接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function configureHarness(array $params): array
    {
        $existing = ProductSceneQueryHarnessCatalog::load() ?? [
            'providers' => [],
            'product' => [],
        ];
        if (array_key_exists('providers', $params) && is_array($params['providers'])) {
            $existing['providers'] = array_values($params['providers']);
        }
        if (array_key_exists('product', $params) && is_array($params['product'])) {
            $existing['product'] = $params['product'];
        }
        ProductSceneQueryHarnessCatalog::put($existing);

        return [
            'success' => true,
            'harness_active' => ProductSceneQueryHarnessCatalog::isActive(),
            'provider_count' => count($existing['providers']),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function renderScene(array $params): array
    {
        $stack = ProductSceneQueryHarnessCatalog::buildRendererStack();
        /** @var ProductSceneRenderer $renderer */
        $renderer = $stack['renderer'];
        $product = is_array($params['product'] ?? null)
            ? $params['product']
            : ProductSceneQueryHarnessCatalog::defaultProduct();
        $options = is_array($params['options'] ?? null) ? $params['options'] : [];
        $ctx = new ProductSceneContext(
            scene: (string)($params['scene'] ?? 'detail'),
            productType: (string)($params['product_type'] ?? 'simple'),
            websiteId: (int)($params['website_id'] ?? 0),
            storeId: (int)($params['store_id'] ?? 0),
            product: $product,
            options: $options,
        );
        $result = $renderer->render($ctx);
        $errors = $renderer->drainLoggedErrors();

        return [
            'success' => true,
            'result' => $result->toArray(),
            'logged_errors' => $errors,
            'harness_active' => ProductSceneQueryHarnessCatalog::isActive(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function tryRegisterDuplicate(array $params): array
    {
        $registry = ProductProviderRegistry::forTesting();
        $registry->register(new DefaultProductProvider());
        $code = trim((string)($params['code'] ?? 'default'));
        $type = trim((string)($params['type'] ?? 'simple'));
        try {
            $registry->register(ProductSceneQueryHarnessCatalog::buildProvider([
                'code' => $code !== '' ? $code : 'default',
                'type' => $type !== '' ? $type : 'simple',
                'renderer_mode' => ProductSceneQueryHarnessCatalog::MODE_NONE,
            ]));

            return [
                'success' => false,
                'duplicated' => false,
                'error_code' => null,
                'message' => (string)__('未触发重复注册'),
            ];
        } catch (ProductProviderConflictException $e) {
            return [
                'success' => true,
                'duplicated' => true,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'context' => $e->context(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clearHarness(): array
    {
        ProductSceneQueryHarnessCatalog::clear();

        return ['success' => true, 'cleaned' => true];
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Product',
            'summary' => 'Product SceneRenderer E2E harness (isolated registry)',
            'operations' => [
                [
                    'name' => 'configureHarness',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'providers' => ['type' => 'array', 'required' => false],
                        'product' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Configure Product SceneRenderer E2E harness providers/product',
                ],
                [
                    'name' => 'renderScene',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'scene' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'product_type' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'store_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'product' => ['type' => 'array', 'required' => false],
                        'options' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Render product scene via isolated harness registry',
                ],
                [
                    'name' => 'tryRegisterDuplicate',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'type' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Attempt duplicate provider register on isolated registry',
                ],
                [
                    'name' => 'clearHarness',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Clear Product SceneRenderer harness',
                ],
            ],
        ];
    }
}
