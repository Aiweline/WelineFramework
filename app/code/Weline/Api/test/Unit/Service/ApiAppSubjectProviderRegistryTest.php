<?php
declare(strict_types=1);

namespace Weline\Api\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Api\Api\ApiAppSubjectProviderInterface;
use Weline\Api\Service\ApiAppSubjectProviderRegistry;

final class ApiAppSubjectProviderRegistryTest extends TestCase
{
    public function testGlobalSubjectIsSupportedWithoutBusinessProvider(): void
    {
        $registry = new ApiAppSubjectProviderRegistry();

        self::assertTrue($registry->validate('global', '0'));
        self::assertTrue($registry->validate('global', 'default'));
        self::assertFalse($registry->validate('store', '1'));
    }

    public function testRegisteredProviderValidatesBusinessSubject(): void
    {
        $registry = new ApiAppSubjectProviderRegistry();
        $registry->register(new class implements ApiAppSubjectProviderInterface {
            public function getSubjectType(): string
            {
                return 'store';
            }

            public function validateSubject(string $subjectId): bool
            {
                return $subjectId === 'store-1';
            }

            public function getSubjectLabel(string $subjectId): string
            {
                return 'Store ' . $subjectId;
            }
        });

        self::assertTrue($registry->validate('store', 'store-1'));
        self::assertFalse($registry->validate('store', 'missing'));
        self::assertSame('Store store-1', $registry->label('store', 'store-1'));
    }
}
