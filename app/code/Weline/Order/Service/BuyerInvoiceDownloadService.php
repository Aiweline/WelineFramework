<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\TaxSnapshot;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\OrderItem;

/**
 * 只把当前顾客名下、已经开具的那张发票渲染成 PDF。
 */
final class BuyerInvoiceDownloadService
{
    private ?BuyerInvoiceSettings $settings;

    private ?BuyerInvoicePdfRenderer $renderer;

    private ?ObjectManager $objects;

    public function __construct(
        ?BuyerInvoiceSettings $settings = null,
        ?BuyerInvoicePdfRenderer $renderer = null,
        ?ObjectManager $objects = null,
    ) {
        $this->settings = $settings;
        $this->renderer = $renderer;
        $this->objects = $objects;
    }

    public function hasIssuedInvoice(string $orderUuid): bool
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return false;
        }
        $order = $this->loadOrder($orderUuid);

        return $order instanceof Order && $this->loadIssuedInvoice((int)$order->getId()) instanceof OrderInvoice;
    }

    public function bytesForCustomer(int $customerId, string $orderUuid): string
    {
        $orderUuid = trim($orderUuid);
        if ($customerId <= 0) {
            throw new BuyerInvoiceException('buyer_invoice_auth_required', 401, (string)__('请先登录'));
        }
        if ($orderUuid === '') {
            throw new BuyerInvoiceException('buyer_invoice_not_found', 404, (string)__('这张订单还没有可下载的发票'));
        }
        $settings = $this->settings();
        if (!$settings->isEnabled()) {
            throw new BuyerInvoiceException(
                'buyer_invoice_disabled',
                404,
                (string)__('买家发票下载未开启'),
            );
        }
        $seller = $settings->seller();
        if ($seller['name'] === '') {
            throw new BuyerInvoiceException(
                'buyer_invoice_seller_incomplete',
                409,
                (string)__('请先填写开票公司名称'),
            );
        }

        $order = $this->loadOrder($orderUuid);
        if (!$order instanceof Order) {
            throw new BuyerInvoiceException('buyer_invoice_not_found', 404, (string)__('这张订单还没有可下载的发票'));
        }
        $this->assertCustomerOwns($customerId, (int)$order->getData(Order::schema_fields_CUSTOMER_ID));
        $invoice = $this->loadIssuedInvoice((int)$order->getId());
        if (!$invoice instanceof OrderInvoice) {
            throw new BuyerInvoiceException('buyer_invoice_not_found', 404, (string)__('这张订单还没有可下载的发票'));
        }

        return $this->renderer()->render($this->document($order, $invoice, $seller));
    }

    public function filenameForCustomer(int $customerId, string $orderUuid): string
    {
        $order = $this->loadOrder($orderUuid);
        if (!$order instanceof Order) {
            return 'invoice.pdf';
        }
        $this->assertCustomerOwns($customerId, (int)$order->getData(Order::schema_fields_CUSTOMER_ID));
        $invoice = $this->loadIssuedInvoice((int)$order->getId());
        $number = $invoice instanceof OrderInvoice
            ? (string)$invoice->getData(OrderInvoice::schema_fields_INVOICE_NUMBER)
            : 'invoice';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) ?: 'invoice';

        return $safe . '.pdf';
    }

    public function assertCustomerOwns(int $customerId, int $orderCustomerId): void
    {
        if ($customerId <= 0) {
            throw new BuyerInvoiceException('buyer_invoice_auth_required', 401, (string)__('请先登录'));
        }
        if ($orderCustomerId <= 0 || $customerId !== $orderCustomerId) {
            throw new BuyerInvoiceException('buyer_invoice_forbidden', 403, (string)__('不能下载他人的发票'));
        }
    }

    /**
     * @param array{name:string,tax_id:string,address:string,phone:string,email:string} $seller
     */
    private function document(Order $order, OrderInvoice $invoice, array $seller): BuyerInvoiceDocument
    {
        $currency = (string)$order->getData(Order::schema_fields_CURRENCY);
        $lines = [];
        foreach ($this->items((int)$order->getId(), (string)$order->getData(Order::schema_fields_ORDER_UUID)) as $item) {
            $lines[] = [
                'name' => trim((string)$item->getData(OrderItem::schema_fields_PRODUCT_NAME)),
                'sku' => trim((string)$item->getData(OrderItem::schema_fields_PRODUCT_SKU)),
                'qty' => $this->qty((string)$item->getData(OrderItem::schema_fields_QTY_ORDERED)),
                'price' => $this->major((string)$item->getData(OrderItem::schema_fields_PRICE), $currency),
                'total' => $this->major((string)$item->getData(OrderItem::schema_fields_ROW_TOTAL), $currency),
            ];
        }
        $billing = $this->addressLine((string)$order->getData(Order::schema_fields_BILLING_ADDRESS));
        $shipping = $this->addressLine((string)$order->getData(Order::schema_fields_SHIPPING_ADDRESS));
        $sameAddress = $billing !== '' && $billing === $shipping;
        $buyer = $this->buyerIdentity($invoice);
        $discount = (float)$order->getData(Order::schema_fields_DISCOUNT_AMOUNT);
        $paymentStatus = trim((string)$order->getData(Order::schema_fields_PAYMENT_STATUS));

        return new BuyerInvoiceDocument(
            invoiceNumber: (string)$invoice->getData(OrderInvoice::schema_fields_INVOICE_NUMBER),
            issuedAt: (string)($invoice->getData(OrderInvoice::schema_fields_ISSUED_AT) ?: $invoice->getData(OrderInvoice::schema_fields_CREATED_AT)),
            orderNumber: (string)($order->getData(Order::schema_fields_ORDER_NUMBER) ?: $order->getData(Order::schema_fields_ORDER_UUID)),
            currency: $currency,
            sellerName: $seller['name'],
            sellerTaxId: $seller['tax_id'],
            sellerAddress: $seller['address'],
            sellerPhone: $seller['phone'],
            sellerEmail: $seller['email'],
            buyerName: trim((string)$order->getData(Order::schema_fields_CUSTOMER_NAME)),
            buyerContact: trim((string)$order->getData(Order::schema_fields_CUSTOMER_EMAIL)),
            buyerAddress: $shipping !== '' ? $shipping : $billing,
            lines: $lines,
            taxLabel: BuyerInvoiceDocument::money((int)$invoice->getData(OrderInvoice::schema_fields_TAX_AMOUNT_MINOR), $currency),
            totalLabel: BuyerInvoiceDocument::money((int)$invoice->getData(OrderInvoice::schema_fields_AMOUNT_MINOR), $currency),
            buyerPhone: trim((string)$order->getData(Order::schema_fields_CUSTOMER_PHONE)),
            buyerCompany: $buyer['company'],
            buyerTaxId: $buyer['tax_id'],
            shipTo: $shipping,
            billTo: $sameAddress ? '' : $billing,
            billSameAsShip: $sameAddress,
            subtotalLabel: $this->major((string)$order->getData(Order::schema_fields_SUBTOTAL), $currency),
            shippingLabel: $this->major((string)$order->getData(Order::schema_fields_SHIPPING_AMOUNT), $currency),
            discountLabel: $this->discountLabel($discount, $currency),
            paymentMethod: trim((string)$order->getData(Order::schema_fields_PAYMENT_METHOD)),
            paymentStatus: $paymentStatus === '' ? '' : Order::getPaymentStatusLabel($paymentStatus),
        );
    }

    /** @return array{company:string,tax_id:string} */
    private function buyerIdentity(OrderInvoice $invoice): array
    {
        $raw = $invoice->getData(OrderInvoice::schema_fields_TAX_SNAPSHOT_JSON);
        if (!is_string($raw) || trim($raw) === '') {
            return ['company' => '', 'tax_id' => ''];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['company' => '', 'tax_id' => ''];
        }
        $snapshot = TaxSnapshot::fromArray($data);

        return [
            'company' => trim($snapshot->buyerCompanyName),
            'tax_id' => trim($snapshot->buyerTaxId),
        ];
    }

    private function major(string $amount, string $currency): string
    {
        $minor = (int)round(((float)$amount) * 100);

        return BuyerInvoiceDocument::money($minor, $currency);
    }

    private function discountLabel(float $amount, string $currency): string
    {
        if (abs($amount) <= 0.00001) {
            return '';
        }
        $label = BuyerInvoiceDocument::money((int)round(abs($amount) * 100), $currency);
        $space = strpos($label, ' ');
        if ($space === false) {
            return '-' . $label;
        }

        return substr($label, 0, $space) . ' -' . substr($label, $space + 1);
    }

    private function settings(): BuyerInvoiceSettings
    {
        return $this->settings ??= new BuyerInvoiceSettings();
    }

    private function renderer(): BuyerInvoicePdfRenderer
    {
        return $this->renderer ??= new BuyerInvoicePdfRenderer();
    }

    private function objects(): ObjectManager
    {
        return $this->objects ??= ObjectManager::getInstance();
    }

    private function loadOrder(string $orderUuid): ?Order
    {
        /** @var Order $order */
        $order = $this->objects()->getInstance(Order::class);
        $order->clear()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!(int)$order->getId()) {
            return null;
        }
        if (!hash_equals($orderUuid, (string)$order->getData(Order::schema_fields_ORDER_UUID))) {
            return null;
        }

        return $order;
    }

    private function loadIssuedInvoice(int $orderId): ?OrderInvoice
    {
        if ($orderId <= 0) {
            return null;
        }
        /** @var OrderInvoice $invoice */
        $invoice = $this->objects()->getInstance(OrderInvoice::class);
        $invoice->clear()
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderId)
            ->where(OrderInvoice::schema_fields_STATUS, OrderInvoice::STATUS_ISSUED)
            ->find()
            ->fetch();
        if (!(int)$invoice->getId()) {
            return null;
        }

        return $invoice;
    }

    /** @return list<OrderItem> */
    private function items(int $orderId, string $orderUuid): array
    {
        /** @var OrderItem $model */
        $model = $this->objects()->getInstance(OrderItem::class);
        $rows = $model->clear()
            ->where(OrderItem::schema_fields_ORDER_ID, $orderId)
            ->select()
            ->fetch()
            ->getItems();
        $items = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof OrderItem));
        if ($items !== [] || $orderUuid === '') {
            return $items;
        }
        $rows = $model->clear()
            ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetch()
            ->getItems();

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof OrderItem));
    }

    private function qty(string $qty): string
    {
        if (!is_numeric($qty)) {
            return $qty;
        }
        $formatted = rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function addressLine(string $json): string
    {
        $json = trim($json);
        if ($json === '') {
            return '';
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $parts = [];
        foreach (['country', 'country_code', 'province', 'region', 'city', 'district', 'address1', 'address', 'street', 'address2', 'postal_code', 'postcode'] as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $value = implode(' ', array_filter($value, 'is_scalar'));
            }
            if (is_scalar($value) && trim((string)$value) !== '') {
                $parts[] = trim((string)$value);
            }
        }

        return implode(' ', $parts);
    }
}
