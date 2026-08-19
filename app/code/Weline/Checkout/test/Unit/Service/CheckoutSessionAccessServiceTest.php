<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutSessionAccessService;

final class CheckoutSessionAccessServiceTest extends TestCase
{
    public function testGuestCapabilityOnlyAllowsAnOrderFromTheSubmittedSession(): void
    {
        $service = new CheckoutSessionAccessService($this->store([
            'state' => CheckoutSession::STATE_SUBMITTED,
            'customer_id' => null,
            'submitted_result' => [
                'checkout_group_uuid' => 'group-guest-1',
                'order_uuids' => ['order-guest-1'],
            ],
        ]));

        self::assertTrue($service->canAccess('qt_guest_capability', 'order-guest-1', null));
        self::assertTrue($service->canAccess('qt_guest_capability', 'order-guest-1', 42));
        self::assertFalse($service->canAccess('qt_guest_capability', 'order-other', null));
        self::assertFalse($service->canAccess('', 'order-guest-1', null));
    }

    public function testCustomerCapabilityMustMatchTheFrozenCustomer(): void
    {
        $service = new CheckoutSessionAccessService($this->store([
            'state' => CheckoutSession::STATE_SUBMITTED,
            'customer_id' => 42,
            'submitted_result' => [
                'checkout_group_uuid' => 'group-customer-1',
                'order_uuids' => ['order-customer-1'],
            ],
        ]));

        self::assertTrue($service->canAccess('qt_customer_capability', 'order-customer-1', 42));
        self::assertTrue($service->canAccess('qt_customer_capability', 'order-customer-1', null));
        self::assertFalse($service->canAccess('qt_customer_capability', 'order-customer-1', 7));
    }

    public function testNonSubmittedOrMissingSessionIsRejected(): void
    {
        $quoted = new CheckoutSessionAccessService($this->store([
            'state' => CheckoutSession::STATE_QUOTED,
            'customer_id' => null,
            'submitted_result' => ['order_uuids' => ['order-1']],
        ]));
        $missing = new CheckoutSessionAccessService($this->store(null));

        self::assertFalse($quoted->canAccess('qt_quoted', 'order-1', null));
        self::assertFalse($missing->canAccess('qt_missing', 'order-1', null));
    }

    public function testSuccessPageCapabilityBridgesIdentityRestorationWithoutWeakeningAccountOwnership(): void
    {
        $service = new CheckoutSessionAccessService($this->store(null));

        self::assertTrue($service->canReadOrder(42, null, true));
        self::assertTrue($service->canReadOrder(42, 42, false));
        self::assertFalse($service->canReadOrder(42, 7, false));
        self::assertTrue($service->canReadOrder(null, null, true));
        self::assertFalse($service->canReadOrder(null, null, false));
    }

    /** @param array<string, mixed>|null $session */
    private function store(?array $session): CheckoutSessionStoreInterface
    {
        return new class($session) implements CheckoutSessionStoreInterface {
            /** @param array<string, mixed>|null $session */
            public function __construct(private readonly ?array $session)
            {
            }

            public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void
            {
            }

            public function get(string $quoteToken): ?array
            {
                return $quoteToken === '' ? null : $this->session;
            }

            public function getForUpdate(string $quoteToken): ?array
            {
                return $this->get($quoteToken);
            }

            public function delete(string $quoteToken): bool
            {
                return false;
            }
        };
    }
}
