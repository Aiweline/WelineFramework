<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Receivable;
use WeShop\Order\Model\Order;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class ReceivableService
{
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';

    public function __construct(
        private readonly AccountService $accountService,
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    public function getByOrderId(int $orderId): ?Receivable
    {
        if ($orderId <= 0) {
            return null;
        }

        /** @var Receivable $model */
        $model = ObjectManager::getInstance(Receivable::class);
        $model->clear()
            ->where(Receivable::schema_fields_ORDER_ID, $orderId)
            ->limit(1);
        $rows = $model->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }
        $first = $rows[0];
        if (!\is_array($first)) {
            return null;
        }
        $id = (int) ($first[Receivable::schema_fields_ID] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $model->clear()->load($id);

        return $model->getId() ? $model : null;
    }

    public function createFromCreditOrder(Order $order, int $customerId, float $amount, string $dueDate, string $invoiceNo = ''): Receivable
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Receivable amount must be positive.'));
        }
        $dueDate = trim($dueDate);
        if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || strtotime($dueDate) === false) {
            throw new \InvalidArgumentException((string) __('Due date format must be YYYY-MM-DD.'));
        }

        $orderId = (int) $order->getId();
        if ($orderId <= 0) {
            throw new \InvalidArgumentException((string) __('Order ID is required.'));
        }

        $existing = $this->getByOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        /** @var Receivable $rec */
        $rec = ObjectManager::getInstance(Receivable::class);
        $rec->clearData()
            ->setData(Receivable::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Receivable::schema_fields_ORDER_ID, $orderId)
            ->setData(Receivable::schema_fields_INVOICE_NO, $invoiceNo !== '' ? $invoiceNo : $this->generateInvoiceNo($order))
            ->setData(Receivable::schema_fields_AMOUNT, round(max(0.0, $amount), 2))
            ->setData(Receivable::schema_fields_PAID_AMOUNT, '0.00')
            ->setData(Receivable::schema_fields_DUE_DATE, $dueDate)
            ->setData(Receivable::schema_fields_OVERDUE_DAYS, 0)
            ->setData(Receivable::schema_fields_STATUS, self::STATUS_UNPAID)
            ->setData(Receivable::schema_fields_CREATED_AT, $now)
            ->setData(Receivable::schema_fields_UPDATED_AT, $now)
            ->save();

        $this->accountService->adjustBalance($customerId, -round(max(0.0, $amount), 2));

        $this->dispatch('WeShop_B2B::receivable_created', [
            'receivable_id' => (int) $rec->getId(),
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => $amount,
        ]);

        return $rec;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, pagination: array<string, mixed>}
     */
    public function getReceivableList(int $page, int $pageSize, array $filters = []): array
    {
        /** @var Receivable $model */
        $model = ObjectManager::getInstance(Receivable::class);
        $model->clear();
        if (!empty($filters['customer_id']) && (int) $filters['customer_id'] > 0) {
            $model->where(Receivable::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }
        if (!empty($filters['status']) && trim((string) $filters['status']) !== '') {
            $model->where(Receivable::schema_fields_STATUS, trim((string) $filters['status']));
        }
        $model->order(Receivable::schema_fields_DUE_DATE, 'ASC')
            ->pagination($page, $pageSize);

        return [
            'items' => $model->select()->fetchArray() ?: [],
            'total' => $model->getTotalCount(),
            'pagination' => $model->getPagination(),
        ];
    }

    public function refreshOverdueFlags(): int
    {
        /** @var Receivable $model */
        $model = ObjectManager::getInstance(Receivable::class);
        $model->clear()
            ->where(Receivable::schema_fields_STATUS, [self::STATUS_PAID], 'NOT IN');
        $rows = $model->select()->fetchArray();
        if (!\is_array($rows)) {
            return 0;
        }

        $today = date('Y-m-d');
        $updated = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $due = (string) ($row[Receivable::schema_fields_DUE_DATE] ?? '');
            if ($due === '' || $due >= $today) {
                continue;
            }
            $id = (int) ($row[Receivable::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $dueTs = strtotime($due);
            $days = $dueTs ? max(0, (int) floor((strtotime($today) - $dueTs) / 86400)) : 0;

            /** @var Receivable $one */
            $one = ObjectManager::getInstance(Receivable::class);
            $one->load($id);
            if (!$one->getId()) {
                continue;
            }
            $status = (string) ($one->getData(Receivable::schema_fields_STATUS) ?? '');
            if ($status === self::STATUS_PAID) {
                continue;
            }
            $one->setData(Receivable::schema_fields_OVERDUE_DAYS, $days)
                ->setData(Receivable::schema_fields_STATUS, self::STATUS_OVERDUE)
                ->setData(Receivable::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
            ++$updated;
        }

        return $updated;
    }

    private function generateInvoiceNo(Order $order): string
    {
        return 'B2B-' . (string) ($order->getData(Order::schema_fields_increment_id) ?? $order->getId());
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
