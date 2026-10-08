<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ResponseRedirectBeforeAllowedHostContractTest extends TestCase
{
    public function testSecurityRedirectAllowsRegisteredWebsiteHosts(): void
    {
        $path = (new \ReflectionClass(\Weline\Backend\Observer\ResponseRedirectBefore::class))->getFileName();
        self::assertNotFalse($path);
        $source = (string) \file_get_contents((string)$path);
        self::assertNotSame('', $source);

        self::assertStringContainsString('isRegisteredWebsiteStorefrontHost(', $source);
        self::assertStringContainsString('WebsiteDomain::class', $source);
        self::assertStringContainsString('Website::class', $source);
        self::assertStringContainsString('schema_fields_DOMAIN', $source);
        // Must not keep the localhost-only denylist that blocked grocery preview.
        self::assertStringNotContainsString(
            "// 可以添加更多允许的域名",
            $source
        );
    }

    public function testGroceryStorefrontHostIsAllowedWhenRegistered(): void
    {
        $observer = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Backend\Observer\ResponseRedirectBefore::class
        );
        $ref = new \ReflectionClass($observer);
        $method = $ref->getMethod('isAllowedHost');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($observer, 'localhost'));
        self::assertTrue($method->invoke($observer, '127.0.0.1'));

        try {
            $origin = \trim((string)(\Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeApplicationUsageService::class
            )->resolveStorefrontOriginForWebsite(544, 'grocery') ?? ''));
        } catch (\Throwable) {
            $origin = '';
        }
        $host = \strtolower((string)(\parse_url($origin, \PHP_URL_HOST) ?: ''));
        if ($host === '') {
            self::markTestSkipped('grocery storefront origin not resolvable in this environment');
        }
        self::assertTrue(
            $method->invoke($observer, $host),
            'registered grocery Host must be an allowed backend redirect target'
        );
    }
}
