<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Cookie;

class StorefrontRecentlyViewedRecorder
{
    public const GUEST_COOKIE_NAME = 'weshop_recently_viewed';
    private const GUEST_COOKIE_TTL = 3600 * 24 * 30;
    private const GUEST_COOKIE_LIMIT = 24;

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RecentlyViewedService $recentlyViewedService
    ) {
    }

    public function recordProductView(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        $this->recordGuestProductView($productId);

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId > 0) {
            $this->recentlyViewedService->recordView($customerId, $productId);
        }
    }

    /**
     * @return array<int, int>
     */
    public static function getGuestProductIds(): array
    {
        $raw = (string) Cookie::get(self::GUEST_COOKIE_NAME, '[]');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = explode(',', $raw);
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function recordGuestProductView(int $productId): void
    {
        $ids = array_values(array_filter(
            self::getGuestProductIds(),
            static fn (int $id): bool => $id !== $productId
        ));
        array_unshift($ids, $productId);
        $ids = array_slice($ids, 0, self::GUEST_COOKIE_LIMIT);

        Cookie::set(
            self::GUEST_COOKIE_NAME,
            json_encode($ids, JSON_UNESCAPED_SLASHES) ?: '[]',
            self::GUEST_COOKIE_TTL,
            ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']
        );
    }
}
