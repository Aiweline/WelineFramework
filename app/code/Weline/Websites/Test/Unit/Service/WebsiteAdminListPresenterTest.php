<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAdminListPresenter;

final class WebsiteAdminListPresenterTest extends TestCase
{
    public function testPresentRowMergesColumnsAndSummaries(): void
    {
        $presenter = new WebsiteAdminListPresenter();
        $row = $presenter->presentRow([
            'website_id' => 2,
            'name' => 'E2E Theme Default',
            'code' => 'e2e_default_injection_mso3wvvv_2',
            'default_timezone' => 'Asia/Shanghai',
            'default_currency' => 'CNY',
            'url' => 'http://e2e-theme-default.test',
            'frontend_url' => 'http://e2e-theme-default.test',
            'backend_url' => 'http://backend.example/admin',
            'currency_codes' => ['CNY'],
            'language_codes' => [],
            'domain_list' => [
                ['domain' => 'e2e-theme-default.test', 'is_primary' => true],
                ['domain' => 'www.e2e-theme-default.test', 'is_primary' => false],
            ],
            'store_channel_directory' => [
                [
                    'name' => '默认店铺',
                    'code' => 'default',
                    'is_default' => true,
                    'channels' => [
                        ['name' => '默认渠道', 'code' => 'default'],
                    ],
                ],
            ],
        ]);

        self::assertSame(
            'E2E Theme Default · e2e_default_injection_mso3wvvv_2 · #2',
            $row['site_head'],
        );
        self::assertSame('Asia/Shanghai', $row['site_meta']);
        self::assertStringContainsString('http://e2e-theme-default.test', $row['access_entry']);
        self::assertStringContainsString('+1', $row['access_entry']);
        self::assertStringContainsString('全部语言', $row['market_cluster']);
        self::assertStringContainsString('CNY', $row['market_cluster']);
        self::assertStringContainsString('1 Store', $row['store_channel_summary']);
        self::assertStringContainsString('默认店铺/default', $row['store_channel_summary']);
    }

    public function testPresentRowMarksDefaultWebsite(): void
    {
        $presenter = new WebsiteAdminListPresenter();
        $row = $presenter->presentRow([
            'website_id' => Website::ID_DEFAULT,
            'name' => 'Weline 主站',
            'code' => Website::CODE_DEFAULT,
            'default_timezone' => 'Asia/Shanghai',
            'currency_codes' => [],
            'language_codes' => ['zh_Hans_CN'],
            'store_channel_directory' => [],
        ]);

        self::assertStringContainsString('默认站点', $row['site_meta']);
        self::assertSame('暂无 Store/Channel', $row['store_channel_summary']);
    }
}
