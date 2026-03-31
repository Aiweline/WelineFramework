<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use WeShop\Affiliate\Model\Affiliate;
use Weline\Framework\Manager\ObjectManager;

class AffiliateService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public function createAffiliate(int $customerId): Affiliate
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $existing = $this->getAffiliateAccount($customerId);
        if (is_array($existing) && (int) ($existing[Affiliate::schema_fields_ID] ?? 0) > 0) {
            /** @var Affiliate $affiliate */
            $affiliate = ObjectManager::getInstance(Affiliate::class);
            $affiliate->load((int) $existing[Affiliate::schema_fields_ID]);
            return $affiliate;
        }

        $now = date('Y-m-d H:i:s');

        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $affiliate->clearData()
            ->setData(Affiliate::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Affiliate::schema_fields_REFERRAL_CODE, $this->generateReferralCode($customerId))
            ->setData(Affiliate::schema_fields_COMMISSION_RATE, 0.10)
            ->setData(Affiliate::schema_fields_TOTAL_COMMISSION, 0.0)
            ->setData(Affiliate::schema_fields_PAID_COMMISSION, 0.0)
            ->setData(Affiliate::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->setData(Affiliate::schema_fields_CREATED_AT, $now)
            ->setData(Affiliate::schema_fields_UPDATED_AT, $now)
            ->save();

        return $affiliate;
    }

    public function calculateCommission(string $referralCode, float $orderTotal): float
    {
        if ($referralCode === '' || $orderTotal <= 0) {
            return 0.0;
        }

        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $affiliate->load($referralCode, Affiliate::schema_fields_REFERRAL_CODE);
        if (
            !$affiliate->getId()
            || (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? '') !== self::STATUS_ACTIVE
        ) {
            return 0.0;
        }

        $rate = (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0);
        if ($rate <= 0) {
            return 0.0;
        }

        return round($orderTotal * $rate, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAffiliateSummary(int $customerId): array
    {
        $account = $this->getAffiliateAccountOrCreate($customerId);

        $totalCommission = (float) ($account[Affiliate::schema_fields_TOTAL_COMMISSION] ?? 0);
        $paidCommission = (float) ($account[Affiliate::schema_fields_PAID_COMMISSION] ?? 0);
        $pendingCommission = max(0.0, round($totalCommission - $paidCommission, 2));
        $referralCode = (string) ($account[Affiliate::schema_fields_REFERRAL_CODE] ?? '');

        return [
            'affiliate_id' => (int) ($account[Affiliate::schema_fields_ID] ?? 0),
            'customer_id' => (int) ($account[Affiliate::schema_fields_CUSTOMER_ID] ?? $customerId),
            'referral_code' => $referralCode,
            'referral_link' => $referralCode === '' ? '' : $this->getReferralBasePath() . '?ref=' . rawurlencode($referralCode),
            'commission_rate' => (float) ($account[Affiliate::schema_fields_COMMISSION_RATE] ?? 0),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'status' => (string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAffiliateAccount(int $customerId): ?array
    {
        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $rows = $affiliate->clear()
            ->where(Affiliate::schema_fields_CUSTOMER_ID, $customerId)
            ->limit(1)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAffiliateAccountOrCreate(int $customerId): array
    {
        $existing = $this->getAffiliateAccount($customerId);
        if (is_array($existing)) {
            return $existing;
        }

        $affiliate = $this->createAffiliate($customerId);

        return [
            Affiliate::schema_fields_ID => (int) ($affiliate->getId() ?? 0),
            Affiliate::schema_fields_CUSTOMER_ID => (int) ($affiliate->getData(Affiliate::schema_fields_CUSTOMER_ID) ?? $customerId),
            Affiliate::schema_fields_REFERRAL_CODE => (string) ($affiliate->getData(Affiliate::schema_fields_REFERRAL_CODE) ?? ''),
            Affiliate::schema_fields_COMMISSION_RATE => (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0),
            Affiliate::schema_fields_TOTAL_COMMISSION => (float) ($affiliate->getData(Affiliate::schema_fields_TOTAL_COMMISSION) ?? 0),
            Affiliate::schema_fields_PAID_COMMISSION => (float) ($affiliate->getData(Affiliate::schema_fields_PAID_COMMISSION) ?? 0),
            Affiliate::schema_fields_STATUS => (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? self::STATUS_DISABLED),
        ];
    }

    public function getReferralBasePath(): string
    {
        return '/register';
    }

    protected function generateReferralCode(int $customerId): string
    {
        return 'REF' . str_pad((string) $customerId, 8, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2)));
    }

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => (string) __('Active'),
            self::STATUS_DISABLED => (string) __('Disabled'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[$status]);
    }

    public function getAffiliateRecord(int $affiliateId): ?Affiliate
    {
        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $affiliate->load($affiliateId);

        return $affiliate->getId() ? $affiliate : null;
    }

    public function getAffiliateList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);
        $affiliate->clear();

        if (!empty($filters['customer_id'])) {
            $affiliate->where(Affiliate::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['referral_code'])) {
            $affiliate->where(Affiliate::schema_fields_REFERRAL_CODE, '%' . $filters['referral_code'] . '%', 'LIKE');
        }

        if (!empty($filters['status']) && $this->isValidStatus((string) $filters['status'])) {
            $affiliate->where(Affiliate::schema_fields_STATUS, (string) $filters['status']);
        }

        $affiliate->order(Affiliate::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $affiliate->select()->fetchArray(),
            'total' => $affiliate->getTotalCount(),
            'pagination' => $affiliate->getPagination(),
        ];
    }

    public function saveAffiliate(array $data): Affiliate
    {
        $affiliateId = (int) ($data['affiliate_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $commissionRate = (float) ($data['commission_rate'] ?? 0);
        $status = trim((string) ($data['status'] ?? self::STATUS_ACTIVE));

        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('Unsupported affiliate status.'));
        }

        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new \InvalidArgumentException((string) __('Commission rate must be between 0 and 1.'));
        }

        /** @var Affiliate $affiliate */
        $affiliate = ObjectManager::getInstance(Affiliate::class);

        if ($affiliateId) {
            $affiliate->load($affiliateId);
            if (!$affiliate->getId()) {
                throw new \InvalidArgumentException((string) __('Affiliate record not found.'));
            }
        } elseif ($customerId) {
            $existing = $this->getAffiliateAccount($customerId);
            if (is_array($existing) && (int) ($existing[Affiliate::schema_fields_ID] ?? 0) > 0) {
                $affiliate->load((int) $existing[Affiliate::schema_fields_ID]);
            } else {
                $affiliate = $this->createAffiliate($customerId);
            }
        } else {
            throw new \InvalidArgumentException((string) __('Customer ID or affiliate ID is required.'));
        }

        $affiliate->setData([
            Affiliate::schema_fields_COMMISSION_RATE => round($commissionRate, 2),
            Affiliate::schema_fields_STATUS => $status,
            Affiliate::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ]);

        if (!$affiliate->getData(Affiliate::schema_fields_CREATED_AT)) {
            $affiliate->setData(Affiliate::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }

        $affiliate->save();

        return $affiliate;
    }
}
