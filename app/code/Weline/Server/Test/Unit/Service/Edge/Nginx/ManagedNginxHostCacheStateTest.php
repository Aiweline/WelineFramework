<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxHostCacheState;

final class ManagedNginxHostCacheStateTest extends TestCase
{
    public function testOperationIsDurableIdempotentAndHostScoped(): void
    {
        $state = ManagedNginxHostCacheState::fromConfig('worker_processes 1;');
        $first = $state->advance(['B.test.', 'a.test', 'A.TEST'], 'job:1');
        self::assertSame(['a.test'=>1,'b.test'=>1], $first['state']->generations());
        $restored = ManagedNginxHostCacheState::fromConfig($first['state']->configComment());
        self::assertTrue($restored->advance(['a.test','b.test'], 'job:1')['already_applied']);
        self::assertSame(['a.test'=>1,'b.test'=>1], $restored->generations());
        $next = $restored->advance(['a.test'], 'job:2');
        self::assertSame(['a.test'=>2,'b.test'=>1], $next['state']->generations());
        self::assertSame([], $state->generations(), 'Planning must not mutate the committed state.');
        $this->expectExceptionMessage('operation_conflict');
        $restored->advance(['a.test'], 'job:1');
    }

    public function testMalformedPersistedStateCannotResetToGenerationZero(): void
    {
        $this->expectException(\RuntimeException::class);
        ManagedNginxHostCacheState::fromConfig("# wls-edge-cache-state: invalid\n");
    }

    public function testHostsCannotInjectNginxDirectives(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ManagedNginxHostCacheState::normalizeHosts(['a.test; return 200;']);
    }
}
