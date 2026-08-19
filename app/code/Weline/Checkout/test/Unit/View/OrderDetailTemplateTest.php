<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class OrderDetailTemplateTest extends TestCase
{
    public function testV2OrderProjectionRendersCustomerOrderEvidence(): void
    {
        $template = dirname(__DIR__, 3) . '/view/frontend/order/view.phtml';
        self::assertFileExists($template);

        $view = new class([
            'order' => [
                'order_uuid' => 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
                'checkout_group_uuid' => 'c447babc-f8dd-4f54-921c-55b89c1bcd3d',
                'display_number' => '0813194997',
                'status' => 'paid',
                'currency' => 'USD',
                'customer_id' => 7,
                'items' => [[
                    'name' => 'ZTOT Z6-MAX YBS300 PRO',
                    'sku' => 'ZTOT-Z6-MAX',
                    'qty_minor' => 1,
                    'unit_price_minor' => 289500,
                ]],
                'money' => [
                    'subtotal_minor' => 289500,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 289500,
                ],
            ],
        ]) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            /** @param array<string, scalar> $params */
            public function getUrl(string $path, array $params = []): string
            {
                $query = $params === [] ? '' : '?' . http_build_query($params);
                return '/USD/' . ltrim($path, '/') . $query;
            }

            public function escapeHtml(mixed $value): string
            {
                return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            }

            public function render(string $template): string
            {
                ob_start();
                include $template;
                return (string)ob_get_clean();
            }
        };

        try {
            $html = $view->render($template);
        } catch (\Throwable $throwable) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            self::fail('V2 order projection must render without legacy model calls: ' . $throwable->getMessage());
        }

        self::assertStringContainsString('data-checkout-order-view', $html);
        self::assertStringContainsString('weline-code="checkout.order.view.info"', $html);
        self::assertStringContainsString('weline-code="checkout.order.view.items"', $html);
        self::assertStringContainsString('weline-code="checkout.order.view.totals"', $html);
        self::assertStringContainsString('0813194997', $html);
        self::assertStringContainsString('已支付', $html);
        self::assertStringContainsString('ZTOT Z6-MAX YBS300 PRO', $html);
        self::assertStringContainsString('ZTOT-Z6-MAX', $html);
        self::assertStringContainsString('USD 2,895.00', $html);
        self::assertStringContainsString('/USD/customer/account/index#orders', $html);
    }
}
