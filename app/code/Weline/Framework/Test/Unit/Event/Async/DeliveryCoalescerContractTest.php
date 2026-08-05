<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Test\TestCore;

final class DeliveryCoalescerContractTest extends TestCore
{
    public function testOnlyMatchingUpsertsAreMergeCompatible(): void
    {
        $base = $this->payload('upsert', 7);

        self::assertTrue($this->invoke('compatible', $base, $this->payload('upsert', 7)));
        self::assertFalse($this->invoke('compatible', $base, $this->payload('delete', 7)));
        self::assertFalse($this->invoke('compatible', $base, $this->payload('upsert', 8)));

        $otherSchema = $this->payload('upsert', 7);
        $otherSchema['schema_version'] = 2;
        self::assertFalse($this->invoke('compatible', $base, $otherSchema));
    }

    public function testUnionIsStableDeduplicatedAndDropsEmptyValues(): void
    {
        self::assertSame(
            ['cache/site/0', 'cache/site/1', 'cache/site/2'],
            $this->invoke(
                'union',
                ['cache/site/0', '', 'cache/site/1'],
                ['cache/site/1', 'cache/site/2'],
            ),
        );
    }

    private function payload(string $action, int $id): array
    {
        return [
            'schema_version' => 1,
            'resource' => ['type' => 'website', 'id' => (string)$id, 'action' => $action],
        ];
    }

    private function invoke(string $methodName, mixed ...$arguments): mixed
    {
        $coalescer = (new \ReflectionClass(DeliveryCoalescer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DeliveryCoalescer::class, $methodName);
        return $method->invoke($coalescer, ...$arguments);
    }
}
