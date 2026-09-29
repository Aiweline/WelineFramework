<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Frontend\Observer\ResponseRedirectBefore;

final class StatefulRedirectMountTest extends TestCase
{
    private array $serverBackup = [];
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
    }

    /** @dataProvider redirectCases */
    public function testSeoKeepsStatefulRedirectsTemporaryWithinTheCurrentMount(
        string $websiteUrl,
        string $path,
        int $expectedCode,
        string $expectedPath,
    ): void {
        $_SERVER['WELINE_WEBSITE_URL'] = $websiteUrl;
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', $websiteUrl, 'stateful redirect mount test');
        $url = 'https://shop.example.test' . $path;
        $data = new DataObject(['url' => $url, 'code' => 302]);

        // Exercise the real SEO transformation without unrelated rewrite-directory IO.
        $observer = (new \ReflectionClass(ResponseRedirectBefore::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(ResponseRedirectBefore::class, 'handleSeoRedirect'))
            ->invoke($observer, $data, $url, 302);

        self::assertSame($expectedCode, $data->getData('code'));
        self::assertSame('https://shop.example.test' . $expectedPath, $data->getData('url'));
    }

    public static function redirectCases(): array
    {
        return [
            'root login' => ['https://shop.example.test', '/customer/account/login', 302, '/customer/account/login'],
            'website mount login' => ['https://shop.example.test/daocharms', '/daocharms/customer/account/login', 302, '/daocharms/customer/account/login'],
            'website mount localized login' => ['https://shop.example.test/store-path', '/store-path/zh_Hans_CN/customer/account/login', 302, '/store-path/zh_Hans_CN/customer/account/login'],
            'different mount segment remains ordinary SEO' => ['https://shop.example.test/daocharms', '/daocharms2/customer/account/login', 301, '/daocharms2/customer/account/login/'],
            'ordinary website page retains canonical slash' => ['https://shop.example.test/daocharms', '/daocharms/about', 301, '/daocharms/about/'],
        ];
    }
}
