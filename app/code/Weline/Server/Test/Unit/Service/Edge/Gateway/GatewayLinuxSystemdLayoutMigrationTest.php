<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayLinuxSystemdLayout;
use Weline\Server\Service\Edge\Gateway\GatewayLinuxSystemdLayoutMigration;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayLinuxSystemdLayoutMigrationTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';
    private GatewayPaths $paths;

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-systemd-layout-migration-' . \bin2hex(\random_bytes(8));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'host');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=22080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=22443');
        $this->paths = new GatewayPaths();
        $this->paths->ensureDirectories();
        $this->paths->ensureSystemdDefinitionDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testMigrationPublishesTargetThenLinkThenMetadataBeforeReload(): void
    {
        [$oldDefinition, $newDefinition, $oldMetadata, $newMetadata] =
            $this->writeLegacyFixture();
        $reloads = 0;

        (new GatewayLinuxSystemdLayoutMigration($this->paths))
            ->migrate(
                'default',
                $oldDefinition,
                $oldMetadata,
                $newDefinition,
                $newMetadata,
                function () use (&$reloads, $newDefinition, $newMetadata): void {
                    ++$reloads;
                    (new GatewayLinuxSystemdLayout($this->paths))
                        ->assertCurrentDefinitionAndFixedLink($newDefinition);
                    self::assertSame(
                        $newMetadata,
                        \file_get_contents($this->paths->platformServiceMetadataFile()),
                    );
                },
            );

        self::assertSame(1, $reloads);
        self::assertFileDoesNotExist(
            $this->paths->systemdLayoutMigrationTransactionFile(),
        );
        self::assertSame(
            $newMetadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );
    }

    public function testMigrationResumesAfterMetadataPublicationBeforeReload(): void
    {
        [$oldDefinition, $newDefinition, $oldMetadata, $newMetadata] =
            $this->writeLegacyFixture();
        $migration = new GatewayLinuxSystemdLayoutMigration($this->paths);
        try {
            $migration->migrate(
                'default',
                $oldDefinition,
                $oldMetadata,
                $newDefinition,
                $newMetadata,
                static function (): void {
                    throw new \RuntimeException('simulated reload interruption');
                },
            );
            self::fail('A reload interruption must escape and retain the migration journal.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('simulated reload interruption', $exception->getMessage());
        }

        self::assertFileExists(
            $this->paths->systemdLayoutMigrationTransactionFile(),
        );
        (new GatewayLinuxSystemdLayout($this->paths))
            ->assertCurrentDefinitionAndFixedLink($newDefinition);
        self::assertSame(
            $newMetadata,
            \file_get_contents($this->paths->platformServiceMetadataFile()),
        );

        $reloads = 0;
        $migration->migrate(
            'default',
            $oldDefinition,
            $oldMetadata,
            $newDefinition,
            $newMetadata,
            static function () use (&$reloads): void {
                ++$reloads;
            },
        );

        self::assertSame(1, $reloads);
        self::assertFileDoesNotExist(
            $this->paths->systemdLayoutMigrationTransactionFile(),
        );
    }

    /** @return array{string,string,string,string} */
    private function writeLegacyFixture(): array
    {
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        $oldMetadata = "legacy-metadata\n";
        $newMetadata = "new-metadata\n";
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $metadata = $this->paths->platformServiceMetadataFile();
        self::assertNotFalse(\file_put_contents($metadata, $oldMetadata));
        self::assertTrue(\chmod($metadata, 0600));
        return [$oldDefinition, $newDefinition, $oldMetadata, $newMetadata];
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
