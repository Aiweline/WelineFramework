<?php
declare(strict_types=1);

use WeShop\Invoice\Service\InvoiceService;
use WeShop\Order\Model\Order;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function fixtureFail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

try {
    /** @var Order $orderModel */
    $orderModel = ObjectManager::getInstance(Order::class);
    $rows = $orderModel->clear()
        ->select(Order::schema_fields_ID)
        ->fetchArray();

    $orderId = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = (int)($row[Order::schema_fields_ID] ?? 0);
        if ($candidate > 0) {
            $orderId = $candidate;
            break;
        }
    }

    if ($orderId <= 0) {
        $now = date('Y-m-d H:i:s');
        $incrementId = 'E2E-ORD-' . date('YmdHis') . '-' . random_int(1000, 9999);
        /** @var Order $newOrder */
        $newOrder = ObjectManager::getInstance(Order::class);
        $newOrder->clearData()
            ->setData(Order::schema_fields_increment_id, $incrementId)
            ->setData(Order::schema_fields_customer_id, 1)
            ->setData(Order::schema_fields_status, 'pending')
            ->setData(Order::schema_fields_total, 99.99)
            ->setData(Order::schema_fields_subtotal, 99.99)
            ->setData(Order::schema_fields_shipping_amount, 0)
            ->setData(Order::schema_fields_discount_amount, 0)
            ->setData(Order::schema_fields_tax_amount, 0)
            ->setData(Order::schema_fields_created_at, $now)
            ->setData(Order::schema_fields_updated_at, $now)
            ->save();

        $orderId = (int)$newOrder->getId();
    }

    if ($orderId <= 0) {
        fixtureFail('Unable to resolve or create order fixture.');
    }

    /** @var InvoiceService $invoiceService */
    $invoiceService = ObjectManager::getInstance(InvoiceService::class);
    $invoiceNumber = 'E2E-INV-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $invoice = $invoiceService->createInvoice([
        'order_id' => $orderId,
        'invoice_number' => $invoiceNumber,
        'amount' => 99.99,
        'status' => InvoiceService::STATUS_PENDING,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    echo json_encode([
        'invoice_id' => (int)$invoice->getId(),
        'invoice_number' => $invoiceNumber,
        'order_id' => $orderId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (Throwable $throwable) {
    fixtureFail('Invoice pending fixture failed: ' . $throwable->getMessage());
}
