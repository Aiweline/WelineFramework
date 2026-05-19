<?php

declare(strict_types=1);

namespace WeShop\RMA\Service;

use WeShop\RMA\Model\Rma;
use Weline\Framework\Manager\ObjectManager;

class RmaService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @param array<string,mixed> $rmaData
     */
    public function createRma(array $rmaData): Rma
    {
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);

        $reason = trim((string) ($rmaData[Rma::schema_fields_REASON] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException((string) __('请填写退换货原因。'));
        }

        $now = date('Y-m-d H:i:s');
        $rma->clearData()
            ->setData(Rma::schema_fields_ORDER_ID, (int) ($rmaData[Rma::schema_fields_ORDER_ID] ?? 0))
            ->setData(Rma::schema_fields_CUSTOMER_ID, (int) ($rmaData[Rma::schema_fields_CUSTOMER_ID] ?? 0))
            ->setData(Rma::schema_fields_REASON, $reason)
            ->setData(Rma::schema_fields_DESCRIPTION, (string) ($rmaData[Rma::schema_fields_DESCRIPTION] ?? ''))
            ->setData(Rma::schema_fields_STATUS, (string) ($rmaData[Rma::schema_fields_STATUS] ?? self::STATUS_PENDING))
            ->setData(Rma::schema_fields_CREATED_AT, $now)
            ->setData(Rma::schema_fields_UPDATED_AT, $now)
            ->save();

        return $rma;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getCustomerRmas(int $customerId): array
    {
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);

        return $rma->clear()
            ->where(Rma::schema_fields_CUSTOMER_ID, $customerId)
            ->order(Rma::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }

    public function approveRma(int $rmaId): Rma
    {
        return $this->changeStatus($rmaId, self::STATUS_APPROVED);
    }

    public function rejectRma(int $rmaId): Rma
    {
        return $this->changeStatus($rmaId, self::STATUS_REJECTED);
    }

    protected function changeStatus(int $rmaId, string $status): Rma
    {
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);
        $rma->load($rmaId);

        if (!$rma->getId()) {
            throw new \RuntimeException((string) __('售后单不存在。'));
        }

        $rma->setData(Rma::schema_fields_STATUS, $status)
            ->setData(Rma::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $rma;
    }
}
