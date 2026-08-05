<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit;

use PHPUnit\Framework\TestCase;

final class QueueModuleVersionUpgradeTest extends TestCase
{
    public function testModuleVersionAdvancesForTheNotBeforeSchemaCheckpoint(): void
    {
        $register = (string)file_get_contents(
            BP . 'app/code/Weline/Queue/register.php',
        );

        self::assertMatchesRegularExpression(
            "/'Weline_Queue',\\s*__DIR__,\\s*'1\\.2\\.2'/s",
            $register,
        );
    }

    public function testAuthoritativeManifestMatchesTheSchemaCheckpoint(): void
    {
        $manifest = require BP . 'app/code/Weline/Queue/etc/module.php';

        self::assertSame('Weline_Queue', $manifest['name'] ?? null);
        self::assertSame('1.2.2', $manifest['version'] ?? null);
        self::assertSame(
            [
                'Weline_Backend' => '*',
                'Weline_Cron' => '*',
                'Weline_Eav' => '*',
            ],
            $manifest['requires'] ?? null,
        );
        self::assertSame(
            \Weline\Queue\Service\AsyncEvent\QueueAsyncEventTransport::class,
            $manifest['provides'][
                \Weline\Framework\Api\Event\AsyncEventTransportInterface::class
            ] ?? null,
        );
    }
}
