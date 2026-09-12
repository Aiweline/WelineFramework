<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentLinkServiceInterface;
use Weline\Payment\Service\PaymentLinkRecordRepository;
use Weline\Payment\Service\PaymentLinkService;

final class PaymentLinkServiceTest extends TestCase
{
    private function svc(): PaymentLinkService
    {
        return new PaymentLinkService(
            new PaymentLinkRecordRepository(sys_get_temp_dir() . '/helppay-ut-' . uniqid('', true) . '.json')
        );
    }

    public function testHelpPayCreateResolveOmitsShippingAndLocksFlag(): void
    {
        $svc = $this->svc();
        $created = $svc->create([
            'kind' => PaymentLinkServiceInterface::KIND_HELP_PAY,
            'payable_type' => 'order',
            'payable_id' => '1001',
            'owner_customer_id' => 9,
            'amount_minor' => 1299,
            'currency_code' => 'usd',
            'shipping_locked' => true,
            'shipping_snapshot' => [
                'name' => 'Alice',
                'phone' => '13800000000',
                'line1' => 'Secret Rd 1',
            ],
            'ttl_seconds' => 3600,
        ], 'https://shop.example');

        self::assertSame(PaymentLinkServiceInterface::KIND_HELP_PAY, $created['kind']);
        self::assertTrue($created['shipping_locked']);
        self::assertStringStartsWith('https://shop.example/h/', $created['absolute_url']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,}$/', $created['token']);

        $resolved = $svc->resolve($created['token'], PaymentLinkServiceInterface::KIND_HELP_PAY);
        self::assertNotNull($resolved);
        self::assertArrayNotHasKey('shipping_snapshot', $resolved);
        self::assertTrue((bool) ($resolved['shipping_redacted'] ?? false));
        self::assertSame(1299, (int) ($resolved['amount_minor'] ?? 0));

        $shipping = $svc->resolveShippingForFulfillment(
            $created['token'],
            PaymentLinkServiceInterface::KIND_HELP_PAY
        );
        self::assertSame('Alice', $shipping['name'] ?? null);
    }

    public function testSelectionShareUsesSPathAndNoShippingLockByDefault(): void
    {
        $svc = $this->svc();
        $created = $svc->create([
            'kind' => PaymentLinkServiceInterface::KIND_SELECTION_SHARE,
            'selection_snapshot' => [
                'lines' => [['sku' => 'A', 'qty' => 2]],
            ],
        ], 'https://shop.example');

        self::assertStringStartsWith('https://shop.example/s/', $created['absolute_url']);
        self::assertFalse($created['shipping_locked']);
        $resolved = $svc->resolve($created['token'], PaymentLinkServiceInterface::KIND_SELECTION_SHARE);
        self::assertNotNull($resolved);
        self::assertSame([['sku' => 'A', 'qty' => 2]], $resolved['selection_snapshot']['lines'] ?? null);
    }

    public function testRevokeBlocksResolve(): void
    {
        $svc = $this->svc();
        $created = $svc->create([
            'kind' => PaymentLinkServiceInterface::KIND_HELP_PAY,
            'owner_customer_id' => 3,
        ]);
        self::assertTrue($svc->revoke($created['token'], PaymentLinkServiceInterface::KIND_HELP_PAY, 3));
        self::assertNull($svc->resolve($created['token'], PaymentLinkServiceInterface::KIND_HELP_PAY));
    }

    public function testExpiredTokenRejected(): void
    {
        $path = sys_get_temp_dir() . '/helppay-ut-' . uniqid('', true) . '.json';
        $repo = new PaymentLinkRecordRepository($path);
        $svc = new PaymentLinkService($repo);
        $created = $svc->create([
            'kind' => PaymentLinkServiceInterface::KIND_QUICK_PAY,
            'ttl_seconds' => 60,
        ]);
        $row = $repo->findByToken(PaymentLinkServiceInterface::KIND_QUICK_PAY, $created['token']);
        self::assertIsArray($row);
        $row['expires_at'] = time() - 10;
        $repo->save($row);
        self::assertNull($svc->resolve($created['token'], PaymentLinkServiceInterface::KIND_QUICK_PAY));
    }

    public function testDiskReviveBeatsStaleInMemoryExpired(): void
    {
        $path = sys_get_temp_dir() . '/helppay-ut-' . uniqid('', true) . '.json';
        $repo = new PaymentLinkRecordRepository($path);
        $svc = new PaymentLinkService($repo);
        $created = $svc->create([
            'kind' => PaymentLinkServiceInterface::KIND_QUICK_PAY,
            'amount_minor' => 100,
            'ttl_seconds' => 3600,
        ]);
        $token = $created['token'];
        $row = $repo->findByToken(PaymentLinkServiceInterface::KIND_QUICK_PAY, $token);
        self::assertIsArray($row);
        $row['status'] = PaymentLinkServiceInterface::STATUS_EXPIRED;
        $row['expires_at'] = time() - 5;
        $repo->save($row);
        self::assertNull($svc->resolve($token, PaymentLinkServiceInterface::KIND_QUICK_PAY));

        $row['status'] = PaymentLinkServiceInterface::STATUS_ACTIVE;
        $row['expires_at'] = time() + 86400;
        $repo->save($row);
        $again = $svc->resolve($token, PaymentLinkServiceInterface::KIND_QUICK_PAY);
        self::assertNotNull($again);
        self::assertSame(100, (int) ($again['amount_minor'] ?? 0));
    }
}
