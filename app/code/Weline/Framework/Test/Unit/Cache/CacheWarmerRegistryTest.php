<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;

class CacheWarmerRegistryTest extends TestCase
{
    public function testWarmersExecuteInPriorityOrder(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('low', 'a', 100, $order),
            new CacheWarmerOrderingFake('high', 'a', 0, $order),
            new CacheWarmerOrderingFake('mid', 'a', 50, $order),
        ]);

        $result = $registry->warmUp();

        $this->assertSame(['high', 'mid', 'low'], $order);
        $this->assertSame(3, $result['ran']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(3, $result['warmed']);
        $this->assertSame([], $result['errors']);
    }

    public function testCanWarmFalseSkipsWarmer(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('skip-me', 'a', 0, $order, canWarm: false),
            new CacheWarmerOrderingFake('run-me', 'a', 1, $order),
        ]);
        $result = $registry->warmUp();
        $this->assertSame(1, $result['ran']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(['run-me'], $order, 'skipped warmer must not record execution');
    }

    public function testWarmerExceptionIsCapturedNotPropagated(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('boom', 'a', 0, $order, throwMessage: 'boom!'),
            new CacheWarmerOrderingFake('after', 'a', 1, $order),
        ]);
        $result = $registry->warmUp();
        $this->assertSame(1, $result['ran']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('boom', $result['errors'][0]['name']);
        $this->assertSame('boom!', $result['errors'][0]['error']);
        $this->assertContains('after', $order, 'subsequent warmers must still run after a failure');
    }

    public function testOnlyPoolFiltersByTargetPool(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('router-warmer', 'router', 0, $order),
            new CacheWarmerOrderingFake('config-warmer', 'config', 0, $order),
        ]);

        $result = $registry->warmUp(onlyPool: 'router');
        $this->assertSame(1, $result['ran']);
        $this->assertSame(['router-warmer'], $order);
    }

    public function testRegisterReplacesExistingWarmerWithSameName(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('dup', 'a', 0, $order),
        ]);
        $registry->register(new CacheWarmerOrderingFake('dup', 'a', 0, $order));
        $this->assertCount(1, $registry->all());
    }

    public function testRegistrySupportsUnregister(): void
    {
        $order = [];
        $registry = new CacheWarmerRegistry([
            new CacheWarmerOrderingFake('one', 'a', 0, $order),
            new CacheWarmerOrderingFake('two', 'a', 1, $order),
        ]);
        $registry->unregister('one');
        $this->assertFalse($registry->has('one'));
        $this->assertTrue($registry->has('two'));
    }
}

final class CacheWarmerOrderingFake implements CacheWarmerInterface
{
    /**
     * @param array<int, string> $order by-ref order trace
     */
    public function __construct(
        private string $name,
        private string $pool,
        private int $priority,
        private array &$order,
        private bool $canWarm = true,
        private string $throwMessage = ''
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTargetPool(): string
    {
        return $this->pool;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function canWarm(): bool
    {
        return $this->canWarm;
    }

    public function warm(): array
    {
        if ($this->throwMessage !== '') {
            throw new \RuntimeException($this->throwMessage);
        }
        $this->order[] = $this->name;
        return ['warmed' => 1, 'skipped' => 0];
    }
}
