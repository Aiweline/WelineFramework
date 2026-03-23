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

    public function createGiftCard(array $giftCardData): GiftCard
    {
        $customerId = (int) ($giftCardData['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $amount = (float) ($giftCardData['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Gift card amount must be greater than zero.'));
        }

        $now = date('Y-m-d H:i:s');

        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        $giftCard->clearData()
            ->setData(GiftCard::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(GiftCard::schema_fields_CARD_NUMBER, $this->generateCardNumber())
            ->setData(GiftCard::schema_fields_AMOUNT, $amount)
            ->setData(GiftCard::schema_fields_BALANCE, (float) ($giftCardData['balance'] ?? $amount))
            ->setData(GiftCard::schema_fields_STATUS, (string) ($giftCardData['status'] ?? self::STATUS_ACTIVE))
            ->setData(GiftCard::schema_fields_EXPIRES_AT, (string) ($giftCardData['expires_at'] ?? '') ?: null)
            ->setData(GiftCard::schema_fields_CREATED_AT, $now)
            ->setData(GiftCard::schema_fields_UPDATED_AT, $now)
            ->save();

        return $giftCard;
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

    protected function generateCardNumber(): string
    {
        return 'GC' . strtoupper(bin2hex(random_bytes(6)));
    }
}
