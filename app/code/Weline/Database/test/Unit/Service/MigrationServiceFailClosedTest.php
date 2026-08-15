<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

final class MigrationServiceFailClosedTest extends TestCase
{
    public function testStandaloneUninstallIsRejectedBeforeLoadingOrExecutingMigrationCode(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->expects(self::never())->method('getConnector');
        $printing = $this->createMock(Printing::class);
        $printing->expects(self::once())
            ->method('error')
            ->with(self::callback(static fn(mixed $message): bool => str_contains(
                (string)$message,
                '已禁止独立迁移卸载',
            )));
        $service = new MigrationService(
            $connectionFactory,
            $this->createMock(Migration::class),
            $this->createMock(BackupService::class),
            $this->createMock(VersionService::class),
            $printing,
        );

        self::assertFalse($service->uninstallMigration('Weline_Unit', '/not/loaded/migration.php'));
    }
}
