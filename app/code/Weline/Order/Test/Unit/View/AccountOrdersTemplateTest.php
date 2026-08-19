<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrdersTemplateTest extends TestCase
{
    public function testSummaryOrderLinksToItsCanonicalV2DetailPage(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($template);

        $view = new class {
            /** @param array<string, scalar> $params */
            public function getUrl(string $path, array $params = []): string
            {
                $query = $params === [] ? '' : '?' . http_build_query($params);
                return '/USD/' . ltrim($path, '/') . $query;
            }

            /** @param array<string, mixed> $data */
            public function render(string $template, array $data): string
            {
                extract($data, EXTR_SKIP);
                ob_start();
                include $template;
                return (string)ob_get_clean();
            }
        };

        $html = $view->render($template, [
            'accountCheckoutGroups' => [[
                'group_uuid' => 'c447babc-f8dd-4f54-921c-55b89c1bcd3d',
                'display_number' => 'G-c447babc',
                'status' => 'paid',
                'grand_total_minor' => 289500,
                'currency' => 'USD',
                'orders' => [[
                    'order_uuid' => 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
                    'display_number' => '0813194997',
                    'status' => 'paid',
                    'amount_minor' => 289500,
                    'refund_status' => 'none',
                    'invoice_status' => 'none',
                    'fulfillment_status' => 'none',
                ]],
            ]],
        ]);

        self::assertStringContainsString('data-order-detail-link="true"', $html);
        self::assertStringContainsString('/USD/customer/account/index?order_uuid=f783cdc9-ad19-4a50-9137-eb9cea4741a6#orders', $html);
        self::assertStringContainsString('查看详情', $html);
    }

    public function testOwnedV2OrderDetailRendersInsideTheAccountOrdersSection(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($template);

        $view = new class {
            /** @param array<string, scalar> $params */
            public function getUrl(string $path, array $params = []): string
            {
                $query = $params === [] ? '' : '?' . http_build_query($params);
                return '/USD/' . ltrim($path, '/') . $query;
            }

            /** @param array<string, mixed> $data */
            public function render(string $template, array $data): string
            {
                extract($data, EXTR_SKIP);
                ob_start();
                include $template;
                return (string)ob_get_clean();
            }
        };

        $html = $view->render($template, [
            'accountCheckoutGroups' => [],
            'accountOrderDetail' => [
                'order_uuid' => 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
                'display_number' => '0813194997',
                'status' => 'paid',
                'currency' => 'USD',
                'items' => [[
                    'name' => 'ZTOT Z6-MAX YBS300 PRO',
                    'sku' => 'ZTOT-Z6-MAX',
                    'qty_minor' => 1,
                    'unit_price_minor' => 289500,
                    'row_total_minor' => 289500,
                ]],
                'money' => [
                    'subtotal_minor' => 289500,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 289500,
                ],
                'shipping' => [
                    'method' => 'LOCAL_DEVELOPMENT',
                    'address' => [
                        'name' => 'FCDC Dealer QA',
                        'phone' => '13800138000',
                        'country_code' => 'CN',
                        'province' => 'Zhejiang',
                        'city' => 'Taizhou',
                        'address1' => 'Development Test Address 1',
                        'postal_code' => '318000',
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('data-account-order-detail="true"', $html);
        self::assertStringContainsString('weline-code="order.account.detail.section_1"', $html);
        self::assertStringContainsString('0813194997', $html);
        self::assertStringContainsString('已支付', $html);
        self::assertStringContainsString('ZTOT Z6-MAX YBS300 PRO', $html);
        self::assertStringContainsString('ZTOT-Z6-MAX', $html);
        self::assertStringContainsString('USD 2,895.00', $html);
        self::assertStringContainsString('data-order-shipping-address="true"', $html);
        self::assertStringContainsString('收货信息', $html);
        self::assertStringContainsString('FCDC Dealer QA', $html);
        self::assertStringContainsString('Development Test Address 1', $html);
        self::assertStringContainsString('Taizhou', $html);
        self::assertStringContainsString('/USD/customer/account/index#orders', $html);
        self::assertStringContainsString('返回订单列表', $html);
    }

    public function testOrderDetailAvoidsTagsReservedByTheWelineTemplateCompiler(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($template);

        $source = (string) file_get_contents($template);
        self::assertStringNotContainsString('<dd', $source);
        self::assertStringNotContainsString('<table', $source);
        self::assertStringContainsString('role="table"', $source);
        self::assertStringContainsString('data-order-item-count=', $source);
    }
}
