<?php

declare(strict_types=1);

namespace Weline\Product\Api\Storefront;

/**
 * Optional Shipping (or other) catalog of bindable shipping profiles for Offer admin + PDP hints.
 */
interface StorefrontShippingProfileCatalogProviderInterface
{
    public function getCode(): string;

    /**
     * @return list<array{
     *   code:string,
     *   label:string,
     *   is_free_shipping:bool,
     *   estimated_days_min?:int|null,
     *   estimated_days_max?:int|null
     * }>
     */
    public function listActiveProfiles(): array;

    /**
     * @return array{badge:?string,note:string}|null
     */
    public function previewHint(string $profileCode, bool $requiresShipping): ?array;
}
