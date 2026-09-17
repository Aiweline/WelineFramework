<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\WinbackMailItemsHtmlBuilder;
use Weline\Marketing\Service\WinbackMailNotifier;

final class WinbackMailItemsHtmlBuilderTest extends TestCase
{
    public function testRendersProductBlockWithImageTitleOptionsPrice(): void
    {
        $html = (new WinbackMailItemsHtmlBuilder())->render([
            [
                'name' => '长安襦裙',
                'sku' => 'SKU-1',
                'qty' => 2,
                'unit_price' => '99.00',
                'row_total' => '198.00',
                'options_text' => '尺码: M · 颜色: 朱红',
                'image_url' => 'https://p05113ef3.test.weline.com:9555/pub/media/a.jpg',
            ],
        ], 'USD');
        self::assertStringContainsString('长安襦裙', $html);
        self::assertStringContainsString('尺码: M', $html);
        self::assertStringContainsString('USD 198.00', $html);
        self::assertStringContainsString('<img src="https://p05113ef3.test.weline.com:9555/pub/media/a.jpg"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testRelativeImageBecomesAbsoluteWithBase(): void
    {
        $html = (new WinbackMailItemsHtmlBuilder())->render([
            [
                'name' => 'Test',
                'qty' => 1,
                'unit_price' => '10.00',
                'row_total' => '10.00',
                'image_url' => '/pub/media/x.jpg',
            ],
        ], 'CNY', 'https://p05113ef3.test.weline.com:9555');
        self::assertStringContainsString(
            'src="https://p05113ef3.test.weline.com:9555/pub/media/x.jpg"',
            $html,
        );
    }

    public function testWelineTestAbsoluteImageRewritesOntoSiteBase(): void
    {
        $html = (new WinbackMailItemsHtmlBuilder())->render([
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
        self::assertSame('', (new WinbackMailItemsHtmlBuilder())->render([], 'CNY'));
    }

    public function testNotifierSourceInjectsItemsHtml(): void
    {
        $root = dirname(__DIR__, 3);
        $src = (string)file_get_contents($root . '/Service/WinbackMailNotifier.php');
        $provider = (string)file_get_contents($root . '/extends/MailChannelProvider.php');
        self::assertStringContainsString("'items_html'", $src);
        self::assertStringContainsString('WinbackMailItemsHtmlBuilder', $src);
        self::assertStringContainsString('WinbackMailItemsHtmlBuilder', $provider);
        self::assertStringNotContainsString("'<table>…</table>'", $provider);
        self::assertSame(WinbackMailNotifier::CHANNEL_UNPAID_ORDER_REMINDER, 'Weline_Marketing::unpaid_order_reminder');
    }
}
