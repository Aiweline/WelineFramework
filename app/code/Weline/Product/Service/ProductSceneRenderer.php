<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\Data\ProductSceneContext;
use Weline\Product\Api\Data\ProductSceneRenderResult;
use Weline\Product\Api\ProductSceneRendererInterface;

/**
 * Scene dispatcher：Provider capability → custom renderer / default templates（MOD-P2C-001）.
 *
 * Fallback 语义（TEST-P2C-RENDER-02）：
 * - 缺 custom / bug 空串 → 回默认模板
 * - handled_empty → 真真空，不再 fallback
 * - 异常 → 记录 error_code 并回默认（不抛穿前台）
 *
 * 安全（TEST-P2C-RENDER-03）：字段默认转义；模板路径仅场景白名单，禁止请求指定。
 */
final class ProductSceneRenderer
{
    public const ERROR_PROVIDER_DISABLED = 'product_provider_disabled';
    public const ERROR_PROVIDER_MISSING = 'product_provider_missing';
    public const ERROR_SCENE_UNSUPPORTED = 'product_scene_unsupported';
    public const ERROR_CUSTOM_EXCEPTION = 'product_renderer_exception';
    public const ERROR_CUSTOM_EMPTY = 'product_renderer_empty_bug';
    public const ERROR_TEMPLATE_PATH_REJECTED = 'product_template_path_rejected';
    private const ERROR_CONTEXT_KEY = 'product.scene_renderer.errors';

    /** @var array<string, true> */
    private const SCENE_WHITELIST = [
        ProductRendererCapabilityInterface::SCENE_LIST => true,
        ProductRendererCapabilityInterface::SCENE_DETAIL => true,
        ProductRendererCapabilityInterface::SCENE_CART => true,
        ProductRendererCapabilityInterface::SCENE_CHECKOUT => true,
        ProductRendererCapabilityInterface::SCENE_ORDER_SNAPSHOT => true,
    ];

    public function __construct(
        private readonly ProductProviderRegistry $registry,
    ) {
    }

    public static function forTesting(?ProductProviderRegistry $registry = null): self
    {
        return new self($registry ?? ProductProviderRegistry::forTesting([], autoEnsureDefault: true));
    }

    public function render(ProductSceneContext $context): ProductSceneRenderResult
    {
        $scene = trim($context->scene);
        if ($scene === '' || !isset(self::SCENE_WHITELIST[$scene])) {
            $this->recordError(self::ERROR_SCENE_UNSUPPORTED, $context);
            return $this->fallbackResult(
                $context,
                '',
                self::ERROR_SCENE_UNSUPPORTED,
                usedFallback: false,
            );
        }

        // Reject request-controlled template paths (TEST-P2C-RENDER-03)
        if (isset($context->options['template']) || isset($context->options['template_path'])) {
            $this->recordError(self::ERROR_TEMPLATE_PATH_REJECTED, $context);
            return $this->fallbackResult(
                $context,
                '',
                self::ERROR_TEMPLATE_PATH_REJECTED,
                usedFallback: true,
            );
        }

        $provider = $this->registry->getByType($context->productType, onlyEnabled: true);
        $providerResolutionError = null;
        if ($provider === null) {
            $disabled = $this->registry->getByType($context->productType, onlyEnabled: false);
            if ($disabled !== null && !$disabled->isEnabled()) {
                $providerResolutionError = self::ERROR_PROVIDER_DISABLED;
                $this->recordError($providerResolutionError, $context, $disabled->getCode());
            } else {
                $providerResolutionError = self::ERROR_PROVIDER_MISSING;
                $this->recordError($providerResolutionError, $context);
            }
            $provider = $this->registry->getByType('simple', onlyEnabled: true);
        }
        if ($provider === null) {
            return $this->fallbackResult(
                $context,
                '',
                $providerResolutionError ?? self::ERROR_PROVIDER_MISSING,
                true,
            );
        }

        $cap = $provider->getRendererCapability();
        $providerCode = $provider->getCode();
        $cacheKey = $this->buildCacheKey($context, $providerCode);

        if ($cap !== null && !$cap->supportsScene($scene)) {
            $this->recordError(self::ERROR_SCENE_UNSUPPORTED, $context, $providerCode);
            return $this->fallbackResult($context, $providerCode, self::ERROR_SCENE_UNSUPPORTED, true);
        }

        if ($cap !== null && $cap->hasCustomRenderer()) {
            $custom = $this->dispatchCustom($cap->getRendererClass(), $context);
            if ($custom === null || $custom->errorCode === self::ERROR_CUSTOM_EXCEPTION) {
                $html = $this->renderDefault($context);
                return new ProductSceneRenderResult(
                    html: $html,
                    handledEmpty: false,
                    usedFallback: true,
                    cacheKey: $cacheKey,
                    providerCode: $providerCode,
                    errorCode: self::ERROR_CUSTOM_EXCEPTION,
                );
            }
            if ($custom->handledEmpty) {
                return new ProductSceneRenderResult(
                    html: '',
                    handledEmpty: true,
                    usedFallback: false,
                    cacheKey: $cacheKey,
                    providerCode: $providerCode,
                );
            }
            if (!$custom->isEmpty()) {
                return new ProductSceneRenderResult(
                    html: $custom->html,
                    handledEmpty: false,
                    usedFallback: false,
                    cacheKey: $cacheKey,
                    providerCode: $providerCode,
                    errorCode: $custom->errorCode,
                );
            }
            // bug empty → default
            $this->recordError(self::ERROR_CUSTOM_EMPTY, $context, $providerCode);
            $html = $this->renderDefault($context);
            return new ProductSceneRenderResult(
                html: $html,
                handledEmpty: false,
                usedFallback: true,
                cacheKey: $cacheKey,
                providerCode: $providerCode,
                errorCode: self::ERROR_CUSTOM_EMPTY,
            );
        }

        $html = $this->renderDefault($context);
        return new ProductSceneRenderResult(
            html: $html,
            handledEmpty: false,
            usedFallback: $providerResolutionError !== null,
            cacheKey: $cacheKey,
            providerCode: $providerCode,
            errorCode: $providerResolutionError,
        );
    }

    /** @return list<string> */
    public function drainLoggedErrors(): array
    {
        $out = RequestContext::get(self::ERROR_CONTEXT_KEY, []);
        RequestContext::remove(self::ERROR_CONTEXT_KEY);
        if (!is_array($out)) {
            return [];
        }
        return $out;
    }

    public function buildCacheKey(ProductSceneContext $context, string $providerCode): string
    {
        $payload = [
            'scene' => trim($context->scene),
            'type' => trim($context->productType),
            'provider' => trim($providerCode),
            'website_id' => $context->websiteId,
            'store_id' => $context->storeId,
            'product' => $this->normalizeCacheValue($context->product),
            'options' => $this->normalizeCacheValue($context->options),
        ];
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR,
        );

        return 'product.scene.' . hash('sha256', $encoded);
    }

    private function dispatchCustom(string $class, ProductSceneContext $context): ?ProductSceneRenderResult
    {
        $class = trim($class);
        if ($class === '' || !class_exists($class)) {
            $this->recordError(self::ERROR_CUSTOM_EXCEPTION, $context);
            return null;
        }
        try {
            $reflection = new \ReflectionClass($class);
            $renderer = $reflection->getConstructor() === null
                ? $reflection->newInstance()
                : ObjectManager::make($class);
            if (!$renderer instanceof ProductSceneRendererInterface) {
                $this->recordError(self::ERROR_CUSTOM_EXCEPTION, $context);
                return null;
            }
            return $renderer->render($context);
        } catch (\Throwable) {
            $this->recordError(self::ERROR_CUSTOM_EXCEPTION, $context);
            return new ProductSceneRenderResult(
                html: '',
                handledEmpty: false,
                usedFallback: false,
                cacheKey: '',
                providerCode: '',
                errorCode: self::ERROR_CUSTOM_EXCEPTION,
            );
        }
    }

    private function fallbackResult(
        ProductSceneContext $context,
        string $providerCode,
        string $errorCode,
        bool $usedFallback,
    ): ProductSceneRenderResult {
        $html = $usedFallback ? $this->renderDefault($context) : '';
        return new ProductSceneRenderResult(
            html: $html,
            handledEmpty: false,
            usedFallback: $usedFallback,
            cacheKey: $this->buildCacheKey($context, $providerCode),
            providerCode: $providerCode,
            errorCode: $errorCode,
        );
    }

    private function renderDefault(ProductSceneContext $context): string
    {
        $p = $context->product;
        $name = $this->esc((string)($p['name'] ?? ''));
        $sku = $this->esc((string)($p['sku'] ?? ''));
        $desc = $this->esc((string)($p['description'] ?? ''));
        $price = $this->esc((string)($p['price_label'] ?? ''));
        $scene = $this->esc($context->scene);
        $type = $this->esc($context->productType);

        return match ($context->scene) {
            ProductRendererCapabilityInterface::SCENE_LIST =>
                '<div class="w-product w-product--list" data-scene="' . $scene . '" data-type="' . $type . '">'
                . '<span class="w-product__name">' . $name . '</span>'
                . ($price !== '' ? '<span class="w-product__price">' . $price . '</span>' : '')
                . '</div>',
            ProductRendererCapabilityInterface::SCENE_CART,
            ProductRendererCapabilityInterface::SCENE_CHECKOUT =>
                '<div class="w-product w-product--' . $scene . '" data-sku="' . $sku . '">'
                . '<span class="w-product__name">' . $name . '</span>'
                . ($price !== '' ? '<span class="w-product__price">' . $price . '</span>' : '')
                . '</div>',
            ProductRendererCapabilityInterface::SCENE_ORDER_SNAPSHOT =>
                '<div class="w-product w-product--order-snapshot" data-sku="' . $sku . '">'
                . '<span class="w-product__name">' . $name . '</span>'
                . ($sku !== '' ? '<span class="w-product__sku">' . $sku . '</span>' : '')
                . ($price !== '' ? '<span class="w-product__price">' . $price . '</span>' : '')
                . '</div>',
            ProductRendererCapabilityInterface::SCENE_DETAIL =>
                '<article class="w-product w-product--detail" data-scene="' . $scene . '" data-type="' . $type . '">'
                . '<h1 class="w-product__name">' . $name . '</h1>'
                . ($sku !== '' ? '<p class="w-product__sku">' . $sku . '</p>' : '')
                . ($price !== '' ? '<p class="w-product__price">' . $price . '</p>' : '')
                . ($desc !== '' ? '<div class="w-product__description">' . $desc . '</div>' : '')
                . '</article>',
            default => '',
        };
    }

    private function recordError(
        string $errorCode,
        ProductSceneContext $context,
        string $providerCode = '',
    ): void {
        $loggedErrors = RequestContext::get(self::ERROR_CONTEXT_KEY, []);
        if (!is_array($loggedErrors)) {
            $loggedErrors = [];
        }
        if (count($loggedErrors) >= 64) {
            array_shift($loggedErrors);
        }
        $loggedErrors[] = $errorCode;
        RequestContext::set(self::ERROR_CONTEXT_KEY, $loggedErrors);

        if (\function_exists('w_log_warning')) {
            \w_log_warning('[ProductSceneRenderer] ' . $errorCode, [
                'scene' => trim($context->scene),
                'product_type' => trim($context->productType),
                'provider_code' => trim($providerCode),
                'website_id' => $context->websiteId,
                'store_id' => $context->storeId,
            ], 'product_scene_renderer');
        }
    }

    private function normalizeCacheValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) && !is_finite($value)) {
                return ['@float' => match (true) {
                    is_nan($value) => 'nan',
                    $value > 0 => 'infinity',
                    default => '-infinity',
                }];
            }
            if ($value instanceof \DateTimeInterface) {
                return ['@datetime' => $value->format(\DateTimeInterface::ATOM)];
            }
            if ($value instanceof \Stringable) {
                return ['@stringable' => $value::class, 'value' => (string)$value];
            }
            if (is_object($value)) {
                return ['@object' => $value::class];
            }
            if (is_resource($value)) {
                return ['@resource' => get_resource_type($value)];
            }
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeCacheValue($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string)$key] = $this->normalizeCacheValue($item);
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
