<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Subscription\Service\SubscriptionBuyerService;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

final class SubscriptionBuyerServiceTest extends TestCase
{
    private SubscriptionBuyerService $buyers;

    protected function setUp(): void
    {
        $subscriptions = SubscriptionService::forTesting();
        $subscriptions->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $this->buyers = SubscriptionBuyerService::forTesting($subscriptions);
    }

    public function testBuyerCanSubscribeListRenewAndCancelOwnSubscription(): void
    {
        $created = $this->buyers->subscribe([
            'customer_id' => '42',
            'website_id' => 0,
            'store_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_buyer',
            'idempotency_key' => 'buyer-idem-0001',
            'environment' => 'sandbox',
        ]);
        self::assertTrue($created['success']);
        self::assertArrayNotHasKey('cas_token', $created['subscription']);
        $id = (string)$created['subscription']['subscription_id'];

        $listed = $this->buyers->listForCustomer('42', 0);
        self::assertCount(1, $listed);
        self::assertSame($id, $listed[0]['subscription_id']);
        self::assertSame([], $this->buyers->listForCustomer('7', 0));

        $renewed = $this->buyers->renew($id, '42');
        self::assertTrue($renewed['success']);
        self::assertSame($id, $renewed['subscription_id']);

        $owned = $this->buyers->presentOwned($id, '42');
        $cancelled = $this->buyers->cancel($id, '42', (int)$owned['version']);
        self::assertSame('cancelled', $cancelled['subscription']['status']);
    }

    public function testOtherCustomerCannotRenewOrCancel(): void
    {
        $created = $this->buyers->subscribe([
            'customer_id' => '42',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan_private',
            'idempotency_key' => 'buyer-idem-0002',
        ]);
        $id = (string)$created['subscription']['subscription_id'];

        $this->expectException(SubscriptionConflictException::class);
        $this->buyers->renew($id, '7');
    }

    public function testStorefrontQueryDoesNotAcceptClientCustomerIdentity(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/SubscriptionStorefrontQueryProvider.php');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Buyer.php');

        self::assertStringNotContainsString("params['customer_id']", $source);
        self::assertStringNotContainsString("params['website_id']", $source);
        self::assertStringContainsString('createFrontendSession', $source);
        self::assertStringContainsString("'auth' => 'customer'", $source);
        self::assertDoesNotMatchRegularExpression('/function post[A-Z]/', $controller);
        self::assertStringContainsString('getRenew', $controller);
        self::assertStringContainsString('getCancel', $controller);
        $buyer = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/SubscriptionBuyerService.php');
        self::assertStringContainsString('SubscriptionSandboxBilling', $buyer);
        self::assertStringNotContainsString('SubscriptionAdminService', $buyer);
    }
}
