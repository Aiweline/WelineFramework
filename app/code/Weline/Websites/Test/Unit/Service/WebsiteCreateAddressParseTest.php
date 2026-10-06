<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteCreateService;

final class WebsiteCreateAddressParseTest extends TestCase
{
    public function testParsesHostAndOptionalSubPath(): void
    {
        self::assertSame(
            ['domain' => 'shop.example.com', 'sub_path' => '', 'pool_id' => 0],
            WebsiteCreateService::parsePrimaryAddress('https://shop.example.com/'),
        );
        self::assertSame(
            ['domain' => 'shop.example.com', 'sub_path' => '/store', 'pool_id' => 0],
            WebsiteCreateService::parsePrimaryAddress('shop.example.com/store'),
        );
        self::assertSame(
            ['domain' => '127.0.0.1', 'sub_path' => '', 'pool_id' => 0],
            WebsiteCreateService::parsePrimaryAddress('http://127.0.0.1:9555'),
        );
    }

    public function testCodeFromDomainUsesHostAndSubPath(): void
    {
        self::assertSame('shop_example_com', WebsiteCreateService::codeFromDomain('shop.example.com'));
        self::assertSame('shop_example_com_store', WebsiteCreateService::codeFromDomain('shop.example.com', '/store'));
    }

    public function testRejectsEmptyHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebsiteCreateService::parsePrimaryAddress('https://');
    }

    public function testCreateRequiresPoolOrUrl(): void
    {
        $service = (new \ReflectionClass(WebsiteCreateService::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('域名池');
        (new \ReflectionMethod(WebsiteCreateService::class, 'create'))->invoke($service, ['name' => 'Demo']);
    }
}
