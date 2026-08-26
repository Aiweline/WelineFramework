<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Service\CatalogSpaceRegistry;
use Weline\Framework\Manager\ObjectManager;

final class CatalogSpaceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testEmptyRegistryReturnsNoSpaces(): void
    {
        $registry = new CatalogSpaceRegistry($this->createMock(ObjectManager::class));
        self::assertSame([], $registry->all(true));
        self::assertSame([], $registry->listSpaces());
        self::assertNull($registry->get('product'));
    }
}
