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

    protected function getReferralBasePath(): string
    {
        return '/register';
    }

    protected function generateReferralCode(int $customerId): string
    {
        return 'REF' . str_pad((string) $customerId, 8, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2)));
    }
}
