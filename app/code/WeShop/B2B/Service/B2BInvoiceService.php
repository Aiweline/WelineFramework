<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Receivable;
use WeShop\B2B\Model\Statement;
use Weline\Framework\Manager\ObjectManager;

/**
 * B2B billing statements (对账单).
 */
class B2BInvoiceService
{
    /**
     * @return array<string, mixed>
     */
    public function buildStatement(int $customerId, string $periodStart, string $periodEnd): array
    {
        /** @var Receivable $model */
        $model = ObjectManager::getInstance(Receivable::class);
        $model->clear()
            ->where(Receivable::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Receivable::schema_fields_DUE_DATE, $periodStart, '>=')
            ->where(Receivable::schema_fields_DUE_DATE, $periodEnd, '<=');

        $lines = $model->select()->fetchArray() ?: [];
        $total = 0.0;
        $ids = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $remaining = (float) ($line[Receivable::schema_fields_AMOUNT] ?? 0) - (float) ($line[Receivable::schema_fields_PAID_AMOUNT] ?? 0);
            $total += max(0.0, $remaining);
            $ids[] = (int) ($line[Receivable::schema_fields_ID] ?? 0);
        }

        $statementNo = 'ST-' . $customerId . '-' . date('YmdHis');
        $now = date('Y-m-d H:i:s');

        /** @var Statement $statement */
        $statement = ObjectManager::getInstance(Statement::class);
        $statement->clearData()
            ->setData(Statement::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Statement::schema_fields_STATEMENT_NO, $statementNo)
            ->setData(Statement::schema_fields_PERIOD_START, $periodStart)
            ->setData(Statement::schema_fields_PERIOD_END, $periodEnd)
            ->setData(Statement::schema_fields_TOTAL_AMOUNT, round($total, 2))
            ->setData(Statement::schema_fields_STATUS, 'draft')
            ->setData(Statement::schema_fields_LINE_DATA, json_encode($ids, JSON_THROW_ON_ERROR))
            ->setData(Statement::schema_fields_CREATED_AT, $now)
            ->setData(Statement::schema_fields_UPDATED_AT, $now)
            ->save();

        return [
            'statement_id' => (int) $statement->getId(),
            'statement_no' => $statementNo,
            'total' => round($total, 2),
            'lines' => $lines,
        ];
    }
}
