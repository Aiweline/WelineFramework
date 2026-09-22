<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query\Attribute;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\Attribute\BinQueryOperation;

final class BinQueryOperationDefaultDenyTest extends TestCase
{
    public function testAttributeDefaultsDenyExternalAndFrontend(): void
    {
        $op = new BinQueryOperation(name: 'probe');
        $descriptor = $op->toDescriptor();

        self::assertFalse($descriptor['external']);
        self::assertFalse($descriptor['frontend']);
        self::assertFalse($descriptor['backend']);
        self::assertSame('read', $descriptor['mode']);
        self::assertArrayNotHasKey('auth', $descriptor);
    }

    public function testExplicitOptInKeepsExternalFrontendAndAuth(): void
    {
        $op = new BinQueryOperation(
            name: 'publicList',
            external: true,
            frontend: true,
            auth: 'any',
        );
        $descriptor = $op->toDescriptor();

        self::assertTrue($descriptor['external']);
        self::assertTrue($descriptor['frontend']);
        self::assertSame('any', $descriptor['auth']);
    }
}
