<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Cart\Api\CommerceTypeMembershipCheckerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Template;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\ProductLayoutResolveService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;

/**
 * Listing quick-add panel HTML (gallery + specs + B2B) without full PDP.
 * Shared by BinQuery {@see \Weline\Product\Extends\Module\Weline_Framework\Query\ProductQueryProvider}
 * and the thin REST shell {@see \Weline\Product\Controller\Frontend\Api\PurchasePanel}.
 */
final class PurchasePanelService
{
    /**
     * @param array<string, mixed> $params product_id|slug|offer plus optional axis query keys
     * @return array{
     *   success:bool,
     *   message?:string,
     *   html:string,
     *   product_id?:int,
     *   needs_selection?:bool,
     *   identity:array{logged_in:bool,has_membership:bool,customer_id:int,website_id:int},
     *   http_status:int
     * }
     */
    public function render(array $params): array
    {
        $productId = max(0, (int)($params['product_id'] ?? 0));
        $slug = strtolower(trim((string)($params['slug'] ?? '')));
        $requestedOfferUuid = trim((string)($params['offer'] ?? ''));
        $identity = $this->resolveB2bIdentity();

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            /** @var StorefrontVariantSelectionService $variantSelection */
            $variantSelection = ObjectManager::getInstance(StorefrontVariantSelectionService::class);
            /** @var StorefrontEavLabelResolver $variantLabels */
            $variantLabels = ObjectManager::getInstance(StorefrontEavLabelResolver::class);

            $offers = [];
            if ($slug !== '') {
                $offers = $catalog->publishedOffersBySlug($slug);
            } elseif ($productId > 0) {
                $offers = $catalog->publishedOffersForProduct($productId);
            }
            if ($offers === []) {
                return [
                    'success' => false,
                    'message' => (string)__('商品不存在或当前不可用。'),
                    'html' => '',
                    'identity' => $identity,
                    'http_status' => 404,
                ];
            }

            $productIdForLabels = max(0, (int)($offers[0]['product_id'] ?? $productId));
            $labels = $variantLabels->forProduct($productIdForLabels);
            $axisQuery = $labels->canonicalizeAxisQuery(
                $params,
                $variantSelection->collectAxisCodes($offers),
            );
            if ($requestedOfferUuid !== '') {
                $axisQuery['offer'] = $requestedOfferUuid;
            }
            $selectedOffer = $variantSelection->resolveSelectedOffer($offers, $axisQuery);
            $displayOffer = $selectedOffer ?? $offers[0];
            $requiresExplicitSelection = count($offers) > 1 && $selectedOffer === null && $requestedOfferUuid === '';
            if ($requiresExplicitSelection) {
                $displayOffer['global_offer_uuid'] = '';
                $displayOffer['sellable'] = false;
                $displayOffer['selection_required'] = true;
                $displayOffer['message'] = (string)__('请选择规格后再加入购物车。');
            } else {
                $displayOffer['selection_required'] = false;
            }

            $catalogData = $variantSelection->compactCatalogMedia(
                $this->enrichCatalogOptionCodes(
                    $variantSelection->buildCatalog($offers, $displayOffer),
                    $labels,
                ),
            );

            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $template->assign([
                'storefront_offer' => $displayOffer,
                'storefront_offers' => $offers,
                'selected_offer_uuid' => $selectedOffer === null
                    ? ''
                    : trim((string)($selectedOffer['global_offer_uuid'] ?? '')),
                'variant_catalog' => $catalogData,
                'quick_add' => true,
                'b2b_customer_logged_in' => $identity['logged_in'],
                'b2b_has_membership' => $identity['has_membership'],
                'b2b_customer_id' => $identity['customer_id'],
                'b2b_website_id' => $identity['website_id'],
            ]);
            StorefrontOfferResolver::rememberResolvedOffer($displayOffer);

            $html = $this->renderPanelTemplate($template, $displayOffer);

            return [
                'success' => true,
                'html' => $html,
                'product_id' => $productIdForLabels,
                'needs_selection' => count($offers) > 1,
                'identity' => $identity,
                'http_status' => 200,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'html' => '',
                'identity' => $identity,
                'http_status' => 500,
            ];
        }
    }

    /** A BinQuery fragment has no page controller/after-fetch pass to fill its nested slots. */
    private function renderPanelTemplate(Template $template, array $offer): string
    {
        $themeId = (int)RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_THEME_ID, 0);
        if ($themeId < 1) {
            $themeId = (int)(ThemeData::getCurrentTheme()?->getId() ?? 0);
        }
        $installed = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
        $scopeIdentity = RequestContext::scopeIdentity();
        $scope = $scopeIdentity !== null
            ? ObjectManager::getInstance(ThemeLayoutScopeNormalizer::class)->normalize(['scope_identity' => $scopeIdentity])['scope']
            : ($installed instanceof LayoutIdentity ? $installed->scope : 'default');
        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $websiteId = max(0, (int)($offer['website_id'] ?? RequestContext::getWelineWebsiteId()));
        $categoryIds = [];
        foreach (['category_id', 'primary_category_id', 'main_category_id'] as $key) {
            if ((int)($offer[$key] ?? 0) > 0) {
                $categoryIds[(int)$offer[$key]] = (int)$offer[$key];
            }
        }
        foreach (ObjectManager::getInstance(CategoryLinkRepository::class)->listByProductIds($websiteId, [$productId], [0]) as $row) {
            if ((int)($row['category_id'] ?? 0) > 0 && (int)($row['selected'] ?? 1) === 1) {
                $categoryIds[(int)$row['category_id']] = (int)$row['category_id'];
            }
        }
        $layout = ObjectManager::getInstance(ProductLayoutResolveService::class)->resolveForProduct(
            $productId, array_values($categoryIds), $scope, null, null, $websiteId,
        );
        $identity = new LayoutIdentity(
            (string)($layout['layout_option'] ?? 'default'), $scope,
            (string)($layout['target_type'] ?? 'global'), (int)($layout['target_id'] ?? 0),
        );
        $temporary = [
            LayoutIdentity::REQUEST_CONTEXT_KEY => $identity,
            ThemeLayoutEntityPublishedSlotHost::CTX_THEME_ID => $themeId,
            ThemeLayoutEntityPublishedSlotHost::CTX_LAYOUT_TYPE => 'product',
            ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE => true,
            ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING => false,
            ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS => null,
            'theme.layout_entity.preview_entity' => null,
            'theme.layout_entity.rendered_chrome_binding' => null,
            'theme.layout_entity.rendered_chrome_bindings' => [],
        ];
        $previous = [];
        foreach ($temporary as $key => $value) {
            $previous[$key] = [RequestContext::has($key), RequestContext::get($key)];
            RequestContext::set($key, $value);
        }
        try {
            $html = (string)$template->fetch('Weline_Product::templates/frontend/widgets/product-info.phtml');
            return ObjectManager::getInstance(SlotRendererService::class)->processSlots(
                $html, $themeId, 'product', 'published', 'frontend',
            );
        } finally {
            foreach ($previous as $key => [$exists, $value]) {
                $exists ? RequestContext::set($key, $value) : RequestContext::remove($key);
            }
        }
    }

    /**
     * @return array{logged_in:bool,has_membership:bool,customer_id:int,website_id:int}
     */
    private function resolveB2bIdentity(): array
    {
        $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
        $loggedIn = false;
        $customerId = 0;
        try {
            /** @var SessionFactory $sessionFactory */
            $sessionFactory = ObjectManager::getInstance(SessionFactory::class);
            $session = $sessionFactory->createFrontendSession();
            $loggedIn = $session->isLoggedIn();
            $customerId = (int)($session->getUserId() ?? 0);
        } catch (\Throwable) {
        }
        $hasMembership = false;
        if ($loggedIn && $customerId > 0) {
            try {
                /** @var CommerceTypeMembershipCheckerInterface $checker */
                $checker = ObjectManager::getInstance(CommerceTypeMembershipCheckerInterface::class);
                $hasMembership = $checker->hasMembership('tob', $customerId, $websiteId);
            } catch (\Throwable) {
                $hasMembership = false;
            }
        }

        return [
            'logged_in' => $loggedIn && $customerId > 0,
            'has_membership' => $hasMembership,
            'customer_id' => max(0, $customerId),
            'website_id' => $websiteId,
        ];
    }

    /**
     * @param array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>} $catalog
     * @return array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>}
     */
    private function enrichCatalogOptionCodes(array $catalog, StorefrontEavLabelResolver $variantLabels): array
    {
        foreach ($catalog['axes'] as $axisIndex => $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
            if ($axisCode === '') {
                continue;
            }
            $options = [];
            foreach ((array)($axis['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $value = trim((string)($option['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = trim((string)($option['label'] ?? ''));
                $resolvedLabel = trim($variantLabels->resolve($axisCode, $value));
                $labelCorrupt = $label !== '' && preg_match('/%[0-9A-Fa-f]{2}/', $label) === 1;
                $resolvedIsLocal = $resolvedLabel !== '' && strcasecmp($resolvedLabel, $value) !== 0;
                if ($resolvedLabel !== '' && ($label === '' || $label === $value || $labelCorrupt || $resolvedIsLocal)) {
                    $option['label'] = $resolvedLabel;
                }
                if (trim((string)($option['code'] ?? '')) === '') {
                    $option['code'] = $variantLabels->publicOptionCode($axisCode, $value);
                }
                $options[] = $option;
            }
            $catalog['axes'][$axisIndex]['options'] = $options;
        }

        return $catalog;
    }
}
