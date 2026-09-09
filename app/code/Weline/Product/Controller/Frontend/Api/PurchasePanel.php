<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend\Api;

use Weline\Cart\Api\CommerceTypeMembershipCheckerInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;

/**
 * Listing quick-add panel: slim product-info (gallery + specs + B2B) without full PDP.
 */
class PurchasePanel extends FrontendController
{
    public function index(): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $productId = max(0, (int)$this->request->getParam('product_id', 0));
        $slug = strtolower(trim((string)$this->request->getParam('slug', '')));
        $requestedOfferUuid = trim((string)$this->request->getParam('offer', ''));
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
                $response->setHttpResponseCode(404);

                return $this->encodeJson([
                    'success' => false,
                    'message' => (string)__('商品不存在或当前不可用。'),
                    'html' => '',
                    'identity' => $identity,
                ]);
            }

            $productIdForLabels = max(0, (int)($offers[0]['product_id'] ?? $productId));
            $labels = $variantLabels->forProduct($productIdForLabels);
            $axisQuery = $labels->canonicalizeAxisQuery(
                $this->request->getParams(),
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

            $this->assign('storefront_offer', $displayOffer);
            $this->assign('storefront_offers', $offers);
            $this->assign(
                'selected_offer_uuid',
                $selectedOffer === null ? '' : trim((string)($selectedOffer['global_offer_uuid'] ?? '')),
            );
            $this->assign('variant_catalog', $catalogData);
            $this->assign('quick_add', true);
            $this->assign('b2b_customer_logged_in', $identity['logged_in']);
            $this->assign('b2b_has_membership', $identity['has_membership']);
            $this->assign('b2b_customer_id', $identity['customer_id']);
            $this->assign('b2b_website_id', $identity['website_id']);
            StorefrontOfferResolver::rememberResolvedOffer($displayOffer);

            $html = (string)$this->fetch('Weline_Product::templates/frontend/widgets/product-info.phtml');

            return $this->encodeJson([
                'success' => true,
                'html' => $html,
                'product_id' => $productIdForLabels,
                'needs_selection' => count($offers) > 1,
                'identity' => $identity,
            ]);
        } catch (\Throwable $throwable) {
            $response->setHttpResponseCode(500);

            return $this->encodeJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'html' => '',
                'identity' => $identity,
            ]);
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
            $loggedIn = $this->isLoggedIn();
            $customerId = (int)($this->session->getUserId() ?? 0);
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
                if ($resolvedLabel !== '' && ($label === '' || $label === $value || $labelCorrupt)) {
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

    /** @param array<string, mixed> $data */
    private function encodeJson(array $data): string
    {
        $json = \json_encode($data, \JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
