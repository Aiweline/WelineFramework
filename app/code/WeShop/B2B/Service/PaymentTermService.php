<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\PaymentTerm;
use Weline\Framework\Manager\ObjectManager;

class PaymentTermService
{
    public const TYPE_PREPAID = 'prepaid';
    public const TYPE_IN_ADVANCE = 'inadvance';
    public const TYPE_ARREARS = 'arrears';

    public function getTerm(int $termId): ?PaymentTerm
    {
        if ($termId <= 0) {
            return null;
        }

        /** @var PaymentTerm $model */
        $model = ObjectManager::getInstance(PaymentTerm::class);
        $model->load($termId);

        return $model->getId() ? $model : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveTerms(): array
    {
        /** @var PaymentTerm $model */
        $model = ObjectManager::getInstance(PaymentTerm::class);
        $model->clear()
            ->order(PaymentTerm::schema_fields_SORT_ORDER, 'ASC')
            ->order(PaymentTerm::schema_fields_ID, 'ASC');

        $rows = $model->select()->fetchArray();

        return \is_array($rows) ? $rows : [];
    }

    public function ensureDefaultTerms(): void
    {
        $existing = $this->listActiveTerms();
        if ($existing !== []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $defaults = [
            [__('Prepaid'), 0, self::TYPE_PREPAID, 0, 10],
            [__('Net 30'), 30, self::TYPE_ARREARS, 1, 20],
            [__('Net 60'), 60, self::TYPE_ARREARS, 1, 30],
            [__('Net 90'), 90, self::TYPE_ARREARS, 1, 40],
        ];

        foreach ($defaults as $row) {
            /** @var PaymentTerm $term */
            $term = ObjectManager::getInstance(PaymentTerm::class);
            $term->clearData()
                ->setData(PaymentTerm::schema_fields_TERM_NAME, (string) $row[0])
                ->setData(PaymentTerm::schema_fields_TERM_DAYS, (int) $row[1])
                ->setData(PaymentTerm::schema_fields_TERM_TYPE, (string) $row[2])
                ->setData(PaymentTerm::schema_fields_AUTO_INVOICE, (int) $row[3])
                ->setData(PaymentTerm::schema_fields_SORT_ORDER, (int) $row[4])
                ->setData(PaymentTerm::schema_fields_CREATED_AT, $now)
                ->setData(PaymentTerm::schema_fields_UPDATED_AT, $now)
                ->save();
        }
    }

    public function resolveTermDays(?PaymentTerm $term, int $accountOverrideDays): int
    {
        if ($accountOverrideDays > 0) {
            return $accountOverrideDays;
        }

        if ($term !== null && $term->getId()) {
            return max(0, (int) ($term->getData(PaymentTerm::schema_fields_TERM_DAYS) ?? 0));
        }

        return 0;
    }
}
