<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Shared quote-line weight for Checkout / Express / HelpPay.
 * Prefer line snapshot; else catalog weight_kg. Never invent 0.5kg.
 */
final class CheckoutQuoteLineWeightResolver
{
    /** @var (callable(int,int,int):int)|null */
    private $catalogReader;

    /**
     * @param (callable(int,int,int):int)|null $catalogReader websiteId, storeId, productId → weight_minor
     */
    public function __construct(?callable $catalogReader = null)
    {
        $this->catalogReader = $catalogReader;
    }

    /**
     * @param (callable(int,int,int):int)|null $catalogReader
     */
    public static function forTesting(?callable $catalogReader): self
    {
        return new self($catalogReader);
    }

    /**
     * @param array<string, mixed> $line
     */
    public function resolveLineWeightMinor(array $line, ?int $websiteId = null, ?int $storeId = null): int
    {
        $weightMinor = max(0, (int) ($line['weight_minor'] ?? 0));
        if ($weightMinor > 0) {
            return $weightMinor;
        }
        $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
        if ($productId <= 0) {
            return 0;
        }
        $websiteId = $websiteId ?? (int) RequestContext::getWelineWebsiteId();
        $storeId = $storeId ?? (int) RequestContext::getWelineStoreId();

        return max(0, $this->catalogWeightMinor($websiteId, $storeId, $productId));
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function enrichLines(array $lines, ?int $websiteId = null, ?int $storeId = null): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $line['weight_minor'] = $this->resolveLineWeightMinor($line, $websiteId, $storeId);
            $out[] = $line;
        }

        return $out;
    }

    private function catalogWeightMinor(int $websiteId, int $storeId, int $productId): int
    {
        if ($this->catalogReader !== null) {
            return max(0, (int) ($this->catalogReader)($websiteId, $storeId, $productId));
        }
        try {
            $attributes = ObjectManager::getInstance(\Weline\Product\Repository\AttributeValueRepository::class);
            if (!is_object($attributes) || !method_exists($attributes, 'read')) {
                return 0;
            }
            $value = $attributes->read($websiteId, $storeId, 'product', $productId, 'weight_kg', '', ['']);
            $raw = is_object($value) ? ($value->value ?? null) : null;
            if (!is_numeric($raw)) {
                return 0;
            }
            $kg = (float) $raw;
            if ($kg <= 0) {
                return 0;
            }

            return (int) max(1, (int) round($kg * 1000));
        } catch (\Throwable) {
            return 0;
        }
    }
}
