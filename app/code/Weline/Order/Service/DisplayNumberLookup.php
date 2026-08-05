<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\DisplayNumberRef;

/**
 * Kind-qualified display number lookup（DEC-017 / TEST-P2D-04）.
 * Bare-number query is forbidden → display_number_kind_required.
 */
final class DisplayNumberLookup
{
    public const ERROR_KIND_REQUIRED = 'display_number_kind_required';
    public const ERROR_NOT_FOUND = 'display_number_not_found';

    public function __construct(
        private readonly DisplayNumberAllocator $allocator,
    ) {
    }

    public static function forTesting(?DisplayNumberAllocator $allocator = null): self
    {
        return new self($allocator ?? DisplayNumberAllocator::forTesting());
    }

    public function allocator(): DisplayNumberAllocator
    {
        return $this->allocator;
    }

    /**
     * @param string|null $numberKind Required; null/'' → display_number_kind_required
     */
    public function find(
        ?string $numberKind,
        string $displayNumber,
        int $websiteId = 0,
        int $storeId = 0,
    ): DisplayNumberRef {
        if ($numberKind === null || trim($numberKind) === '') {
            throw new OrderFacadeConflictException(
                self::ERROR_KIND_REQUIRED,
                \__('查号必须携带 number_kind'),
                ['display_number' => $displayNumber],
            );
        }
        $kind = $this->allocator->normalizeKind($numberKind);
        $displayNumber = trim($displayNumber);
        if ($displayNumber === '') {
            throw new \InvalidArgumentException(\__('display_number 不能为空'));
        }

        $reference = $this->allocator->lookup(
            $websiteId,
            $storeId,
            $kind,
            $displayNumber,
        );
        if ($reference !== null) {
            return $reference;
        }

        throw new OrderFacadeConflictException(
            self::ERROR_NOT_FOUND,
            \__('展示号未找到：%{1}/%{2}', [$kind, $displayNumber]),
            [
                'number_kind' => $kind,
                'display_number' => $displayNumber,
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ],
        );
    }
}
