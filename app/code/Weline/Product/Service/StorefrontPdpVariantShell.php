<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Neutral PDP / purchase-panel shell for multi-offer products.
 *
 * Path-only FPC: SSR never binds size/style_type/offer query into the selected
 * buy-box. Full catalog ships in HTML; the browser applies in-page selection.
 */
final class StorefrontPdpVariantShell
{
    public function __construct(
        private readonly StorefrontVariantSelectionService $variantSelection,
        private readonly StorefrontEavLabelResolver $variantLabels,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array{
     *   display_offer: array<string, mixed>,
     *   selected_offer: null,
     *   selected_offer_uuid: string,
     *   variant_catalog: array<string, mixed>,
     *   selection_required: bool
     * }
     */
    public function buildNeutral(array $offers): array
    {
        if ($offers === []) {
            return [
                'display_offer' => [],
                'selected_offer' => null,
                'selected_offer_uuid' => '',
                'variant_catalog' => ['axes' => [], 'offers' => [], 'selected' => []],
                'selection_required' => false,
            ];
        }

        $shell = $this->pickShellOffer($offers);
        $productId = max(0, (int)($shell['product_id'] ?? 0));
        $labels = $this->variantLabels->forProduct($productId);
        $multi = count($offers) > 1;
        $display = $shell;
        if ($multi) {
            $display['global_offer_uuid'] = '';
            $display['sellable'] = false;
            $display['selection_required'] = true;
            $display['message'] = (string)__('请选择规格后再加入购物车。');
        } else {
            $display['selection_required'] = false;
        }

        $catalog = $this->variantSelection->compactCatalogMedia(
            $this->enrichCatalogOptionCodes(
                $this->variantSelection->buildCatalog($offers, null),
                $labels,
            ),
        );

        return [
            'display_offer' => $display,
            'selected_offer' => null,
            'selected_offer_uuid' => '',
            'variant_catalog' => $catalog,
            'selection_required' => $multi,
        ];
    }

    /**
     * Quick-add / API may still pin an offer UUID without rewriting the public URL.
     *
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $params
     * @return array{
     *   display_offer: array<string, mixed>,
     *   selected_offer: ?array<string, mixed>,
     *   selected_offer_uuid: string,
     *   variant_catalog: array<string, mixed>,
     *   selection_required: bool
     * }
     */
    public function buildForInteractivePanel(array $offers, array $params): array
    {
        $requestedOfferUuid = trim((string)($params['offer'] ?? ''));
        if ($requestedOfferUuid === '' && !$this->paramsHaveAxisKeys($offers, $params)) {
            return $this->buildNeutral($offers);
        }

        $productId = max(0, (int)($offers[0]['product_id'] ?? 0));
        $labels = $this->variantLabels->forProduct($productId);
        $axisQuery = $labels->canonicalizeAxisQuery(
            $params,
            $this->variantSelection->collectAxisCodes($offers),
        );
        if ($requestedOfferUuid !== '') {
            $axisQuery['offer'] = $requestedOfferUuid;
        }
        $selectedOffer = $this->variantSelection->resolveSelectedOffer($offers, $axisQuery);
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

        $catalog = $this->variantSelection->compactCatalogMedia(
            $this->enrichCatalogOptionCodes(
                $this->variantSelection->buildCatalog($offers, $selectedOffer),
                $labels,
            ),
        );

        return [
            'display_offer' => $displayOffer,
            'selected_offer' => $selectedOffer,
            'selected_offer_uuid' => $selectedOffer === null
                ? ''
                : trim((string)($selectedOffer['global_offer_uuid'] ?? '')),
            'variant_catalog' => $catalog,
            'selection_required' => $requiresExplicitSelection,
        ];
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array<string, mixed>
     */
    private function pickShellOffer(array $offers): array
    {
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            if (!empty($offer['is_default'])) {
                return $offer;
            }
        }

        return $offers[0];
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $params
     */
    private function paramsHaveAxisKeys(array $offers, array $params): bool
    {
        foreach ($this->variantSelection->collectAxisCodes($offers) as $axisCode) {
            if (trim((string)($params[$axisCode] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{axes?:list<array<string,mixed>>,offers?:list<array<string,mixed>>,selected?:array<string,string>} $catalog
     * @return array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>}
     */
    private function enrichCatalogOptionCodes(array $catalog, StorefrontEavLabelResolver $labels): array
    {
        foreach ($catalog['axes'] ?? [] as $axisIndex => $axis) {
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
                $resolvedLabel = trim($labels->resolve($axisCode, $value));
                $resolvedIsLocal = $resolvedLabel !== '' && strcasecmp($resolvedLabel, $value) !== 0;
                if ($resolvedLabel !== '' && ($label === '' || $label === $value || $resolvedIsLocal)) {
                    $option['label'] = $resolvedLabel;
                }
                if (trim((string)($option['code'] ?? '')) === '') {
                    $option['code'] = $labels->publicOptionCode($axisCode, $value);
                }
                $options[] = $option;
            }
            $catalog['axes'][$axisIndex]['options'] = $options;
        }

        return $catalog;
    }
}
