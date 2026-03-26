<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerNameResolutionTest extends TestCase
{
    public function testResolvePersistedInstanceNameReturnsExactMatch(): void
    {
        $manager = $this->createManager(['default', 'test']);

        self::assertSame('default', $manager->resolvePersistedInstanceName('default'));
    }

    public function testResolvePersistedInstanceNameAllowsUniquePrefix(): void
    {
        $manager = $this->createManager(['default', 'test']);

        self::assertSame('default', $manager->resolvePersistedInstanceName('defaul'));
    }

    public function testResolvePersistedInstanceNameReturnsNullForAmbiguousPrefix(): void
    {
        $manager = $this->createManager(['default', 'default-blue', 'test']);

        self::assertNull($manager->resolvePersistedInstanceName('def'));
    }

    public function testSuggestPersistedInstanceNamesRanksClosestCandidatesFirst(): void
    {
        $manager = $this->createManager(['default', 'default-blue', 'test', 'staging']);

        self::assertSame(
            ['default', 'default-blue'],
            $manager->suggestPersistedInstanceNames('defaul', 2)
        );
    }

    private function createManager(array $names): ServerInstanceManager
    {
        return new class($names) extends ServerInstanceManager {
            /**
             * @param string[] $names
             */
            public function __construct(private readonly array $names)
            {
            }

            public function listPersistedInstanceNames(): array
            {
                return $this->names;
            }
        };
    }
}
