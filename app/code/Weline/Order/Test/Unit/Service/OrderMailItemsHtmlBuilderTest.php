<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderMailItemsHtmlBuilder;
use Weline\Order\Service\OrderMailNotifier;

final class OrderMailItemsHtmlBuilderTest extends TestCase
{
    public function testRendersProductBlockWithImageTitleOptionsPrice(): void
    {
        $html = (new OrderMailItemsHtmlBuilder())->render([
            [
                'name' => '长安襦裙',
                'sku' => 'SKU-1',
                'qty' => 2,
                'unit_price' => '99.00',
                'row_total' => '198.00',
                'options_text' => '尺码: M · 颜色: 朱红',
                'image_url' => 'https://p05113ef3.test.weline.com:9555/pub/media/a.jpg',
            ],
        ], 'CNY');
        self::assertStringContainsString('长安襦裙', $html);
        self::assertStringContainsString('尺码: M', $html);
        self::assertStringContainsString('¥198.00', $html);
        self::assertStringContainsString('<img src="https://p05113ef3.test.weline.com:9555/pub/media/a.jpg"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testWelineTestAbsoluteImageRewritesOntoSiteBase(): void
    {
        $html = (new OrderMailItemsHtmlBuilder())->render([
            [
                'name' => '长安襦裙',
                'qty' => 1,
                'unit_price' => '99.00',
                'row_total' => '99.00',
                'image_url' => 'https://e2e-test-1783791034.weline.test:9555/pub/media/catalog/product/placeholder.jpg',
            ],
        ], 'CNY', 'https://p05113ef3.test.weline.com:9555');
        self::assertStringContainsString(
            'src="https://p05113ef3.test.weline.com:9555/pub/media/catalog/product/placeholder.jpg"',
            $html,
        );
        self::assertStringNotContainsString('e2e-test-1783791034.weline.test', $html);
    }

    public function testEmptyLinesYieldEmptyHtml(): void
    {
        self::assertSame('', (new OrderMailItemsHtmlBuilder())->render([], 'CNY'));
    }

    public function testNotifierAndCreatedTemplateWireItemsHtml(): void
    {
        $notifier = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/OrderMailNotifier.php');
        self::assertStringContainsString("'items_html'", $notifier);
        self::assertStringContainsString('OrderMailItemsHtmlBuilder', $notifier);
        self::assertStringContainsString('UnpaidOrderMailLineProjector', $notifier);
        self::assertSame(OrderMailNotifier::CHANNEL_CREATED, 'Weline_Order::order_created');

        $zh = (string)file_get_contents(dirname(__DIR__, 3) . '/view/email/order_created/zh_Hans_CN.html');
        self::assertStringContainsString('{{#if var.items_html}}', $zh);
        self::assertStringContainsString('{{var.items_html|raw}}', $zh);
        self::assertStringContainsString('商品明细', $zh);

        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/MailChannelProvider.php');
        self::assertStringContainsString("'code' => 'items_html'", $provider);
        self::assertStringContainsString("'code' => 'currency'", $provider);
    }
}
