<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Service;

use WeShop\GiftCard\Model\GiftCard;
use Weline\Framework\Manager\ObjectManager;

class GiftCardService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => (string) __('Active'),
            self::STATUS_REDEEMED => (string) __('Redeemed'),
            self::STATUS_EXPIRED => (string) __('Expired'),
            self::STATUS_DISABLED => (string) __('Disabled'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[$status]);
    }

    public function createGiftCard(array $giftCardData): GiftCard
    {
        // Keep create/save validation rules consistent to avoid invalid records.
        return $this->saveGiftCard($giftCardData);
    }

    public function useGiftCard(string $cardNumber, float $amount, int $customerId = 0): bool
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        $giftCard->load($cardNumber, GiftCard::schema_fields_CARD_NUMBER);

        if (!$giftCard->getId()) {
            throw new \InvalidArgumentException((string) __('Gift card does not exist.'));
        }

        if ($customerId > 0 && (int) $giftCard->getData(GiftCard::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \RuntimeException((string) __('No permission to use this gift card.'));
        }

        $status = (string) ($giftCard->getData(GiftCard::schema_fields_STATUS) ?? self::STATUS_DISABLED);
        if ($status !== self::STATUS_ACTIVE) {
            throw new \RuntimeException((string) __('Gift card is not active.'));
        }

        $expiresAt = (string) ($giftCard->getData(GiftCard::schema_fields_EXPIRES_AT) ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            $giftCard->setData(GiftCard::schema_fields_STATUS, self::STATUS_EXPIRED)
                ->setData(GiftCard::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
            throw new \RuntimeException((string) __('Gift card has expired.'));
        }

        $balance = (float) ($giftCard->getData(GiftCard::schema_fields_BALANCE) ?? 0);
        if ($amount <= 0 || $balance < $amount) {
            throw new \RuntimeException((string) __('Gift card balance is insufficient.'));
        }

        $nextBalance = round($balance - $amount, 2);
        $nextStatus = $nextBalance <= 0.0 ? self::STATUS_REDEEMED : self::STATUS_ACTIVE;

        $giftCard->setData(GiftCard::schema_fields_BALANCE, $nextBalance)
            ->setData(GiftCard::schema_fields_STATUS, $nextStatus)
            ->setData(GiftCard::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerGiftCards(int $customerId, int $limit = 20): array
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);

        $query = $giftCard->clear()
            ->where(GiftCard::schema_fields_CUSTOMER_ID, $customerId)
            ->order(GiftCard::schema_fields_CREATED_AT, 'DESC');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->select()->fetchArray();
    }

    /**
     * @return array{count:int,active_count:int,total_balance:float,total_amount:float}
     */
    public function getCustomerGiftCardSummary(int $customerId): array
    {
        $cards = $this->getCustomerGiftCards($customerId, 0);

        $summary = [
            'count' => 0,
            'active_count' => 0,
            'total_balance' => 0.0,
            'total_amount' => 0.0,
        ];

        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }

            ++$summary['count'];
            if ((string) ($card[GiftCard::schema_fields_STATUS] ?? '') === self::STATUS_ACTIVE) {
                ++$summary['active_count'];
            }
            $summary['total_balance'] += (float) ($card[GiftCard::schema_fields_BALANCE] ?? 0);
            $summary['total_amount'] += (float) ($card[GiftCard::schema_fields_AMOUNT] ?? 0);
        }

        $summary['total_balance'] = round($summary['total_balance'], 2);
        $summary['total_amount'] = round($summary['total_amount'], 2);

        return $summary;
    }

    public function getGiftCardRecord(int $giftCardId): ?GiftCard
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        $giftCard->load($giftCardId);

        return $giftCard->getId() ? $giftCard : null;
    }

    public function deleteGiftCard(int $giftCardId): bool
    {
        if ($giftCardId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid gift card ID.'));
        }

        $giftCard = $this->getGiftCardRecord($giftCardId);
        if (!$giftCard) {
            throw new \InvalidArgumentException((string) __('Gift card does not exist.'));
        }

        $giftCard->delete();
        return true;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>,total:int,pagination:array<string, mixed>}
     */
    public function getGiftCardList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);

        $giftCard->clear();

        if (!empty($filters['customer_id'])) {
            $giftCard->where(GiftCard::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['card_number'])) {
            $giftCard->where(GiftCard::schema_fields_CARD_NUMBER, '%' . $filters['card_number'] . '%', 'LIKE');
        }

        if (!empty($filters['status']) && $this->isValidStatus((string) $filters['status'])) {
            $giftCard->where(GiftCard::schema_fields_STATUS, (string) $filters['status']);
        }

        $giftCard->order(GiftCard::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $giftCard->select()->fetchArray(),
            'total' => $giftCard->getTotalCount(),
            'pagination' => $giftCard->getPagination(),
        ];
    }

    /**
     * @return array{total:int,active:int,redeemed:int,expired:int,disabled:int,total_balance:float,total_amount:float}
     */
    public function getGiftCardSummary(): array
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);

        $summary = [
            'total' => $giftCard->clear()->count(),
            self::STATUS_ACTIVE => 0,
            self::STATUS_REDEEMED => 0,
            self::STATUS_EXPIRED => 0,
            self::STATUS_DISABLED => 0,
            'total_balance' => 0.0,
            'total_amount' => 0.0,
        ];

        foreach ([self::STATUS_ACTIVE, self::STATUS_REDEEMED, self::STATUS_EXPIRED, self::STATUS_DISABLED] as $status) {
            $summary[$status] = $giftCard->clear()
                ->where(GiftCard::schema_fields_STATUS, $status)
                ->count();
        }

        foreach ($giftCard->clear()->select()->fetchArray() as $record) {
            if (!is_array($record)) {
                continue;
            }

            $summary['total_balance'] += (float) ($record[GiftCard::schema_fields_BALANCE] ?? 0);
            $summary['total_amount'] += (float) ($record[GiftCard::schema_fields_AMOUNT] ?? 0);
        }

        $summary['total_balance'] = round($summary['total_balance'], 2);
        $summary['total_amount'] = round($summary['total_amount'], 2);

        return $summary;
    }

    public function saveGiftCard(array $data): GiftCard
    {
        $cardId = (int) ($data['card_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $cardNumber = trim((string) ($data['card_number'] ?? ''));
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $balance = round((float) ($data['balance'] ?? $amount), 2);
        $status = trim((string) ($data['status'] ?? self::STATUS_ACTIVE));
        $expiresAt = $this->normalizeDateTime((string) ($data['expires_at'] ?? ''), true);

        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Gift card amount must be greater than zero.'));
        }

        if ($balance < 0) {
            throw new \InvalidArgumentException((string) __('Gift card balance cannot be negative.'));
        }

        if ($balance > $amount) {
            throw new \InvalidArgumentException((string) __('Gift card balance cannot exceed the face amount.'));
        }

        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('Unsupported gift card status.'));
        }

        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        if ($cardId > 0) {
            $giftCard->load($cardId);
        } else {
            // 创建：必须清空单例残留。WLS 下 Model 常被复用，仅靠 getId() 判断会跳过 clear，导致误更新旧卡或保存异常。
            $giftCard->clearData();
        }

        if ($cardNumber === '') {
            $cardNumber = (string) ($giftCard->getData(GiftCard::schema_fields_CARD_NUMBER) ?? '');
        }
        if ($cardNumber === '') {
            $cardNumber = $this->generateCardNumber();
        }

        $this->assertUniqueCardNumber($cardNumber, (int) $giftCard->getId());

        $now = date('Y-m-d H:i:s');
        $giftCard->setData(GiftCard::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(GiftCard::schema_fields_CARD_NUMBER, $cardNumber)
            ->setData(GiftCard::schema_fields_AMOUNT, $amount)
            ->setData(GiftCard::schema_fields_BALANCE, $balance)
            ->setData(GiftCard::schema_fields_STATUS, $status)
            ->setData(GiftCard::schema_fields_EXPIRES_AT, $expiresAt)
            ->setData(GiftCard::schema_fields_UPDATED_AT, $now);

        if (!$giftCard->getId()) {
            $giftCard->setData(GiftCard::schema_fields_CREATED_AT, $now);
        }

        $giftCard->save();

        return $giftCard;
    }

    protected function generateCardNumber(): string
    {
        return 'GC' . strtoupper(bin2hex(random_bytes(6)));
    }

    protected function normalizeDateTime(string $value, bool $nullable = false): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return $nullable ? null : date('Y-m-d H:i:s');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException((string) __('Invalid date format.'));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    protected function assertUniqueCardNumber(string $cardNumber, int $currentId = 0): void
    {
        /** @var GiftCard $existing */
        $existing = ObjectManager::make(GiftCard::class);
        $existing->load($cardNumber, GiftCard::schema_fields_CARD_NUMBER);

        if ($existing->getId() && (int) $existing->getId() !== $currentId) {
            throw new \InvalidArgumentException((string) __('Gift card number already exists.'));
        }
    }
}
