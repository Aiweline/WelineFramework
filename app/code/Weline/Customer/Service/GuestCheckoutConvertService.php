<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Service\CheckoutSessionAccessService;
use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Converts a guest checkout success capability into a storefront customer session.
 *
 * Existing emails never silent-login. Already-bound orders are treated as converted.
 */
final class GuestCheckoutConvertService
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly OrderFacadeInterface $orders,
        private readonly CheckoutSessionAccessService $checkoutAccess,
        private readonly CheckoutSessionStoreInterface $checkoutSessions,
    ) {
    }

    /**
     * @return array{
     *   outcome:string,
     *   message:string,
     *   redirect:?string,
     *   email:?string,
     *   attached_order_uuids:list<string>,
     *   can_convert?:bool
     * }
     */
    public function inspect(string $checkoutToken, string $orderUuid): array
    {
        $context = $this->resolveContext($checkoutToken, $orderUuid);
        if (($context['outcome'] ?? '') !== 'ok') {
            return $this->result(
                (string)$context['outcome'],
                (string)$context['message'],
                null,
                $context['email'] ?? null,
            );
        }

        /** @var list<string> $orderUuids */
        $orderUuids = $context['order_uuids'];
        $email = (string)$context['email'];
        if ($this->ordersAlreadyBound($orderUuids)) {
            if ($this->findBoundAnonymousForEmail($orderUuids, $email) !== null) {
                return $this->result('eligible', (string)\__('可以登录并保存订单'), null, $email, [], true);
            }

            return $this->result('already_bound', (string)\__('订单已关联账户'), null, $email);
        }

        if ($this->accounts->findByEmail($email) !== null) {
            $existing = $this->accounts->findByEmail($email);
            if ($existing instanceof Customer && $existing->isAnonymousAccount()) {
                return $this->result('eligible', (string)\__('可以登录并保存订单'), null, $email, [], true);
            }

            return $this->result(
                'login_required',
                (string)\__('该邮箱已有账户，请登录'),
                '/customer/account/login',
                $email,
            );
        }

        return $this->result('eligible', (string)\__('可以登录并保存订单'), null, $email, [], true);
    }

    /**
     * @return array{
     *   outcome:string,
     *   message:string,
     *   redirect:?string,
     *   email:?string,
     *   attached_order_uuids:list<string>,
     *   can_convert?:bool
     * }
     */
    public function convert(string $checkoutToken, string $orderUuid): array
    {
        $context = $this->resolveContext($checkoutToken, $orderUuid);
        if (($context['outcome'] ?? '') !== 'ok') {
            return $this->result(
                (string)$context['outcome'],
                (string)$context['message'],
                null,
                $context['email'] ?? null,
            );
        }

        /** @var list<string> $orderUuids */
        $orderUuids = $context['order_uuids'];
        $email = (string)$context['email'];
        if ($this->ordersAlreadyBound($orderUuids)) {
            $bound = $this->findBoundAnonymousForEmail($orderUuids, $email);
            if ($bound === null) {
                return $this->result(
                    'already_bound',
                    (string)\__('订单已关联账户'),
                    '/customer/account/index#orders',
                    $email,
                );
            }
            $bound->setAnonymousAccount(false)
                ->setMustSetPassword(true)
                ->save();
            $this->accounts->loginCustomer($bound);
            $attached = $this->orders->attachCustomerToGuestOrders((int)$bound->getId(), $orderUuids);

            return $this->result(
                'converted',
                (string)\__('已为您创建账户，请设置密码'),
                '/customer/account/set-password',
                $email,
                $attached,
            );
        }

        if ($this->accounts->findByEmail($email) !== null) {
            $existing = $this->accounts->findByEmail($email);
            if ($existing instanceof Customer && $existing->isAnonymousAccount()) {
                $existing->setAnonymousAccount(false)
                    ->setMustSetPassword(true)
                    ->save();
                $this->accounts->loginCustomer($existing);
                $attached = $this->orders->attachCustomerToGuestOrders((int)$existing->getId(), $orderUuids);

                return $this->result(
                    'converted',
                    (string)\__('已为您创建账户，请设置密码'),
                    '/customer/account/set-password',
                    $email,
                    $attached,
                );
            }
            return $this->result(
                'login_required',
                (string)\__('该邮箱已有账户，请登录'),
                '/customer/account/login',
                $email,
            );
        }

        $customer = $this->createPendingPasswordCustomer($email);
        $this->accounts->loginCustomer($customer);
        $attached = $this->orders->attachCustomerToGuestOrders((int)$customer->getId(), $orderUuids);

        return $this->result(
            'converted',
            (string)\__('已为您创建账户，请设置密码'),
            '/customer/account/set-password',
            $email,
            $attached,
        );
    }

    /**
     * When paid-bind already attached an anonymous customer for this email,
     * upgrade that account instead of treating the order as permanently bound.
     *
     * @param list<string> $orderUuids
     */
    private function findBoundAnonymousForEmail(array $orderUuids, string $email): ?Customer
    {
        $email = $this->accounts->normalizeEmail($email);
        foreach ($orderUuids as $uuid) {
            try {
                $order = $this->orders->get($uuid);
            } catch (\Throwable) {
                continue;
            }
            $customerId = $order->customerId !== null ? (int)$order->customerId : 0;
            if ($customerId <= 0) {
                continue;
            }
            $customer = $this->accounts->findByEmail($email);
            if (!$customer instanceof Customer || (int)$customer->getId() !== $customerId) {
                $customer = $this->customerById($customerId);
            }
            if ($customer instanceof Customer
                && $customer->isAnonymousAccount()
                && $this->accounts->normalizeEmail($customer->getEmail()) === $email
            ) {
                return $customer;
            }

            return null;
        }

        return null;
    }

    private function customerById(int $customerId): ?Customer
    {
        if ($customerId <= 0) {
            return null;
        }
        try {
            $customer = ObjectManager::getInstance(Customer::class);
            $customer->load($customerId);

            return $customer->getId() ? $customer : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(string $checkoutToken, string $orderUuid): array
    {
        $checkoutToken = trim($checkoutToken);
        $orderUuid = trim($orderUuid);
        if ($checkoutToken === '' || $orderUuid === ''
            || !$this->checkoutAccess->canAccess($checkoutToken, $orderUuid, null)
        ) {
            return [
                'outcome' => 'unavailable',
                'message' => (string)\__('结账凭证无效或已过期'),
            ];
        }

        try {
            $order = $this->orders->get($orderUuid);
        } catch (\Throwable) {
            return [
                'outcome' => 'unavailable',
                'message' => (string)\__('订单不存在'),
            ];
        }

        $email = $this->resolveOrderEmail($order);
        if ($email === '') {
            return [
                'outcome' => 'unavailable',
                'message' => (string)\__('订单缺少可用邮箱'),
            ];
        }

        $orderUuids = [$orderUuid];
        $session = $this->checkoutSessions->get($checkoutToken);
        $submitted = is_array($session['submitted_result'] ?? null) ? $session['submitted_result'] : [];
        $fromSession = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($submitted['order_uuids'] ?? []),
        )));
        if ($fromSession !== []) {
            $orderUuids = $fromSession;
        }

        return [
            'outcome' => 'ok',
            'email' => $email,
            'order_uuids' => $orderUuids,
        ];
    }

    private function resolveOrderEmail(OrderReadResult $order): string
    {
        $candidates = [
            (string)($order->customerEmail ?? ''),
            (string)($order->shipping['address']['email'] ?? ''),
            (string)($order->shipping['email'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $email = $this->accounts->normalizeEmail($candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    /** @param list<string> $orderUuids */
    private function ordersAlreadyBound(array $orderUuids): bool
    {
        foreach ($orderUuids as $uuid) {
            try {
                $order = $this->orders->get($uuid);
            } catch (\Throwable) {
                continue;
            }
            if ($order->customerId !== null && (int)$order->customerId > 0) {
                return true;
            }
        }

        return false;
    }

    private function createPendingPasswordCustomer(string $email): Customer
    {
        $password = bin2hex(random_bytes(24)) . 'Aa1';
        $created = $this->accounts->register($email, $password, [
            'first_name' => '',
            'last_name' => '',
        ]);
        /** @var Customer $customer */
        $customer = $created['customer'];
        $customer->setMustSetPassword(true)->save();

        return $customer;
    }

    /**
     * @param list<string> $attached
     * @return array{
     *   outcome:string,
     *   message:string,
     *   redirect:?string,
     *   email:?string,
     *   attached_order_uuids:list<string>,
     *   can_convert?:bool
     * }
     */
    private function result(
        string $outcome,
        string $message,
        ?string $redirect,
        ?string $email = null,
        array $attached = [],
        bool $canConvert = false,
    ): array {
        $payload = [
            'outcome' => $outcome,
            'message' => $message,
            'redirect' => $redirect,
            'email' => $email,
            'attached_order_uuids' => $attached,
        ];
        if ($canConvert) {
            $payload['can_convert'] = true;
        }

        return $payload;
    }
}
