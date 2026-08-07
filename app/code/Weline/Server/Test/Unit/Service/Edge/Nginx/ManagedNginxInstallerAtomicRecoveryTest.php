<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxInstaller;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxInstallerAtomicRecoveryTest extends TestCase
{
    private string $root = '';
    private ManagedNginxPaths $paths;
    private ManagedNginxInstaller $installer;
    private string|false $previousLocalAppData = false;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-managed-nginx-installer-atomic-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->previousLocalAppData = \getenv('LOCALAPPDATA');
            self::assertTrue(\putenv('LOCALAPPDATA=' . $this->root));
        }
        $this->paths = new ManagedNginxPaths($this->root, [
            'install_root' => 'managed/nginx-install',
            'runtime_root' => 'nginx-runtime',
        ]);
        self::assertTrue(\mkdir(\dirname($this->paths->binary()), 0700, true));
        self::assertTrue(\copy(\PHP_BINARY, $this->paths->binary()));
        self::assertTrue(\chmod($this->paths->binary(), 0700));
        $this->installer = new ManagedNginxInstaller($this->paths);
        $this->writeValidManifest();
    }

    protected function tearDown(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(\putenv($this->previousLocalAppData === false
                ? 'LOCALAPPDATA'
                : 'LOCALAPPDATA=' . $this->previousLocalAppData));
        }
        $this->removeTree($this->root);
    }

    public function testInstallLockCollectsBackupOnlyWhenCurrentManifestFullyMatchesRuntime(): void
    {
        $manifest = $this->paths->manifestFile();
        $backup = $manifest . '.wls-backup-' . \str_repeat('a', 16);
        self::assertTrue(\copy($manifest, $backup));

        $result = $this->installer->ensureInstalled();

        self::assertTrue($result['ok'], (string)($result['message'] ?? 'install failed'));
        self::assertFileDoesNotExist($backup);
        self::assertFileExists($manifest);
    }

    public function testInvalidOrMissingManifestPreservesRetainedBackupEvidence(): void
    {
        $manifest = $this->paths->manifestFile();
        $backup = $manifest . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($manifest, $backup));
        self::assertSame(8, \file_put_contents($manifest, '{broken:'));

        $invalid = $this->installer->ensureInstalled();

        self::assertFalse($invalid['ok']);
        self::assertFileExists($manifest);
        self::assertFileExists($backup);

        self::assertTrue(\unlink($manifest));
        $missing = $this->installer->ensureInstalled();
        self::assertFalse($missing['ok']);
        self::assertFileDoesNotExist($manifest);
        self::assertFileExists($backup);
    }

    public function testVerifiedCommittedInstallCollectsOnlyExactCrashCandidate(): void
    {
        $parent = \dirname($this->paths->installRoot());
        $candidate = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('a', 16);
        $unknown = $parent . DIRECTORY_SEPARATOR . 'operator-preserved-data';
        self::assertTrue(\mkdir($candidate . DIRECTORY_SEPARATOR . 'partial', 0700, true));
        self::assertSame(7, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'partial' . DIRECTORY_SEPARATOR . 'payload',
            'partial',
        ));
        self::assertTrue(\mkdir($unknown, 0700));
        self::assertSame(8, \file_put_contents(
            $unknown . DIRECTORY_SEPARATOR . 'evidence',
            'preserve',
        ));

        $result = $this->installer->ensureInstalled();

        self::assertTrue($result['ok'], (string)($result['message'] ?? 'install failed'));
        self::assertDirectoryDoesNotExist($candidate);
        self::assertFileExists($unknown . DIRECTORY_SEPARATOR . 'evidence');
    }

    public function testMalformedReservedCandidateFailsClosedBeforeAnyCleanup(): void
    {
        $parent = \dirname($this->paths->installRoot());
        $candidate = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('b', 16);
        $malformed = $parent . DIRECTORY_SEPARATOR . 'install-candidate-NOT-HEX';
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(5, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'proof',
            'exact',
        ));
        self::assertTrue(\mkdir($malformed, 0700));
        self::assertSame(9, \file_put_contents(
            $malformed . DIRECTORY_SEPARATOR . 'evidence',
            'malformed',
        ));

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('candidate namespace', (string)$result['message']);
        self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'proof');
        self::assertFileExists($malformed . DIRECTORY_SEPARATOR . 'evidence');
    }

    public function testWindowsCandidateNamespaceRejectsACaseAliasedReservedName(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Case-alias namespace semantics are Windows-specific.');
        }
        $parent = \dirname($this->paths->installRoot());
        $aliased = $parent . DIRECTORY_SEPARATOR
            . 'Install-Candidate-' . \str_repeat('a', 16);
        self::assertTrue(\mkdir($aliased, 0700));
        self::assertSame(8, \file_put_contents(
            $aliased . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('candidate namespace', (string)$result['message']);
        self::assertFileExists($aliased . DIRECTORY_SEPARATOR . 'evidence');
    }

    public function testCandidateRecoveryEnumerationIsBoundedBeforeCleanup(): void
    {
        $parent = \dirname($this->paths->installRoot());
        $candidate = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('c', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(8, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));
        for ($index = 0; $index < 300; ++$index) {
            $directory = $parent . DIRECTORY_SEPARATOR
                . \sprintf('unrelated-%03d', $index);
            if (!\mkdir($directory, 0700)) {
                self::fail('Unable to create bounded-enumeration fixture: ' . $directory);
            }
        }

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('enumeration exceeds', (string)$result['message']);
        self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'evidence');
    }

    public function testSuccessfulPublicationCollectsOlderCrashCandidate(): void
    {
        $prefix = $this->paths->installRoot();
        $parent = \dirname($prefix);
        $stale = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('d', 16);
        $publishing = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('e', 16);
        self::assertTrue(\mkdir($stale, 0700));
        self::assertSame(5, \file_put_contents(
            $stale . DIRECTORY_SEPARATOR . 'proof',
            'stale',
        ));
        $relativeBinary = \substr(
            $this->paths->binary(),
            \strlen($prefix) + 1,
        );
        $candidateBinary = $publishing . DIRECTORY_SEPARATOR . $relativeBinary;
        self::assertTrue(\mkdir(\dirname($candidateBinary), 0700, true));
        self::assertTrue(\copy($this->paths->binary(), $candidateBinary));
        self::assertTrue(\chmod($candidateBinary, 0700));
        self::assertTrue(\copy(
            $this->paths->manifestFile(),
            $publishing . DIRECTORY_SEPARATOR . \basename($this->paths->manifestFile()),
        ));

        (new \ReflectionMethod($this->installer, 'publishInstallCandidate'))->invoke(
            $this->installer,
            $publishing,
            $prefix,
            true,
        );

        self::assertDirectoryDoesNotExist($stale);
        self::assertFileExists($this->paths->binary());
    }

    public function testInvalidCommittedInstallPreservesCrashCandidate(): void
    {
        $candidate = \dirname($this->paths->installRoot()) . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('f', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(8, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));
        self::assertSame(8, \file_put_contents(
            $this->paths->manifestFile(),
            '{broken:',
        ));

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'evidence');
    }

    public function testSpecialExactCandidateFailsClosedBeforeValidCandidateCleanup(): void
    {
        $parent = \dirname($this->paths->installRoot());
        $candidate = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('1', 16);
        $special = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('2', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(8, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));
        self::assertSame(7, \file_put_contents($special, 'special'));

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('linked or special candidate', (string)$result['message']);
        self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'evidence');
        self::assertFileExists($special);
    }

    public function testLinkedCandidateParentFailsClosedWithoutFollowingIt(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows directory reparse-point coverage runs on the native runner.');
        }
        $parent = \dirname($this->paths->installRoot());
        $candidate = $parent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('3', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(8, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));
        $retainedParent = $parent . '-retained';
        self::assertTrue(\rename($parent, $retainedParent));
        self::assertTrue(\symlink($retainedParent, $parent));

        $result = $this->installer->ensureInstalled();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('candidate parent is unsafe', (string)$result['message']);
        self::assertFileExists(
            $retainedParent . DIRECTORY_SEPARATOR . \basename($candidate)
                . DIRECTORY_SEPARATOR . 'evidence',
        );
    }

    public function testLegacyManifestMigrationCompletesCrashCandidateRecovery(): void
    {
        $manifest = \json_decode(
            (string)\file_get_contents($this->paths->manifestFile()),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        unset(
            $manifest['schema_version'],
            $manifest['role'],
            $manifest['implementation_level'],
            $manifest['runtime_generation'],
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->manifestFile(),
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
        $candidate = \dirname($this->paths->installRoot()) . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \str_repeat('4', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(8, \file_put_contents(
            $candidate . DIRECTORY_SEPARATOR . 'evidence',
            'retained',
        ));

        $result = $this->installer->ensureInstalled();

        self::assertTrue($result['ok'], (string)$result['message']);
        self::assertStringContainsString('legacy manifest upgraded', (string)$result['message']);
        self::assertDirectoryDoesNotExist($candidate);
    }

    private function writeValidManifest(): void
    {
        $architecture = match (\strtolower((string)\php_uname('m'))) {
            'amd64', 'x86_64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            'x86', 'i386', 'i686' => 'x86',
            default => \strtolower((string)\php_uname('m')),
        };
        $artifact = \PHP_OS_FAMILY === 'Windows'
            ? ManagedNginxInstaller::WINDOWS_ZIP_SHA256
            : ManagedNginxInstaller::SOURCE_SHA256;
        $manifest = [
            'version' => ManagedNginxInstaller::VERSION,
            'source_url' => \PHP_OS_FAMILY === 'Windows'
                ? ManagedNginxInstaller::WINDOWS_ZIP_URL
                : ManagedNginxInstaller::SOURCE_URL,
            'artifact_sha256' => $artifact,
            'source_sha256' => $artifact,
            'platform' => \PHP_OS_FAMILY,
            'arch' => $architecture,
            'php_process_arch' => $architecture,
            'prefix' => $this->paths->installRoot(),
            'binary' => $this->paths->binary(),
            'binary_sha256' => \hash_file('sha256', $this->paths->binary()),
            'build_flags' => [
                'http_v2_module' => true,
                'http_v3_module' => \PHP_OS_FAMILY !== 'Windows',
                'has_pcre' => true,
                'without_rewrite' => false,
            ],
            'installed_at' => '2026-08-06T00:00:00+00:00',
            'schema_version' => 2,
            'role' => 'legacy-project-nginx',
            'implementation_level' => 'nginx-runtime-v2',
        ];
        $generationSource = $this->canonical($manifest);
        $manifest['runtime_generation'] = \hash('sha256', \json_encode(
            $generationSource,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        self::assertNotFalse(\file_put_contents(
            $this->paths->manifestFile(),
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
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
