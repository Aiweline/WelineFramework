<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Repository\OfferRepository;

/**
 * Resolve whether HelpPay quick-buy lines need physical shipping.
 *
 * Offer.requires_shipping is authoritative when a product_id can be resolved.
 * Never special-case product_type (e.g. travel_route) in HelpPay core.
 */
final class HelpPayOfferShippingResolver
{
    /** @var (callable(int,int):(?bool))|null */
    private $lookup;

    /**
     * @param (callable(int,int):(?bool))|null $lookup fn(productId, websiteId): ?bool
     */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup;
    }

    /**
     * @param (callable(int,int):(?bool)) $lookup
     */
    public static function forTesting(callable $lookup): self
    {
        return new self($lookup);
    }

    /**
     * Cart/checkout parity: true when any shippable line remains.
     *
     * @param array<string,mixed> $input
     */
    public function resolveRequiresShipping(array $input): bool
    {
        $summary = is_array($input['line_summary'] ?? null) ? $input['line_summary'] : [];
        if ($summary !== []) {
            foreach ($summary as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($this->lineRequiresShipping($row)) {
                    return true;
                }
            }

            return false;
        }

        $productId = max(0, (int) ($input['product_id'] ?? 0));
        if ($productId > 0) {
            $fromOffer = $this->lookupProductRequiresShipping($productId);
            if ($fromOffer !== null) {
                return $fromOffer;
            }
        }

        if (array_key_exists('requires_shipping', $input)) {
            return (bool) $input['requires_shipping'];
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function lineRequiresShipping(array $row): bool
    {
        $productId = max(0, (int) ($row['product_id'] ?? $row['id'] ?? $row['legacy_product_id'] ?? 0));
        if ($productId > 0) {
            $fromOffer = $this->lookupProductRequiresShipping($productId);
            if ($fromOffer !== null) {
                return $fromOffer;
            }
        }
        if (array_key_exists('requires_shipping', $row)) {
            return (bool) $row['requires_shipping'];
        }

        return true;
    }

    /**
     * @return bool|null null = Offer unavailable → caller keeps conservative default
     */
    public function lookupProductRequiresShipping(int $productId): ?bool
    {
        $productId = max(0, $productId);
        if ($productId <= 0) {
            return null;
        }
        $websiteId = 0;
        try {
            if (RequestContext::isInitialized()) {
                $websiteId = max(0, (int) RequestContext::getWelineWebsiteId());
            }
        } catch (\Throwable) {
            $websiteId = 0;
        }
        if ($this->lookup !== null) {
            $resolved = ($this->lookup)($productId, $websiteId);

            return $resolved === null ? null : (bool) $resolved;
        }
        if ($websiteId <= 0 || !class_exists(OfferRepository::class)) {
            return null;
        }
        try {
            /** @var OfferRepository $offers */
            $offers = ObjectManager::getInstance(OfferRepository::class);
            $rows = $offers->listPublishedByProductIds($websiteId, [$productId]);
            if ($rows === []) {
                $rows = $offers->listByProductIds($websiteId, [$productId]);
            }
            if ($rows === []) {
                return null;
            }
            $picked = null;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!empty($row[Offer::schema_fields_IS_DEFAULT] ?? $row['is_default'] ?? false)) {
                    $picked = $row;
                    break;
                }
                if ($picked === null) {
                    $picked = $row;
                }
            }
            if ($picked === null) {
                return null;
            }
            if (!array_key_exists(Offer::schema_fields_REQUIRES_SHIPPING, $picked)
                && !array_key_exists('requires_shipping', $picked)
            ) {
                return null;
            }

            return (bool) ($picked[Offer::schema_fields_REQUIRES_SHIPPING]
                ?? $picked['requires_shipping']
                ?? true);
        } catch (\Throwable) {
            return null;
        }
    }
}
