<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\FactoryPageParser;

final class FactoryPageParserTest extends TestCase
{
    public function testParsesFactoryIdentityFirstPageAndNaturalNextPage(): void
    {
        $pageData = [
            '3' => ['initShopInfo' => [
                'memberId' => 'b2b-123',
                'name' => '曹县柒依阁服饰有限公司',
                'shopPcWpIndexUrl' => 'https://shop123.1688.com',
                'loginId' => '山东柒依阁服饰',
            ]],
            '8' => ['initOfferList' => [
                'total' => 3,
                'page' => 1,
                'pageSize' => 2,
                '__params__' => ['factoryMemberId' => 'b2b-123'],
                'data' => [
                    ['offerId' => '2', 'title' => '二号汉服', 'price' => ['minPrice' => '20.00'], 'itemPictureList' => ['https://cbu01.alicdn.com/2.jpg']],
                    ['offerId' => '1', 'title' => '一号汉服', 'price' => ['minPrice' => '10.00'], 'itemPictureList' => ['https://cbu01.alicdn.com/1.jpg']],
                ],
            ], 'initShopInfo' => '$ref[3].initShopInfo'],
        ];
        $html = '<html><title>柒依阁</title><script>window.$$pageData=' . json_encode(
            $pageData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . '; window.other={};</script></html>';

        $parsed = (new FactoryPageParser())->parse(
            $html,
            'https://www.1688.com/factory/b2b-123.html',
        );

        self::assertSame('b2b-123', $parsed['member_id']);
        self::assertSame('曹县柒依阁服饰有限公司', $parsed['company_name']);
        self::assertSame('https://shop123.1688.com', $parsed['shop_url']);
        self::assertSame(3, $parsed['total']);
        self::assertSame(2, $parsed['next_page']);
        self::assertSame(['1', '2'], array_column($parsed['offers'], 'offer_id'));
    }

    public function testCaptchaPageFailsClosed(): void
    {
        $this->expectExceptionMessage('hanfu_1688_factory_page_blocked');
        (new FactoryPageParser())->parse(
            '<script>window._config_={"action":"captcha"}</script>',
            'https://www.1688.com/factory/b2b-123.html',
        );
    }
}
