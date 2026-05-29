<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\AccountOrdersListApiService;
use WeShop\Order\Service\AccountOrdersListContextService;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;

final class AccountOrdersListApiServiceTest extends TestCase
{
    public function testGuestPayloadUsesLazyUrlWithoutMutatingReadonlyProperty(): void
    {
        $serverKeys = ['HTTP_HOST', 'REQUEST_URI', 'REQUEST_SCHEME', 'SERVER_PORT'];
        $previousServerValues = [];
        foreach ($serverKeys as $serverKey) {
            $previousServerValues[$serverKey] = [
                'exists' => array_key_exists($serverKey, $_SERVER),
                'value' => $_SERVER[$serverKey] ?? null,
            ];
        }

        try {
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['REQUEST_URI'] = '/customer/account/index';
            $_SERVER['REQUEST_SCHEME'] = 'http';
            $_SERVER['SERVER_PORT'] = '80';

            $contextService = $this->createMock(AccountOrdersListContextService::class);
            $contextService->expects($this->never())->method('build');

            $service = new AccountOrdersListApiService($contextService);
            $payload = $service->buildPayload(0);

            $this->assertFalse($payload['success']);
            $this->assertArrayHasKey('redirect_url', $payload);
            $this->assertIsString($payload['redirect_url']);
            $this->assertStringContainsString('customer/account/login', $payload['redirect_url']);
        } finally {
            foreach ($previousServerValues as $serverKey => $previousServerValue) {
                if ($previousServerValue['exists']) {
                    $_SERVER[$serverKey] = $previousServerValue['value'];
                    continue;
                }

                unset($_SERVER[$serverKey]);
            }
        }
    }

    public function testEnglishPayloadLocalizesOrderCardLabelsAndSnapshotOptionLabels(): void
    {
        $serverKeys = ['WELINE_USER_LANG', 'WELINE_USER_CURRENCY'];
        $previousServerValues = [];
        foreach ($serverKeys as $serverKey) {
            $previousServerValues[$serverKey] = [
                'exists' => array_key_exists($serverKey, $_SERVER),
                'value' => $_SERVER[$serverKey] ?? null,
            ];
        }

        if (Context::hasCurrent()) {
            Context::leave();
        }

        try {
            RequestContext::init();
            WelineEnv::setLang('en_US');
            WelineEnv::setCurrency('CNY');
            ObjectManager::removeInstance(Request::class);
            ObjectManager::getInstance(Request::class)->setModules(['WeShop_Order']);
            Parser::clearWorkerCaches();

            $orderService = new class extends OrderService {
                public function getCustomerOrders(int $customerId, int $page = 1, int $pageSize = 20, array $filters = []): array
                {
                    return [
                        'items' => [[
                            Order::schema_fields_ID => 1001,
                            Order::schema_fields_increment_id => 'WS100001',
                            Order::schema_fields_status => self::STATUS_PAID,
                            'payment_status' => self::PAYMENT_STATUS_PAID,
                            Order::schema_fields_total => '1804.0',
                            Order::schema_fields_created_at => '2026-05-28 07:56:00',
                        ]],
                        'total' => 1,
                        'pagination' => [
                            'current_page' => $page,
                            'page_size' => $pageSize,
                            'total_pages' => 1,
                        ],
                    ];
                }

                public function getUnpaidOrders(int $customerId): array
                {
                    return [];
                }

                public function canRetryPayment(int $orderId, int $customerId): bool
                {
                    return false;
                }

                public function canCancelOrder(int $orderId, int $customerId): array
                {
                    return ['can_cancel' => false];
                }

                public function getOrderItems(int $orderId): array
                {
                    return [[
                        'product_name' => 'AirPods Pro MagSafe Case',
                        'product_sku' => 'ELEC-AIRPODS-PRO-MAGSAFE',
                        'quantity' => 1,
                        'price' => '1799.0',
                        'total' => '1799.0',
                        'options' => [[
                            'label' => '尺寸',
                            'value' => 'MagSafe',
                        ]],
                    ]];
                }
            };

            $service = new AccountOrdersListApiService(new AccountOrdersListContextService($orderService));
            $payload = $service->buildPayload(9);

            $this->assertTrue($payload['success']);
            $this->assertSame('Orders loaded', $payload['message']);
            $this->assertSame('Order #%{1}', $payload['labels']['order_number']);
            $this->assertSame('Order Amount:', $payload['labels']['amount_label']);
            $this->assertSame('View Details', $payload['labels']['view_details']);
            $this->assertSame('No cancellation', $payload['labels']['cannot_cancel']);
            $this->assertSame('Paid', $payload['orders'][0]['status_label']);
            $this->assertSame('Size', $payload['orders'][0]['summary_items'][0]['option_items'][0]['label']);
        } finally {
            Parser::clearWorkerCaches();
            ObjectManager::removeInstance(Request::class);
            RequestContext::cleanup();
            if (Context::hasCurrent()) {
                Context::leave();
            }

            foreach ($previousServerValues as $serverKey => $previousServerValue) {
                if ($previousServerValue['exists']) {
                    $_SERVER[$serverKey] = $previousServerValue['value'];
                    continue;
                }

                unset($_SERVER[$serverKey]);
            }
        }
    }
}
