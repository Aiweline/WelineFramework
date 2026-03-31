<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Credit;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CreditService
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_FROZEN = 0;

    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    public function getCreditForCustomer(int $customerId): ?Credit
    {
        if ($customerId <= 0) {
            return null;
        }

        /** @var Credit $credit */
        $credit = ObjectManager::getInstance(Credit::class);
        $credit->clear()
            ->where(Credit::schema_fields_CUSTOMER_ID, $customerId)
            ->limit(1);

        $rows = $credit->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0];
        if (!\is_array($first)) {
            return null;
        }

        $creditId = (int) ($first[Credit::schema_fields_ID] ?? $first['credit_id'] ?? 0);
        if ($creditId <= 0) {
            return null;
        }

        $credit->clear()->load($creditId);

        return $credit->getId() ? $credit : null;
    }

    public function getOrCreateCredit(int $customerId, float $defaultLimit = 0.0): Credit
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $existing = $this->getCreditForCustomer($customerId);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        /** @var Credit $credit */
        $credit = ObjectManager::getInstance(Credit::class);
        $limit = round(max(0.0, $defaultLimit), 2);
        $credit->clearData()
            ->setData(Credit::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Credit::schema_fields_CREDIT_LIMIT, $limit)
            ->setData(Credit::schema_fields_USED_CREDIT, '0.00')
            ->setData(Credit::schema_fields_AVAILABLE_CREDIT, $limit)
            ->setData(Credit::schema_fields_CREDIT_LEVEL, 'C')
            ->setData(Credit::schema_fields_VALID_FROM, $now)
            ->setData(Credit::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->setData(Credit::schema_fields_CREATED_AT, $now)
            ->setData(Credit::schema_fields_UPDATED_AT, $now)
            ->save();

        $this->recalculateAvailable($credit);

        return $credit;
    }

    public function setCreditLimit(int $customerId, float $limit, ?string $level = null): Credit
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $credit = $this->getOrCreateCredit($customerId, $limit);
        $credit->setData(Credit::schema_fields_CREDIT_LIMIT, round(max(0.0, $limit), 2));
        if ($level !== null && $level !== '') {
            $credit->setData(Credit::schema_fields_CREDIT_LEVEL, $level);
        }
        $credit->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $credit->save();

        return $this->recalculateAvailable($credit);
    }

    public function freeze(int $customerId): void
    {
        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            return;
        }

        $credit->setData(Credit::schema_fields_STATUS, self::STATUS_FROZEN)
            ->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    public function unfreeze(int $customerId): void
    {
        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            return;
        }

        $credit->setData(Credit::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    public function assertCanUseCredit(int $customerId, float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Credit amount must be positive.'));
        }

        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            throw new \InvalidArgumentException((string) __('No B2B credit line is configured for this account.'));
        }

        if ((int) ($credit->getData(Credit::schema_fields_STATUS) ?? 0) !== self::STATUS_ACTIVE) {
            throw new \InvalidArgumentException((string) __('The B2B credit line is frozen. Please contact support.'));
        }

        $available = (float) ($credit->getData(Credit::schema_fields_AVAILABLE_CREDIT) ?? 0);
        if ($amount > $available + 0.00001) {
            $this->dispatch('WeShop_B2B::credit_exceeded', [
                'customer_id' => $customerId,
                'amount' => $amount,
                'available' => $available,
            ]);
            throw new \InvalidArgumentException((string) __('Insufficient B2B credit. Available: %{1}', [number_format($available, 2)]));
        }
    }

    public function reserveCredit(int $customerId, float $amount): Credit
    {
        $this->assertCanUseCredit($customerId, $amount);
        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            throw new \RuntimeException((string) __('Credit record missing after validation.'));
        }

        $used = round((float) ($credit->getData(Credit::schema_fields_USED_CREDIT) ?? 0) + $amount, 2);
        $credit->setData(Credit::schema_fields_USED_CREDIT, $used)
            ->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->recalculateAvailable($credit);
    }

    public function releaseCredit(int $customerId, float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Credit amount must be positive.'));
        }

        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            return;
        }

        $used = max(0.0, round((float) ($credit->getData(Credit::schema_fields_USED_CREDIT) ?? 0) - $amount, 2));
        $credit->setData(Credit::schema_fields_USED_CREDIT, $used)
            ->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->recalculateAvailable($credit);
    }

    public function recalculateAvailable(Credit $credit): Credit
    {
        $limit = (float) ($credit->getData(Credit::schema_fields_CREDIT_LIMIT) ?? 0);
        $used = (float) ($credit->getData(Credit::schema_fields_USED_CREDIT) ?? 0);
        $available = round(max(0.0, $limit - $used), 2);
        $credit->setData(Credit::schema_fields_AVAILABLE_CREDIT, $available)
            ->setData(Credit::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $credit;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function getCreditSummary(int $customerId): array
    {
        $credit = $this->getCreditForCustomer($customerId);
        if ($credit === null) {
            return [
                'has_credit' => false,
                'credit_limit' => 0.0,
                'used_credit' => 0.0,
                'available_credit' => 0.0,
                'status' => 'none',
            ];
        }

        return [
            'has_credit' => true,
            'credit_limit' => (float) ($credit->getData(Credit::schema_fields_CREDIT_LIMIT) ?? 0),
            'used_credit' => (float) ($credit->getData(Credit::schema_fields_USED_CREDIT) ?? 0),
            'available_credit' => (float) ($credit->getData(Credit::schema_fields_AVAILABLE_CREDIT) ?? 0),
            'status' => (int) ($credit->getData(Credit::schema_fields_STATUS) ?? 0) === self::STATUS_ACTIVE ? 'active' : 'frozen',
            'credit_level' => (string) ($credit->getData(Credit::schema_fields_CREDIT_LEVEL) ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $event, array $payload): void
    {
        $manager = $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
        $manager->dispatch($event, $payload);
    }
}
