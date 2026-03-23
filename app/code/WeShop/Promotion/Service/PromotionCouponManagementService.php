<?php

declare(strict_types=1);

namespace WeShop\Promotion\Service;

use WeShop\Promotion\Model\Coupon;
use WeShop\Promotion\Repository\PromotionCouponRepository;
use WeShop\Promotion\Repository\PromotionCouponRepositoryInterface;

class PromotionCouponManagementService
{
    private PromotionCouponRepositoryInterface $repository;

    public function __construct(?PromotionCouponRepositoryInterface $repository = null)
    {
        $this->repository = $repository ?? new PromotionCouponRepository();
    }

    public function getDashboardData(int $recentLimit = 5): array
    {
        return [
            'summary' => $this->repository->getCouponSummary(),
            'recent' => $this->repository->listRecentCoupons($recentLimit),
        ];
    }

    public function saveCoupon(array $input, int $couponId = 0): Coupon
    {
        $coupon = $couponId > 0
            ? $this->repository->findCouponById($couponId) ?? $this->repository->createCoupon()
            : $this->repository->createCoupon();

        $data = $this->prepareData($input);

        $coupon->setData($data);

        $now = (string)date('Y-m-d H:i:s');
        $coupon->setData(Coupon::schema_fields_UPDATED_AT, $now);
        if (!$coupon->getId()) {
            $coupon->setData(Coupon::schema_fields_CREATED_AT, $now);
        }

        $coupon->save();

        return $coupon;
    }

    private function prepareData(array $input): array
    {
        $discountType = in_array($input['discount_type'] ?? 'fixed', ['fixed', 'percent'], true)
            ? $input['discount_type']
            : 'fixed';

        $startDate = $this->normalizeDate($input['start_date'] ?? '');
        $endDate = $this->normalizeDate($input['end_date'] ?? '');

        if ($endDate !== '' && $startDate !== '' && $startDate > $endDate) {
            throw new \InvalidArgumentException(__('End date must be after start date.'));
        }

        return [
            Coupon::schema_fields_CODE => (string)trim($input['code'] ?? ''),
            Coupon::schema_fields_NAME => (string)trim($input['name'] ?? ''),
            Coupon::schema_fields_DISCOUNT_TYPE => $discountType,
            Coupon::schema_fields_DISCOUNT_VALUE => (float)($input['discount_value'] ?? 0.0),
            Coupon::schema_fields_MIN_AMOUNT => (float)($input['min_amount'] ?? 0.0),
            Coupon::schema_fields_MAX_DISCOUNT => (float)($input['max_discount'] ?? 0.0),
            Coupon::schema_fields_START_DATE => $startDate,
            Coupon::schema_fields_END_DATE => $endDate,
            Coupon::schema_fields_IS_ACTIVE => (int)($input['is_active'] ?? 1),
        ];
    }

    private function normalizeDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException(__('Invalid date format.'));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
