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
        self::assertStringContainsString('data-order-uuid="f783cdc9-ad19-4a50-9137-eb9cea4741a6"', $html);
        self::assertStringContainsString("['order_uuid' => \$primaryOrderUuid]", (string) file_get_contents($template));
        self::assertStringContainsString('#orders', $html);
        self::assertStringContainsString('查看详情', $html);
        self::assertStringNotContainsString('data-continue-pay="true"', $html);
    }

    public function testPendingOrderRendersContinuePayCta(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        $source = (string) file_get_contents($template);
        self::assertStringContainsString('data-testid="account-order-continue-pay"', $source);
        self::assertStringContainsString('data-continue-pay="true"', $source);
        self::assertStringContainsString("\$view['continue_pay_reachable']", $source);
        self::assertStringContainsString('继续支付', $source);

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
                'group_uuid' => 'pending-group-1',
                'display_number' => 'G-pending1',
                'status' => 'pending',
                'grand_total_minor' => 54000,
                'currency' => 'CNY',
                'continue_pay_url' => '/checkout#payment-recovery?quote_token=qt1&idempotency_key=idem1&payment_method=paypal&order_uuid=ord-pending-1&recoverable=1',
                'continue_pay_reachable' => true,
                'orders' => [[
                    'order_uuid' => 'ord-pending-1',
                    'display_number' => 'pending-1',
                    'status' => 'pending',
                    'amount_minor' => 54000,
                    'refund_status' => 'none',
                    'invoice_status' => 'none',
                    'fulfillment_status' => 'none',
                    'order_type' => 'toc',
                ]],
            ]],
        ]);

        self::assertStringContainsString('data-continue-pay="true"', $html);
        self::assertStringContainsString('data-testid="account-order-continue-pay"', $html);
        self::assertStringContainsString('#payment-recovery?', $html);
        self::assertStringContainsString('继续支付', $html);
    }

    public function testOrdersTemplateDeclaresOrderTypeBadgeMarkup(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        $source = (string) file_get_contents($template);
        self::assertStringContainsString('data-testid="account-order-type-badge"', $source);
        self::assertStringContainsString('data-testid="account-order-detail-type-badge"', $source);
        self::assertStringContainsString('account-orders__badge--type', $source);
        self::assertStringContainsString("\$view['order_type_label']", $source);
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
        self::assertStringContainsString('data-account-orders-back="true"', $html);
        self::assertStringContainsString('#orders', $html);
        self::assertStringContainsString('返回订单列表', $html);
    }

    public function testOrderDetailIncludesHangCtaWhenAwaitingBalance(): void
    {
        $template = dirname(__DIR__, 3) . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        $source = (string) file_get_contents($template);
        self::assertStringContainsString('account-order-hang.phtml', $source);
        self::assertStringContainsString('$detailHang', $source);
        self::assertStringContainsString('account-orders__hang', $source);
        self::assertStringContainsString('data-account-orders-hang="true"', $source);
        self::assertStringContainsString('account-orders__accordion', $source);
        self::assertStringContainsString('data-testid="account-orders-accordion"', $source);
        self::assertStringContainsString('account-orders__panel', $source);
        self::assertStringContainsString('data-chat-embedded="1"', $source);
        // Hang lives in the accordion panel (below summary), not inside the summary flex row.
        self::assertMatchesRegularExpression(
            '/account-orders__panel[\s\S]*?account-orders__hang/s',
            $source
        );

        $view = new class {
            /** @param array<string, scalar> $params */
            public function getUrl(string $path, array $params = []): string
            {
                $query = $params === [] ? '' : '?' . http_build_query($params);

                return '/CNY/' . ltrim($path, '/') . $query;
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
                'group_uuid' => 'g-hang-1',
                'display_number' => 'G-hang',
                'status' => 'pending',
                'grand_total_minor' => 11500,
                'currency' => 'CNY',
                'orders' => [[
                    'order_uuid' => 'ord-hang-balance-1',
                    'display_number' => 'TOB-1',
                    'status' => 'pending',
                    'amount_minor' => 11500,
                    'status_label' => '待支付',
                    'total_label' => 'CNY 115.00',
                    'refund_label' => '',
                    'invoice_label' => '',
                    'fulfillment_label' => '',
                    'hang' => [
                        'hang_status' => 'awaiting_balance',
                        'order_ref' => 'ord-hang-balance-1',
                        'deposit_amount_minor' => 3000,
                        'balance_amount_minor' => 8500,
                    ],
                ]],
            ]],
            'accountOrderDetail' => [
                'order_uuid' => 'ord-hang-balance-1',
                'display_number' => 'TOB-1',
                'status' => 'pending',
                'currency' => 'CNY',
                'items' => [],
                'money' => [
                    'subtotal_minor' => 10000,
                    'shipping_amount_minor' => 1500,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 11500,
                ],
                'shipping' => [],
            ],
        ]);

        self::assertStringContainsString('data-testid="b2b-pay-balance"', $html);
        self::assertStringContainsString('purpose=balance', $html);
        self::assertStringContainsString('ord-hang-balance-1', $html);
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
