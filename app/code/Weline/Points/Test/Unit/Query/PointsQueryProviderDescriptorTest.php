<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Points\Extends\Module\Weline_Framework\Query\PointsQueryProvider;

final class PointsQueryProviderDescriptorTest extends TestCase
{
    public function testOnlySafeOperationsAreExposedToFrontend(): void
    {
        $descriptor = (new PointsQueryProvider())->getDescriptor();
        $ops = [];
        foreach ($descriptor['operations'] as $operation) {
            $ops[$operation['name']] = $operation;
        }

        foreach (['discount.quote', 'discount.apply', 'discount.clear', 'vip.levels'] as $name) {
            self::assertSame(true, $ops[$name]['frontend'] ?? null, $name . ' must be frontend-exposed');
            self::assertNotSame('', (string) ($ops[$name]['mode'] ?? ''), $name . ' must declare a mode');
        }

        self::assertSame('read', $ops['discount.quote']['mode'] ?? null);
        self::assertSame('write', $ops['discount.apply']['mode'] ?? null);
        self::assertSame('write', $ops['discount.clear']['mode'] ?? null);

        self::assertSame(false, $ops['vip.state']['frontend'] ?? null);
        self::assertSame(false, $ops['vip.benefits']['frontend'] ?? null);
    }
}
