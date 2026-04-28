<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolFlowLogService;
use Weline\Websites\Service\DomainPoolLifecycleService;

final class DomainPoolLifecycleServiceTest extends TestCase
{
    public function testBackfillUsesModelLimitOffsetArgumentInsteadOfMissingOffsetMethod(): void
    {
        $previous = ObjectManager::_getInstance(DomainPool::class);
        $pool = new DomainPoolLifecycleQueryProbe([]);
        ObjectManager::setInstance(DomainPool::class, $pool);

        try {
            $service = new DomainPoolLifecycleService(new DomainPoolFlowLogService());

            self::assertSame(0, $service->backfillAllPoolStages(25));
            self::assertContains(['limit', 25, 0], $pool->calls);
            self::assertNotContains(['offset', 0], $pool->calls);
        } finally {
            if ($previous instanceof \stdClass || \is_object($previous)) {
                ObjectManager::setInstance(DomainPool::class, $previous);
            } else {
                ObjectManager::removeInstance(DomainPool::class);
            }
        }
    }
}

final class DomainPoolLifecycleQueryProbe
{
    /** @var list<array{0: string, 1?: mixed, 2?: mixed}> */
    public array $calls = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function clearQuery(): self
    {
        $this->calls[] = ['clearQuery'];
        return $this;
    }

    public function order(string $field, string $sort): self
    {
        $this->calls[] = ['order', $field, $sort];
        return $this;
    }

    public function limit(int $size, int $offset = 0): self
    {
        $this->calls[] = ['limit', $size, $offset];
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->calls[] = ['offset', $offset];
        throw new \LogicException('Model offset() must not be used; pass offset as limit() second argument.');
    }

    public function select(): self
    {
        $this->calls[] = ['select'];
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchArray(): array
    {
        $this->calls[] = ['fetchArray'];
        return $this->rows;
    }
}
