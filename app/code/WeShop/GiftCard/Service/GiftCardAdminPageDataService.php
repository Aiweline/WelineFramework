<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Service;

use WeShop\GiftCard\Model\GiftCard;

class GiftCardAdminPageDataService
{
    public function __construct(
        private readonly GiftCardService $giftCardService
    ) {
    }

    public function getPageData(int $page = 1, int $pageSize = 20, array $filters = [], int $editingId = 0): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->giftCardService->getGiftCardList($page, $pageSize, $sanitizedFilters);
        $editingRecord = $editingId > 0 ? $this->giftCardService->getGiftCardRecord($editingId) : null;

        return [
            'giftCards' => array_map(fn (array $record): array => $this->normalizeRecord($record), $result['items'] ?? []),
            'summary' => $this->giftCardService->getGiftCardSummary(),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'statusOptions' => $this->giftCardService->getStatusOptions(),
            'editingRecord' => $editingRecord ? $this->normalizeModel($editingRecord) : $this->getEmptyRecord(),
        ];
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['card_number'])) {
            $sanitized['card_number'] = trim((string) $filters['card_number']);
        }

        if (!empty($filters['status']) && $this->giftCardService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = (string) $filters['status'];
        }

        return $sanitized;
    }

    private function normalizeModel(GiftCard $giftCard): array
    {
        $status = (string) $giftCard->getData(GiftCard::schema_fields_STATUS);

        return [
            'card_id' => (int) $giftCard->getId(),
            'customer_id' => (int) $giftCard->getData(GiftCard::schema_fields_CUSTOMER_ID),
            'card_number' => (string) $giftCard->getData(GiftCard::schema_fields_CARD_NUMBER),
            'amount' => (float) $giftCard->getData(GiftCard::schema_fields_AMOUNT),
            'balance' => (float) $giftCard->getData(GiftCard::schema_fields_BALANCE),
            'status' => $status,
            'status_label' => $this->giftCardService->getStatusOptions()[$status] ?? $status,
            'expires_at' => (string) ($giftCard->getData(GiftCard::schema_fields_EXPIRES_AT) ?? ''),
            'created_at' => (string) ($giftCard->getData(GiftCard::schema_fields_CREATED_AT) ?? ''),
            'updated_at' => (string) ($giftCard->getData(GiftCard::schema_fields_UPDATED_AT) ?? ''),
        ];
    }

    private function normalizeRecord(array $record): array
    {
        $status = (string) ($record[GiftCard::schema_fields_STATUS] ?? $record['status'] ?? GiftCardService::STATUS_DISABLED);
        $expiresAt = (string) ($record[GiftCard::schema_fields_EXPIRES_AT] ?? $record['expires_at'] ?? '');

        return [
            'card_id' => (int) ($record[GiftCard::schema_fields_ID] ?? $record['card_id'] ?? 0),
            'customer_id' => (int) ($record[GiftCard::schema_fields_CUSTOMER_ID] ?? $record['customer_id'] ?? 0),
            'card_number' => (string) ($record[GiftCard::schema_fields_CARD_NUMBER] ?? $record['card_number'] ?? ''),
            'amount' => (float) ($record[GiftCard::schema_fields_AMOUNT] ?? $record['amount'] ?? 0),
            'balance' => (float) ($record[GiftCard::schema_fields_BALANCE] ?? $record['balance'] ?? 0),
            'status' => $status,
            'status_label' => $this->giftCardService->getStatusOptions()[$status] ?? $status,
            'expires_at' => $expiresAt,
            'is_expired' => $expiresAt !== '' && strtotime($expiresAt) < time(),
            'created_at' => (string) ($record[GiftCard::schema_fields_CREATED_AT] ?? $record['created_at'] ?? ''),
            'updated_at' => (string) ($record[GiftCard::schema_fields_UPDATED_AT] ?? $record['updated_at'] ?? ''),
        ];
    }

    private function getEmptyRecord(): array
    {
        return [
            'card_id' => 0,
            'customer_id' => 0,
            'card_number' => '',
            'amount' => 0.0,
            'balance' => 0.0,
            'status' => GiftCardService::STATUS_ACTIVE,
            'status_label' => $this->giftCardService->getStatusOptions()[GiftCardService::STATUS_ACTIVE] ?? 'Active',
            'expires_at' => '',
            'created_at' => '',
            'updated_at' => '',
        ];
    }
}
