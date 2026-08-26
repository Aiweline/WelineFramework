<?php

declare(strict_types=1);

namespace Weline\Subscription\Extends\Module\Weline_Product\ProductProvider;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;
use Weline\Product\Api\Capability\ProductPricingCapabilityInterface;
use Weline\Product\Api\Capability\ProductRendererCapabilityInterface;
use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionProviderRegistry;

/**
 * Product V2 extension owned by Subscription.
 *
 * Each Product Offer maps to one immutable Subscription provider_code/plan_code
 * pair. Product remains the catalog/price owner; recurring billing stays here.
 */
final class SubscriptionProductProvider implements
    ProductProviderV2Interface,
    ProductPricingCapabilityInterface,
    ProductRendererCapabilityInterface
{
    private const CODE = 'subscription';

    private readonly SubscriptionProviderRegistry $subscriptionProviders;

    /** @var list<string> */
    private readonly array $currencies;

    /** @param list<string> $currencies */
    public function __construct(
        ?SubscriptionProviderRegistry $subscriptionProviders = null,
        private readonly bool $enabled = true,
        array $currencies = ['CNY', 'USD'],
    ) {
        $this->subscriptionProviders = $subscriptionProviders ?? new SubscriptionProviderRegistry();
        $normalized = [];
        foreach ($currencies as $currency) {
            $currency = strtoupper(trim((string)$currency));
            if (preg_match('/^[A-Z]{3}$/D', $currency) === 1) {
                $normalized[$currency] = true;
            }
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('subscription_product_currency_required');
        }
        $this->currencies = array_keys($normalized);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getType(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('订阅商品');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSortOrder(): int
    {
        return 600;
    }

    public function getRequiredAttributes(): array
    {
        return ['name', 'sku', 'price', 'subscription_plan'];
    }

    public function getCapabilityMap(): array
    {
        return [
            ProductPricingCapabilityInterface::class => true,
            ProductInventoryCapabilityInterface::class => false,
            ProductRendererCapabilityInterface::class => true,
        ];
    }

    public function getPricingCapability(): ?ProductPricingCapabilityInterface
    {
        return $this;
    }

    public function getInventoryCapability(): ?ProductInventoryCapabilityInterface
    {
        return null;
    }

    public function getRendererCapability(): ?ProductRendererCapabilityInterface
    {
        return $this;
    }

    public function getDefinition(): ProductTypeDefinition
    {
        return new ProductTypeDefinition(
            code: self::CODE,
            label: $this->getLabel(),
            minimumOffers: 1,
            maximumOffers: null,
            formSchema: [
                'sections' => [
                    'overview',
                    'basic',
                    'attributes',
                    'offer_price',
                    'subscription',
                    'categories',
                    'media',
                    'stores',
                    'diagnostics',
                    'audit',
                ],
                'fields' => [
                    'plans' => [
                        'path' => 'type_configuration.plans',
                        'type' => 'offer_matrix',
                        'label' => (string)__('订阅方案'),
                        'required' => true,
                        'columns' => [
                            'global_offer_uuid' => 'Offer',
                            'provider_code' => (string)__('计费 Provider'),
                            'plan_code' => (string)__('方案代码'),
                        ],
                    ],
                ],
                'default_offer' => true,
            ],
            requiredProductAttributes: ['name'],
            requiredOfferAttributes: ['sku', 'price', 'subscription_plan'],
            supportsVariants: false,
            supportsPricing: true,
            tracksInventory: false,
            requiresShipping: false,
            supportsDigitalDelivery: false,
            supportsComposition: false,
        );
    }

    public function validateForPublish(ProductValidationContext $context): ProductValidationResult
    {
        $errors = [];
        $offers = $context->offers;
        $stores = $context->storeIds === [] ? [0] : array_values(array_map('intval', $context->storeIds));

        if ($offers === []) {
            $errors[] = $this->issue(
                'subscription_offer_cardinality_invalid',
                (string)__('订阅商品至少需要一个 Offer'),
                'offers',
            );
        }

        foreach ($stores as $storeId) {
            $resolution = $context->attributeResolution('name', $storeId);
            $name = $resolution['found']
                ? $resolution['value']
                : ($context->product['name'] ?? null);
            if (!is_string($name) || trim($name) === '') {
                $errors[] = $this->issue(
                    'product_name_required',
                    (string)__('订阅商品名称不能为空'),
                    'attributes.name',
                    '',
                    $storeId,
                );
            }
        }

        $planRows = $context->typeConfiguration['plans'] ?? [];
        $plans = [];
        foreach (is_array($planRows) ? $planRows : [] as $planIndex => $plan) {
            $offerUuid = is_array($plan)
                ? strtolower(trim((string)($plan['global_offer_uuid'] ?? '')))
                : '';
            if ($offerUuid === ''
                || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $offerUuid) !== 1
                || isset($plans[$offerUuid])
            ) {
                $errors[] = $this->issue(
                    'subscription_plan_offer_mapping_invalid',
                    (string)__('订阅方案 Offer 映射无效'),
                    'type_configuration.plans.' . $planIndex,
                    $offerUuid,
                );
                continue;
            }
            $plans[$offerUuid] = $plan;
        }

        $knownOffers = [];
        $seenPlans = [];
        foreach ($offers as $offerIndex => $offer) {
            $offerUuid = strtolower(trim((string)($offer['global_offer_uuid'] ?? '')));
            if ($offerUuid !== '') {
                $knownOffers[$offerUuid] = true;
            }
            $sku = trim((string)($offer['sku'] ?? ''));
            if ($sku === '') {
                $errors[] = $this->issue(
                    'offer_sku_required',
                    (string)__('订阅 Offer 必须有 SKU'),
                    'offers.' . $offerIndex . '.sku',
                    $offerUuid,
                );
            }
            if ((bool)($offer['requires_shipping'] ?? false)) {
                $errors[] = $this->issue(
                    'subscription_shipping_not_supported',
                    (string)__('订阅商品不能要求配送'),
                    'offers.' . $offerIndex . '.requires_shipping',
                    $offerUuid,
                );
            }
            foreach ($stores as $storeId) {
                if (!$this->hasBasePrice($context, $offer, $storeId)) {
                    $errors[] = $this->issue(
                        'offer_price_required',
                        (string)__('订阅商品必须配置 Website/Store 基础价；显式零价有效'),
                        'offers.' . $offerIndex . '.prices',
                        $offerUuid,
                        $storeId,
                    );
                }
            }

            $plan = $plans[$offerUuid] ?? null;
            if (!is_array($plan)) {
                $errors[] = $this->issue(
                    'subscription_plan_configuration_required',
                    (string)__('每个订阅 Offer 必须绑定一个计费方案'),
                    'type_configuration.plans',
                    $offerUuid,
                );
                continue;
            }
            $providerCode = trim((string)($plan['provider_code'] ?? ''));
            $planCode = trim((string)($plan['plan_code'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $providerCode) !== 1
                || $planCode === ''
                || strlen($planCode) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $planCode) === 1
            ) {
                $errors[] = $this->issue(
                    'subscription_plan_identity_invalid',
                    (string)__('订阅计费 Provider 或方案代码无效'),
                    'type_configuration.plans',
                    $offerUuid,
                );
                continue;
            }
            try {
                $this->subscriptionProviders->get($providerCode);
            } catch (SubscriptionConflictException) {
                $errors[] = $this->issue(
                    'subscription_plan_provider_unavailable',
                    (string)__('订阅计费 Provider 不可用：%{1}', [$providerCode]),
                    'type_configuration.plans',
                    $offerUuid,
                );
                continue;
            }
            $planIdentity = strtolower($providerCode . '|' . $planCode);
            if (isset($seenPlans[$planIdentity])) {
                $errors[] = $this->issue(
                    'subscription_plan_duplicate',
                    (string)__('同一订阅计费方案不能绑定多个 Offer'),
                    'type_configuration.plans',
                    $offerUuid,
                );
            }
            $seenPlans[$planIdentity] = true;
        }

        foreach (array_keys($plans) as $offerUuid) {
            if (!isset($knownOffers[$offerUuid])) {
                $errors[] = $this->issue(
                    'subscription_plan_offer_unknown',
                    (string)__('订阅方案引用了不存在的 Offer'),
                    'type_configuration.plans',
                    $offerUuid,
                );
            }
        }

        return new ProductValidationResult(errors: $errors);
    }

    public function getMetadata(): array
    {
        return [
            'code' => $this->getCode(),
            'type' => $this->getType(),
            'label' => $this->getLabel(),
            'enabled' => $this->isEnabled(),
            'sort_order' => $this->getSortOrder(),
            'required_attributes' => $this->getRequiredAttributes(),
            'capabilities' => array_keys(array_filter($this->getCapabilityMap())),
            'definition' => $this->getDefinition()->toArray(),
            'subscription_provider_codes' => $this->subscriptionProviders->codes(),
            'pricing' => [
                'currencies' => $this->supportedCurrencies(),
                'allows_cleared' => $this->allowsClearedPrice(),
            ],
            'renderer' => [
                'scenes' => $this->supportedScenes(),
                'has_custom' => false,
                'renderer_class' => '',
            ],
        ];
    }

    public function supportedCurrencies(): array
    {
        return $this->currencies;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), $this->currencies, true);
    }

    public function allowsClearedPrice(): bool
    {
        return false;
    }

    public function supportedScenes(): array
    {
        return [
            self::SCENE_LIST,
            self::SCENE_DETAIL,
            self::SCENE_CART,
            self::SCENE_CHECKOUT,
            self::SCENE_ORDER_SNAPSHOT,
        ];
    }

    public function supportsScene(string $scene): bool
    {
        return in_array(trim($scene), $this->supportedScenes(), true);
    }

    public function hasCustomRenderer(): bool
    {
        return false;
    }

    public function getRendererClass(): string
    {
        return '';
    }

    /** @param array<string,mixed> $offer */
    private function hasBasePrice(
        ProductValidationContext $context,
        array $offer,
        int $storeId,
    ): bool {
        $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        $offerId = (int)($offer['offer_id'] ?? 0);
        $currency = $context->currencyCode();
        $prices = array_values(array_filter(
            $context->prices,
            static function (array $price) use ($offerUuid, $offerId, $currency): bool {
                $matchesOffer = ($offerUuid !== ''
                        && (string)($price['global_offer_uuid'] ?? '') === $offerUuid)
                    || ($offerId > 0 && (int)($price['offer_id'] ?? 0) === $offerId);
                $priceCurrency = strtoupper(trim((string)($price['currency'] ?? ''))) ?: $currency;
                return $matchesOffer && $priceCurrency === $currency;
            },
        ));
        $scoped = array_values(array_filter(
            $prices,
            static fn(array $price): bool => (int)($price['store_id'] ?? 0) === $storeId,
        ));
        if ($storeId > 0 && $scoped === []) {
            $scoped = array_values(array_filter(
                $prices,
                static fn(array $price): bool => (int)($price['store_id'] ?? 0) === 0,
            ));
        }

        foreach ($scoped as $price) {
            if (($price['scope_state'] ?? '') === 'cleared'
                || (int)($price['cleared'] ?? 0) === 1
            ) {
                return false;
            }
            if (array_key_exists('amount_minor', $price) && $price['amount_minor'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{code:string,message:string,path:string,store_id?:int,offer_uuid?:string}
     */
    private function issue(
        string $code,
        string $message,
        string $path,
        string $offerUuid = '',
        ?int $storeId = null,
    ): array {
        $issue = ['code' => $code, 'message' => $message, 'path' => $path];
        if ($storeId !== null) {
            $issue['store_id'] = $storeId;
        }
        if ($offerUuid !== '') {
            $issue['offer_uuid'] = $offerUuid;
        }
        return $issue;
    }
}
