<?php

declare(strict_types=1);

namespace WeShop\Membership\Service;

use WeShop\Membership\Model\Membership;
use Weline\Framework\Manager\ObjectManager;

class MembershipService
{
    public function getLevelOptions(): array
    {
        return [
            'bronze' => (string) __('Bronze'),
            'silver' => (string) __('Silver'),
            'gold' => (string) __('Gold'),
            'platinum' => (string) __('Platinum'),
        ];
    }

    public function isValidLevel(string $level): bool
    {
        return isset($this->getLevelOptions()[strtolower($level)]);
    }

    public function getCustomerMembership(int $customerId): ?Membership
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);

        $membership->clear()
            ->where(Membership::schema_fields_CUSTOMER_ID, $customerId)
            ->find()
            ->fetch();

        return $membership->getId() ? $membership : null;
    }

    public function upgradeMembership(int $customerId, string $level): Membership
    {
        if (!$this->isValidLevel($level)) {
            throw new \InvalidArgumentException((string) __('Unsupported membership level.'));
        }

        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);

        $existing = $this->getCustomerMembership($customerId);
        if ($existing) {
            $membership = $existing;
        }

        $now = date('Y-m-d H:i:s');
        $membership->setData(Membership::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Membership::schema_fields_LEVEL, strtolower($level))
            ->setData(Membership::schema_fields_UPDATED_AT, $now);

        if (!$membership->getId()) {
            $membership->setData(Membership::schema_fields_CREATED_AT, $now);
        }

        $membership->save();

        return $membership;
    }

    public function getMembershipRecord(int $membershipId): ?Membership
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);
        $membership->load($membershipId);

        return $membership->getId() ? $membership : null;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>,total:int,pagination:array<string, mixed>}
     */
    public function getMembershipList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);

        $membership->clear();

        if (!empty($filters['customer_id'])) {
            $membership->where(Membership::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['level']) && $this->isValidLevel((string) $filters['level'])) {
            $membership->where(Membership::schema_fields_LEVEL, strtolower((string) $filters['level']));
        }

        $membership->order(Membership::schema_fields_UPDATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $membership->select()->fetchArray(),
            'total' => $membership->getTotalCount(),
            'pagination' => $membership->getPagination(),
        ];
    }

    /**
     * @return array{total:int,bronze:int,silver:int,gold:int,platinum:int,total_points:int}
     */
    public function getMembershipSummary(): array
    {
        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);

        $summary = [
            'total' => $membership->clear()->count(),
            'bronze' => 0,
            'silver' => 0,
            'gold' => 0,
            'platinum' => 0,
            'total_points' => 0,
        ];

        foreach (array_keys($this->getLevelOptions()) as $level) {
            $summary[$level] = $membership->clear()
                ->where(Membership::schema_fields_LEVEL, $level)
                ->count();
        }

        foreach ($membership->clear()->select()->fetchArray() as $record) {
            if (!is_array($record)) {
                continue;
            }

            $summary['total_points'] += (int) ($record[Membership::schema_fields_POINTS] ?? 0);
        }

        return $summary;
    }

    public function saveMembership(array $data): Membership
    {
        $membershipId = (int) ($data['membership_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $level = strtolower(trim((string) ($data['level'] ?? 'bronze')));
        $points = max(0, (int) ($data['points'] ?? 0));

        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        if (!$this->isValidLevel($level)) {
            throw new \InvalidArgumentException((string) __('Unsupported membership level.'));
        }

        /** @var Membership $membership */
        $membership = ObjectManager::getInstance(Membership::class);
        if ($membershipId > 0) {
            $membership->load($membershipId);
        } else {
            $existing = $this->getCustomerMembership($customerId);
            if ($existing) {
                $membership = $existing;
            }
        }

        $now = date('Y-m-d H:i:s');
        $membership->setData(Membership::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Membership::schema_fields_LEVEL, $level)
            ->setData(Membership::schema_fields_POINTS, $points)
            ->setData(Membership::schema_fields_UPDATED_AT, $now);

        if (!$membership->getId()) {
            $membership->setData(Membership::schema_fields_CREATED_AT, $now);
        }

        $membership->save();

        return $membership;
    }
}
