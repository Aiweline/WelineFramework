<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderCheckoutContactFields;

/**
 * On first paid projection: ensure an anonymous Customer for guest orders and
 * backfill notify-able contact fields from checkout / shipping / payment.
 */
final class AnonymousPaidCustomerBinder
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly OrderFacadeInterface $orders,
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata OrderPaidContext metadata
     * @return array{
     *   outcome:string,
     *   customer_id:?int,
     *   email:string,
     *   attached:bool
     * }
     */
    public function bindPaidOrder(string $orderUuid, array $metadata = []): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return $this->outcome('skipped_empty_uuid');
        }

        try {
            $order = $this->orders->get($orderUuid);
        } catch (\Throwable) {
            return $this->outcome('skipped_order_missing');
        }

        $existingCustomerId = $order->customerId !== null ? (int)$order->customerId : 0;
        $contact = $this->resolveContact($orderUuid, $order->toArray(), $metadata);
        $this->backfillOrderContact($orderUuid, $contact);

        if ($existingCustomerId > 0) {
            return $this->outcome('already_bound', $existingCustomerId, $contact['email']);
        }

        $email = $contact['email'];
        if ($email === '') {
            return $this->outcome('skipped_no_email', null, '');
        }

        $existing = $this->accounts->findByEmail($email);
        if ($existing instanceof Customer && (int)$existing->getId() > 0) {
            if (!$existing->isAnonymousAccount()) {
                // Registered account: keep order contact only; never silent-bind.
                return $this->outcome('registered_email_contact_only', null, $email);
            }
            $customerId = (int)$existing->getId();
            $this->orders->attachCustomerToGuestOrders($customerId, [$orderUuid]);

            return $this->outcome('reused_anonymous', $customerId, $email, true);
        }

        $customer = $this->createAnonymousCustomer($email, $contact['name']);
        $customerId = (int)$customer->getId();
        $this->orders->attachCustomerToGuestOrders($customerId, [$orderUuid]);

        return $this->outcome('created_anonymous', $customerId, $email, true);
    }

    /**
     * @param array<string, mixed> $orderArray
     * @param array<string, mixed> $metadata
     * @return array{email:string,name:string,phone:string}
     */
    private function resolveContact(string $orderUuid, array $orderArray, array $metadata): array
    {
        $shipping = [];
        if (isset($orderArray['shipping']) && is_array($orderArray['shipping'])) {
            $shipping = is_array($orderArray['shipping']['address'] ?? null)
                ? $orderArray['shipping']['address']
                : $orderArray['shipping'];
        }
        if ($shipping === [] && isset($orderArray['shipping_address'])) {
            $raw = $orderArray['shipping_address'];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $shipping = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $shipping = $raw;
            }
        }

        $projected = OrderCheckoutContactFields::project($shipping, [
            'guest_email' => (string)($orderArray['customer_email'] ?? ''),
            'customer_email' => (string)($orderArray['customer_email'] ?? ''),
            'customer_name' => (string)($orderArray['customer_name'] ?? ''),
            'customer_phone' => (string)($orderArray['customer_phone'] ?? ''),
            'payer_email' => (string)($metadata['payer_email'] ?? $metadata['payment_email'] ?? ''),
            'payment_email' => (string)($metadata['email'] ?? ''),
        ]);

        if ($projected['email'] === '') {
            $fromPayment = $this->paymentPayerEmail($metadata);
            if ($fromPayment !== '') {
                $projected['email'] = $fromPayment;
            }
        }

        if ($projected['name'] === '') {
            $projected['name'] = trim((string)($metadata['payer_name'] ?? $metadata['customer_name'] ?? ''));
        }
        if ($projected['phone'] === '') {
            $projected['phone'] = trim((string)($metadata['payer_phone'] ?? $metadata['customer_phone'] ?? ''));
        }

        return [
            'email' => $projected['email'],
            'name' => $projected['name'],
            'phone' => $projected['phone'],
        ];
    }

    /**
     * @param array{email:string,name:string,phone:string} $contact
     */
    private function backfillOrderContact(string $orderUuid, array $contact): void
    {
        if ($contact['email'] === '' && $contact['name'] === '' && $contact['phone'] === '') {
            return;
        }
        try {
            /** @var Order $model */
            $model = ($this->objectManager ?? ObjectManager::getInstance())->getInstance(Order::class);
            $hit = $model->reset()
                ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
                ->find()
                ->fetch();
            if (!$hit instanceof Order || !(int)$hit->getId()) {
                return;
            }
            $changed = false;
            if ($contact['email'] !== ''
                && trim((string)$hit->getData(Order::schema_fields_CUSTOMER_EMAIL)) === ''
            ) {
                $hit->setData(Order::schema_fields_CUSTOMER_EMAIL, $contact['email']);
                $changed = true;
            }
            if ($contact['name'] !== ''
                && trim((string)$hit->getData(Order::schema_fields_CUSTOMER_NAME)) === ''
            ) {
                $hit->setData(Order::schema_fields_CUSTOMER_NAME, $contact['name']);
                $changed = true;
            }
            if ($contact['phone'] !== ''
                && trim((string)$hit->getData(Order::schema_fields_CUSTOMER_PHONE)) === ''
            ) {
                $hit->setData(Order::schema_fields_CUSTOMER_PHONE, $contact['phone']);
                $changed = true;
            }
            if ($contact['email'] !== '') {
                $shippingRaw = $hit->getData(Order::schema_fields_SHIPPING_ADDRESS);
                $shipping = [];
                if (is_string($shippingRaw) && $shippingRaw !== '') {
                    $decoded = json_decode($shippingRaw, true);
                    $shipping = is_array($decoded) ? $decoded : [];
                } elseif (is_array($shippingRaw)) {
                    $shipping = $shippingRaw;
                }
                if (trim((string)($shipping['email'] ?? '')) === '') {
                    $shipping['email'] = $contact['email'];
                    $hit->setData(
                        Order::schema_fields_SHIPPING_ADDRESS,
                        json_encode($shipping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    );
                    $changed = true;
                }
            }
            if ($changed) {
                $hit->save();
            }
        } catch (\Throwable) {
            // Soft-fail: paid projection must not roll back for contact backfill.
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function paymentPayerEmail(array $metadata): string
    {
        $direct = OrderCheckoutContactFields::firstValidEmail([
            (string)($metadata['payer_email'] ?? ''),
            (string)($metadata['payment_email'] ?? ''),
            (string)($metadata['email'] ?? ''),
        ]);
        if ($direct !== '') {
            return $direct;
        }

        $txnId = (int)($metadata['payment_transaction_id'] ?? 0);
        if ($txnId <= 0 || !class_exists(\Weline\Payment\Model\PaymentTransaction::class)) {
            return '';
        }
        try {
            /** @var \Weline\Payment\Model\PaymentTransaction $txn */
            $txn = ($this->objectManager ?? ObjectManager::getInstance())
                ->getInstance(\Weline\Payment\Model\PaymentTransaction::class);
            $txn->load($txnId);
            if (!(int)$txn->getId()) {
                return '';
            }
            $bags = [];
            foreach (['getResponseData', 'getRequestData'] as $method) {
                if (!method_exists($txn, $method)) {
                    continue;
                }
                $data = $txn->{$method}();
                if (is_array($data)) {
                    $bags[] = $data;
                }
            }
            foreach ($bags as $bag) {
                $email = OrderCheckoutContactFields::firstValidEmail([
                    (string)($bag['payer_email'] ?? ''),
                    (string)($bag['email'] ?? ''),
                    (string)($bag['payer']['email_address'] ?? ''),
                    (string)($bag['express_profile']['email'] ?? ''),
                    (string)($bag['payload']['express_profile']['email'] ?? ''),
                    (string)($bag['payment_source']['paypal']['email_address'] ?? ''),
                ]);
                if ($email !== '') {
                    return $email;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param array{name?:string} $profile
     */
    private function createAnonymousCustomer(string $email, string $name = ''): Customer
    {
        $password = bin2hex(random_bytes(24)) . 'Aa1';
        $created = $this->accounts->register($email, $password, [
            'first_name' => $name,
            'last_name' => '',
        ]);
        /** @var Customer $customer */
        $customer = $created['customer'];
        $customer->setAnonymousAccount(true)
            ->setMustSetPassword(true)
            ->save();

        return $customer;
    }

    /**
     * @return array{outcome:string,customer_id:?int,email:string,attached:bool}
     */
    private function outcome(
        string $outcome,
        ?int $customerId = null,
        string $email = '',
        bool $attached = false,
    ): array {
        return [
            'outcome' => $outcome,
            'customer_id' => $customerId,
            'email' => $email,
            'attached' => $attached,
        ];
    }
}
