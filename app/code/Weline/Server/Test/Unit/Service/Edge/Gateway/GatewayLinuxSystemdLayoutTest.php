<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayLinuxSystemdLayout;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayLinuxSystemdLayoutTest extends TestCase
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
            . 'wls-systemd-layout-' . \bin2hex(\random_bytes(8));
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

    public function testExactLegacyUnitMigratesToDedicatedTargetAndFixedLink(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));

        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->migrateExactLegacyDefinition($oldDefinition, $newDefinition);
        $layout->assertCurrentDefinitionAndFixedLink($newDefinition);

        $target = $this->paths->systemdServiceDefinitionFile();
        self::assertSame($newDefinition, \file_get_contents($target));
        self::assertTrue(\is_link($legacy));
        self::assertSame($target, \readlink($legacy));
        self::assertFalse(\is_link($target));
    }

    public function testForeignCanonicalLinkFailsClosedBeforePublishingDedicatedTarget(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $outside = $this->root . DIRECTORY_SEPARATOR . 'foreign-unit';
        self::assertNotFalse(\file_put_contents($outside, "foreign\n"));
        if (!@\symlink($outside, $legacy)) {
            self::markTestSkipped('The fixture filesystem does not permit symlinks.');
        }

        $exception = $this->captureRuntimeException(
            fn (): mixed => (new GatewayLinuxSystemdLayout($this->paths))
                ->migrateExactLegacyDefinition(
                    "[Unit]\nDescription=legacy WLS\n",
                    "[Unit]\nDescription=WLS dedicated target\n",
                ),
        );

        self::assertStringContainsString('canonical', \strtolower($exception->getMessage()));
        self::assertTrue(\is_link($legacy));
        self::assertSame($outside, \readlink($legacy));
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    public function testLegacyMigrationCanResumeAfterTargetPublicationBeforeLinkSwitch(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);

        $layout->ensureLegacyTargetPublished($oldDefinition, $newDefinition);

        self::assertSame(
            $oldDefinition,
            \file_get_contents($legacy),
            'Target publication must not replace the canonical legacy unit.',
        );
        self::assertSame(
            $newDefinition,
            \file_get_contents($this->paths->systemdServiceDefinitionFile()),
        );
        self::assertFalse(\is_link($legacy));

        $layout->ensureLegacyFixedLink($oldDefinition, $newDefinition);

        self::assertTrue(\is_link($legacy));
        self::assertSame(
            $this->paths->systemdServiceDefinitionFile(),
            \readlink($legacy),
        );
    }

    public function testLegacyLinkSwitchCollectsOnlyAnExactInterruptedLinkStagingLeaf(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->ensureLegacyTargetPublished($oldDefinition, $newDefinition);
        $staging = $legacy . '.tmp-' . \str_repeat('a', 24);
        if (!@\symlink($this->paths->systemdServiceDefinitionFile(), $staging)) {
            self::markTestSkipped('The fixture filesystem does not permit symlinks.');
        }

        $layout->ensureLegacyFixedLink($oldDefinition, $newDefinition);

        self::assertFileDoesNotExist($staging);
        self::assertTrue(\is_link($legacy));
        self::assertSame(
            $this->paths->systemdServiceDefinitionFile(),
            \readlink($legacy),
        );
    }

    public function testLegacyTargetPublicationRecoversAnUnpairedAtomicStagingLeaf(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $staging = $this->paths->systemdServiceDefinitionFile()
            . '.tmp-' . \str_repeat('b', 24);
        self::assertNotFalse(\file_put_contents($staging, "partial\n"));
        self::assertTrue(\chmod($staging, 0600));

        (new GatewayLinuxSystemdLayout($this->paths))
            ->ensureLegacyTargetPublished($oldDefinition, $newDefinition);

        self::assertFileDoesNotExist($staging);
        self::assertSame(
            $newDefinition,
            \file_get_contents($this->paths->systemdServiceDefinitionFile()),
        );
    }

    public function testDisabledCanonicalLinkIsRestoredAgainstTheExactDedicatedTarget(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $definition = "[Unit]\nDescription=WLS dedicated target\n";
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->publishNewDefinitionAndFixedLink($definition);
        $link = $this->paths->systemdServiceLinkFile();
        self::assertTrue(@\unlink($link));

        $layout->restoreDisabledCurrentDefinitionAndFixedLink($definition);

        $layout->assertCurrentDefinitionAndFixedLink($definition);
        self::assertTrue(\is_link($link));
        self::assertSame(
            $this->paths->systemdServiceDefinitionFile(),
            \readlink($link),
        );
    }

    public function testDisabledCanonicalLinkRecoveryRejectsForeignOccupants(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $definition = "[Unit]\nDescription=WLS dedicated target\n";
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->publishNewDefinitionAndFixedLink($definition);
        $link = $this->paths->systemdServiceLinkFile();
        self::assertTrue(@\unlink($link));

        $outside = $this->root . DIRECTORY_SEPARATOR . 'foreign-unit';
        self::assertNotFalse(\file_put_contents($outside, "foreign\n"));
        self::assertTrue(@\symlink($outside, $link));
        $wrongLink = $this->captureRuntimeException(
            fn (): mixed => $layout
                ->restoreDisabledCurrentDefinitionAndFixedLink($definition),
        );
        self::assertStringContainsString(
            'cannot be restored safely',
            $wrongLink->getMessage(),
        );
        self::assertSame($outside, \readlink($link));

        self::assertTrue(@\unlink($link));
        self::assertNotFalse(\file_put_contents($link, "foreign leaf\n"));
        $foreignLeaf = $this->captureRuntimeException(
            fn (): mixed => $layout
                ->restoreDisabledCurrentDefinitionAndFixedLink($definition),
        );
        self::assertStringContainsString(
            'cannot be restored safely',
            $foreignLeaf->getMessage(),
        );
        self::assertSame("foreign leaf\n", \file_get_contents($link));
    }

    public function testRemovalDeletesExactLinkBeforeDedicatedTarget(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->migrateExactLegacyDefinition($oldDefinition, $newDefinition);

        $layout->removeCurrentDefinitionAndFixedLink($newDefinition);

        self::assertFileDoesNotExist($legacy);
        self::assertFalse(\is_link($legacy));
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    public function testRemovalReplaysAfterCanonicalLinkWasAlreadyRemoved(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->migrateExactLegacyDefinition($oldDefinition, $newDefinition);

        $layout->removeCurrentCanonicalFixedLink($newDefinition);
        self::assertFalse(\is_link($legacy));
        self::assertFileExists($this->paths->systemdServiceDefinitionFile());

        $layout->removeCurrentDefinitionAndFixedLink($newDefinition);

        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    public function testRemovalReplaysAfterDedicatedTargetWasAlreadyRemoved(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        $newDefinition = "[Unit]\nDescription=WLS dedicated target\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->migrateExactLegacyDefinition($oldDefinition, $newDefinition);
        $layout->removeCurrentCanonicalFixedLink($newDefinition);
        $layout->removeCurrentTargetAfterFixedLink($newDefinition);

        $layout->removeCurrentDefinitionAndFixedLink($newDefinition);

        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    public function testRemovalSupportsExactLegacyRegularUnit(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));

        (new GatewayLinuxSystemdLayout($this->paths))
            ->removeExactLegacyDefinition($oldDefinition);

        self::assertFileDoesNotExist($legacy);
        self::assertFalse(\is_link($legacy));
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    public function testLegacyRemovalReplayAcceptsOnlyTheAlreadyAbsentExactUnit(): void
    {
        $legacy = $this->paths->legacySystemdServiceDefinitionFile();
        $oldDefinition = "[Unit]\nDescription=legacy WLS\n";
        self::assertNotFalse(\file_put_contents($legacy, $oldDefinition));
        self::assertTrue(\chmod($legacy, 0600));
        $layout = new GatewayLinuxSystemdLayout($this->paths);
        $layout->removeExactLegacyDefinition($oldDefinition);

        $layout->removeExactLegacyDefinition($oldDefinition);

        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($this->paths->systemdServiceDefinitionFile());
    }

    private function captureRuntimeException(\Closure $callback): \RuntimeException
    {
        try {
            $callback();
        } catch (\RuntimeException $exception) {
            return $exception;
        }
        self::fail('Expected a RuntimeException.');
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
