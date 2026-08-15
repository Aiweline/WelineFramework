<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Websites\Controller\Admin\Domain as AdminDomain;
use Weline\Websites\Controller\Backend\Api\DomainPool;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;

final class WebsitesAdminRequestPathResolveTest extends TestCase
{
    /**
     * @dataProvider pathProvider
     */
    public function testResolveAdminRequestTarget(string $path, string $expectedClass, string $expectedAction): void
    {
        $method = new ReflectionMethod(WebsitesQueryProvider::class, 'resolveAdminRequestTarget');
        $method->setAccessible(true);
        $resolved = $method->invoke(null, $path);

        self::assertIsArray($resolved);
        self::assertSame($expectedClass, $resolved['class']);
        self::assertSame($expectedAction, $resolved['action']);
        self::assertTrue(\class_exists($expectedClass), 'controller class must exist: ' . $expectedClass);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function pathProvider(): array
    {
        return [
            'nested api list' => [
                '/websites/backend/api/domain-pool',
                DomainPool::class,
                'index',
            ],
            'nested api action' => [
                '/websites/backend/api/domain-pool/check-conflict',
                DomainPool::class,
                'checkconflict',
            ],
            'admin purchase' => [
                '/websites/admin/domain/purchase',
                AdminDomain::class,
                'purchase',
            ],
            'admin registrar accounts' => [
                '/websites/admin/domain/get-registrar-accounts',
                AdminDomain::class,
                'getregistraraccounts',
            ],
        ];
    }

    public function testUnsupportedPathReturnsNull(): void
    {
        $method = new ReflectionMethod(WebsitesQueryProvider::class, 'resolveAdminRequestTarget');
        $method->setAccessible(true);
        self::assertNull($method->invoke(null, 'not-a-path'));
        self::assertNull($method->invoke(null, '/'));
    }
}
