<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayPathsTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
            \putenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-gateway-paths-'
            . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testProductionRootCannotBeOverridden(): void
    {
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot override');
        (new GatewayPaths())->home();
    }

    public function testWindowsProductionHomeUsesKnownFolderAuthorityAndAtomicAclCreation(): void
    {
        $gatewayDirectory = \dirname(__DIR__, 5)
            . '/Service/Edge/Gateway';
        $pathsSource = (string)\file_get_contents(
            $gatewayDirectory . '/GatewayPaths.php',
        );
        $authoritySource = (string)\file_get_contents(
            $gatewayDirectory . '/GatewayWindowsHostRootAuthority.php',
        );

        self::assertStringNotContainsString("getenv('PROGRAMDATA')", $pathsSource);
        self::assertStringContainsString(
            'GatewayWindowsHostRootAuthority::resolveHome()',
            $pathsSource,
        );
        self::assertStringContainsString(
            'GatewayWindowsHostRootAuthority::ensureHome()',
            $pathsSource,
        );
        self::assertStringContainsString(
            'GatewayWindowsHostRootAuthority::ensureBootstrapDirectories(',
            $pathsSource,
        );
        self::assertStringContainsString(
            'assertProductionPosixDirectoryAuthority(',
            $pathsSource,
        );
        foreach ([
            'SHGetKnownFolderPath',
            '0x62AB5D82',
            'GetDriveTypeW',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'GetFinalPathNameByHandleW',
            'SECURITY_ATTRIBUTES',
            'CreateDirectoryW',
            'D:P(A;;FA;;;SY)(A;;FA;;;BA)',
            'FILE_DELETE_CHILD',
            'WRITE_DAC',
            'WRITE_OWNER',
            'snapshot-candidates-v2',
            'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)',
            '0x1200a9',
            'ensureBootstrapDirectories',
            'SeRestorePrivilege',
            'OpenProcessToken',
            'AdjustTokenPrivileges',
            'ERROR_NOT_ALL_ASSIGNED',
            'withRestorePrivilege',
            'captureExactPathSddl',
            'applyExactPathSddl',
            'GetSecurityDescriptorOwner',
            'GetSecurityDescriptorDacl',
            'PROTECTED_DACL_SECURITY_INFORMATION',
            'SetSecurityInfo',
            'BY_HANDLE_FILE_INFORMATION',
            'handleLegacyIdentity',
            'target identity changed before authority access',
            'target identity changed after authority access',
        ] as $contract) {
            self::assertStringContainsString($contract, $authoritySource);
        }
        self::assertStringNotContainsString('FILE_SHARE_DELETE', $authoritySource);
        self::assertStringNotContainsString(';;;RX', $authoritySource);

        $captureStart = \strpos(
            $authoritySource,
            'public static function captureExactPathSddl(',
        );
        self::assertNotFalse($captureStart);
        $applyStart = \strpos(
            $authoritySource,
            'public static function applyExactPathSddl(',
            $captureStart,
        );
        self::assertNotFalse($applyStart);
        $captureSource = \substr(
            $authoritySource,
            $captureStart,
            $applyStart - $captureStart,
        );
        self::assertStringNotContainsString(
            'withRestorePrivilege(',
            $captureSource,
            'Read-only authority capture must never enable SeRestorePrivilege.',
        );
        $canonicalStart = \strpos(
            $authoritySource,
            'public static function canonicalizeSddl(',
            $applyStart,
        );
        self::assertNotFalse($canonicalStart);
        $applySource = \substr(
            $authoritySource,
            $applyStart,
            $canonicalStart - $applyStart,
        );
        self::assertStringContainsString('withRestorePrivilege(', $applySource);
        self::assertStringContainsString('SetSecurityInfo(', $applySource);
        self::assertStringNotContainsString('runCommand(', $applySource);

        $ensureMethod = new \ReflectionMethod(GatewayPaths::class, 'ensureDirectories');
        $ensureSource = \implode("\n", \array_slice(
            \explode("\n", $pathsSource),
            $ensureMethod->getStartLine() - 1,
            $ensureMethod->getEndLine() - $ensureMethod->getStartLine() + 1,
        ));
        self::assertStringContainsString(
            'if ($testMode)',
            $ensureSource,
            'Fixed snapshot roots may only be added by the isolated test branch.',
        );
        self::assertStringContainsString(
            '$directories[] = $this->sealedSnapshotsDir();',
            $ensureSource,
        );
        self::assertStringContainsString(
            '$directories[] = $this->snapshotCandidatesDir();',
            $ensureSource,
        );
        self::assertStringContainsString(
            'if (!$testMode)',
            $ensureSource,
            'Production directory checks must validate without chmod mutation.',
        );
        self::assertStringContainsString(
            '$profiles[$directory] ?? []',
            $pathsSource,
            'Missing production identities must not silently accept a bootstrap profile.',
        );
        self::assertStringContainsString(
            'productionBootstrapAuthorityAllowed()',
            $pathsSource,
        );

        $installerSource = (string)\file_get_contents(
            $gatewayDirectory . '/GatewayPlatformServiceInstaller.php',
        );
        $fixedNamespaceStart = \strpos(
            $installerSource,
            'private function applyWindowsFixedNamespaceAcl(',
        );
        self::assertNotFalse($fixedNamespaceStart);
        $fixedNamespaceEnd = \strpos(
            $installerSource,
            'private function applyWindowsAcl(',
            $fixedNamespaceStart,
        );
        self::assertNotFalse($fixedNamespaceEnd);
        $fixedNamespaceSource = \substr(
            $installerSource,
            $fixedNamespaceStart,
            $fixedNamespaceEnd - $fixedNamespaceStart,
        );
        self::assertStringContainsString(
            'GatewayWindowsHostRootAuthority::applyExactPathSddl(',
            $fixedNamespaceSource,
            'SYSTEM-owned DACL projection must remain in-process and handle-bound.',
        );
        self::assertStringContainsString(
            '$before,',
            $fixedNamespaceSource,
            'The held Windows handle must bind the caller preflight identity.',
        );
        self::assertStringNotContainsString(
            'withRestorePrivilege(',
            $fixedNamespaceSource,
            'A privileged child command could outlive its parent token scope.',
        );
    }

    public function testIsolatedTestRootRequiresHighExplicitPorts(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        $paths = new GatewayPaths();
        $expectedRoot = (string)\realpath(\dirname($this->root))
            . DIRECTORY_SEPARATOR . \basename($this->root);

        self::assertSame($expectedRoot, $paths->home());
        self::assertSame(21080, $paths->publicHttpPort());
        self::assertSame(21443, $paths->publicHttpsPort());
        self::assertStringStartsWith($expectedRoot, $paths->runDir());
        self::assertStringEndsWith('project.sock', $paths->projectSocketFile());
        self::assertStringEndsWith('admin.sock', $paths->adminSocketFile());
        self::assertSame(
            $paths->trustDir() . DIRECTORY_SEPARATOR
                . 'guardian-transition.retirement',
            $paths->guardianTransitionRetirementFile(),
        );
        $paths->ensureDirectories();
        self::assertDirectoryExists($paths->slotsDir());
        self::assertDirectoryExists($paths->sealedSnapshotsDir());
        self::assertDirectoryExists($paths->snapshotCandidatesDir());
        self::assertDirectoryDoesNotExist($paths->slotDir('A'));
        self::assertDirectoryDoesNotExist($paths->slotDir('B'));
    }

    public function testSystemdDedicatedDefinitionLayoutKeepsTestModeOutOfEtc(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');

        $paths = new GatewayPaths();
        $expectedDirectory = $paths->stateDir() . DIRECTORY_SEPARATOR
            . 'systemd-definition';
        $expectedTarget = $expectedDirectory . DIRECTORY_SEPARATOR
            . 'weline-wls-gateway-v2.service';
        $expectedLink = $paths->stateDir() . DIRECTORY_SEPARATOR
            . 'systemd-service-link.test';

        self::assertSame($expectedDirectory, $paths->systemdDefinitionDirectory());
        self::assertSame($expectedTarget, $paths->systemdServiceDefinitionFile());
        self::assertSame($expectedLink, $paths->systemdServiceLinkFile());
        self::assertSame($expectedLink, $paths->legacySystemdServiceDefinitionFile());
        self::assertStringNotContainsString('/etc/', $paths->systemdDefinitionDirectory());
        self::assertStringNotContainsString('/etc/', $paths->systemdServiceLinkFile());

        try {
            $paths->assertSystemdDefinitionDirectoryAuthority();
            self::fail('Read-only systemd directory verification must not create a missing directory.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('definition directory', $exception->getMessage());
        }

        $paths->ensureSystemdDefinitionDirectory();

        self::assertDirectoryExists($expectedDirectory);
        self::assertSame(0700, \fileperms($expectedDirectory) & 0777);
        $paths->assertSystemdDefinitionDirectoryAuthority();
    }

    public function testTestRootAndPrivilegedPortEscapeAreRejected(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=/var/lib/not-a-wls-test-root');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        try {
            (new GatewayPaths())->home();
            self::fail('Test roots outside the system temporary directory must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('temporary directory', $exception->getMessage());
        }

        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=80');
        try {
            (new GatewayPaths())->publicHttpPort();
            self::fail('Test mode must never bind a privileged public port.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('above 1024', $exception->getMessage());
        }
    }

    public function testFilesystemRootsCannotBecomeTheHostGatewayHome(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        foreach ([
            '/',
            'C:\\',
            "\\\\server\\",
            "\\\\server\\share\\",
            "\\\\?\\C:\\",
            "\\\\?\\UNC\\server\\share\\",
            "\\\\?\\UNC\\server\\",
            "\\\\.\\C:\\",
            "\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\",
        ] as $filesystemRoot) {
            \putenv('WLS_GATEWAY_HOME=' . $filesystemRoot);
            try {
                (new GatewayPaths())->home();
                self::fail('A filesystem root must not become the gateway home.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'filesystem root',
                    \strtolower($exception->getMessage()),
                );
            }
        }
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
