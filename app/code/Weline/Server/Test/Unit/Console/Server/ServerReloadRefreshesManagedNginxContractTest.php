<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Server;

use PHPUnit\Framework\TestCase;

/**
 * server:reload must rewrite+reload managed nginx after Workers succeed,
 * otherwise Edge conf (e.g. |fpc2) stays stale on disk / in process.
 */
final class ServerReloadRefreshesManagedNginxContractTest extends TestCase
{
    public function testWaitModeRefreshManagedNginxAfterWorkerReload(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Console/Server/Reload.php'
        );
        self::assertStringContainsString('refreshManagedNginxAfterWorkerReload', $source);
        self::assertStringContainsString('ManagedNginxService::fromEnv()', $source);
        self::assertStringContainsString('server:nginx:reload', $source);

        $executePos = \strpos($source, 'function execute(');
        $refreshPos = \strpos($source, 'refreshManagedNginxAfterWorkerReload');
        self::assertNotFalse($executePos);
        self::assertNotFalse($refreshPos);
        self::assertGreaterThan($executePos, $refreshPos);

        self::assertStringContainsString(
            'if ($exitCode === 0) {' . "\n"
            . '                $this->refreshManagedNginxAfterWorkerReload();',
            $source,
        );
    }
}
