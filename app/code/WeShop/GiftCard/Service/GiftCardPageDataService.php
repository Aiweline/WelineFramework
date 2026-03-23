<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Service;

use WeShop\GiftCard\Model\GiftCard;

class GiftCardPageDataService
{
    public function __construct(
        private readonly GiftCardService $giftCardService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $summary = $this->giftCardService->getCustomerGiftCardSummary($customerId);
        $items = $this->mapItems($this->giftCardService->getCustomerGiftCards($customerId, 50));

        return [
            'gift_cards' => $items,
            'gift_card_count' => (int) ($summary['count'] ?? 0),
            'gift_card_active_count' => (int) ($summary['active_count'] ?? 0),
            'gift_card_total_balance' => (float) ($summary['total_balance'] ?? 0),
            'gift_card_total_amount' => (float) ($summary['total_amount'] ?? 0),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $expiresAt = (string) ($item[GiftCard::schema_fields_EXPIRES_AT] ?? '');
            $mapped[] = [
                'card_id' => (int) ($item[GiftCard::schema_fields_ID] ?? 0),
                'card_number' => (string) ($item[GiftCard::schema_fields_CARD_NUMBER] ?? ''),
                'amount' => (float) ($item[GiftCard::schema_fields_AMOUNT] ?? 0),
                'balance' => (float) ($item[GiftCard::schema_fields_BALANCE] ?? 0),
                'status' => (string) ($item[GiftCard::schema_fields_STATUS] ?? GiftCardService::STATUS_DISABLED),
                'expires_at' => $expiresAt,
                'is_expired' => $expiresAt !== '' && strtotime($expiresAt) < time(),
                'created_at' => (string) ($item[GiftCard::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        return $mapped;
    }
}
