<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;

final class TlsProcessProfileConfiguratorRecoveryTest extends TestCase
{
    private const MAX_CONFIG_BYTES = 4096;

    private string $root = '';

    private string $repository = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-tls-profile-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0755, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->repository = \dirname(__DIR__, 8);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFreshPublicationCollectsLegacyAndAtomicStagingEvidence(): void
    {
        $directory = $this->tlsDirectory();
        $target = $this->target();
        $legacy = $target . '.12345.' . \str_repeat('a', 8) . '.tmp';
        $staging = $target . '.tmp-' . \str_repeat('b', 24);
        $this->write($legacy, 'legacy-partial');
        $this->write($staging, 'atomic-partial');

        $result = $this->runConfigurator();

        self::assertTrue($result['ok'] ?? false, (string)($result['message'] ?? ''));
        self::assertSame($target, $result['path'] ?? null);
        self::assertSame($this->content(), \file_get_contents($target));
        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($staging);
        $lock = $directory . DIRECTORY_SEPARATOR . '.openssl-performance.lock';
        self::assertFileExists($lock);
        if (\PHP_OS_FAMILY !== 'Windows') {
            $targetStatus = \stat($target);
            $lockStatus = \stat($lock);
            $rootStatus = \stat($this->root);
            self::assertIsArray($targetStatus);
            self::assertIsArray($lockStatus);
            self::assertIsArray($rootStatus);
            self::assertSame(0644, (int)$targetStatus['mode'] & 0777);
            self::assertSame(0600, (int)$lockStatus['mode'] & 0777);
            self::assertSame((int)$rootStatus['uid'], (int)$targetStatus['uid']);
            self::assertSame((int)$rootStatus['uid'], (int)$lockStatus['uid']);
        }
    }

    public function testRepairsGroupWritableIntermediateRuntimeDirectory(): void
    {
        $var = $this->root . DIRECTORY_SEPARATOR . 'var';
        self::assertTrue(\mkdir($var, 0775));
        $server = $var . DIRECTORY_SEPARATOR . 'server';
        self::assertTrue(\mkdir($server, 0755));
        $tls = $server . DIRECTORY_SEPARATOR . 'tls';
        self::assertTrue(\mkdir($tls, 0755));

        $result = $this->runConfigurator();

        self::assertTrue($result['ok'] ?? false, (string)($result['message'] ?? ''));
        if (\PHP_OS_FAMILY !== 'Windows') {
            $varStatus = \stat($var);
            self::assertIsArray($varStatus);
            self::assertSame(0755, (int)$varStatus['mode'] & 0777);
        }
    }

    public function testValidTargetCollectsEveryValidatedOrphan(): void
    {
        $target = $this->target();
        $this->write($target, $this->content());
        $legacy = $target . '.9.' . \str_repeat('c', 8) . '.tmp';
        $staging = $target . '.tmp-' . \str_repeat('d', 24);
        $backup = $target . '.wls-backup-' . \str_repeat('e', 16);
        $this->write($legacy, 'partial');
        $this->write($staging, 'partial');
        $this->write($backup, $this->content());

        $result = $this->runConfigurator();

        self::assertTrue($result['ok'] ?? false, (string)($result['message'] ?? ''));
        self::assertSame($this->content(), \file_get_contents($target));
        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($staging);
        self::assertFileDoesNotExist($backup);
    }

    public function testValidTargetDoesNotDeleteAnInvalidRetainedBackup(): void
    {
        $target = $this->target();
        $this->write($target, $this->content());
        $safe = $target . '.17.' . \str_repeat('1', 8) . '.tmp';
        $backup = $target . '.wls-backup-' . \str_repeat('2', 16);
        $this->write($safe, 'uncommitted');
        $this->write($backup, 'not-the-performance-config');

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString(
            'backup',
            \strtolower((string)($result['message'] ?? '')),
        );
        self::assertSame($this->content(), \file_get_contents($target));
        self::assertFileExists($safe);
        self::assertFileExists($backup);
    }

    public function testMissingTargetRepublishesOnlyAfterAValidatedBackup(): void
    {
        $target = $this->target();
        $backup = $target . '.wls-backup-' . \str_repeat('f', 16);
        $staging = $target . '.tmp-' . \str_repeat('1', 24);
        $this->write($backup, $this->content());
        $this->write($staging, 'uncommitted');

        $result = $this->runConfigurator();

        self::assertTrue($result['ok'] ?? false, (string)($result['message'] ?? ''));
        self::assertSame($this->content(), \file_get_contents($target));
        self::assertFileDoesNotExist($backup);
        self::assertFileDoesNotExist($staging);
    }

    public function testInvalidMissingTargetBackupPreservesTheCompleteSet(): void
    {
        $target = $this->target();
        $safe = $target . '.123.' . \str_repeat('2', 8) . '.tmp';
        $backup = $target . '.wls-backup-' . \str_repeat('3', 16);
        $this->write($safe, 'safe');
        $this->write($backup, 'not-the-performance-config');

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('backup', \strtolower((string)($result['message'] ?? '')));
        self::assertFileDoesNotExist($target);
        self::assertFileExists($safe);
        self::assertFileExists($backup);
    }

    public function testCaseAliasFailsBeforeDeletingCanonicalEvidence(): void
    {
        $target = $this->target();
        $safe = $target . '.10.' . \str_repeat('4', 8) . '.tmp';
        $alias = $target . '.TMP-' . \str_repeat('5', 24);
        $this->write($safe, 'safe');
        $this->write($alias, 'alias');

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('case alias', \strtolower((string)($result['message'] ?? '')));
        self::assertFileExists($safe);
        self::assertFileExists($alias);
        self::assertFileDoesNotExist($target);
    }

    public function testMalformedReservedLeafFailsBeforeDeletingCanonicalEvidence(): void
    {
        $target = $this->target();
        $safe = $target . '.11.' . \str_repeat('6', 8) . '.tmp';
        $malformed = $target . '.tmp-short';
        $this->write($safe, 'safe');
        $this->write($malformed, 'malformed');

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('malformed', \strtolower((string)($result['message'] ?? '')));
        self::assertFileExists($safe);
        self::assertFileExists($malformed);
        self::assertFileDoesNotExist($target);
    }

    public function testSymlinkedArtifactFailsWithoutTouchingItsPeer(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('POSIX symbolic-link fixture.');
        }
        $target = $this->target();
        $safe = $target . '.12.' . \str_repeat('7', 8) . '.tmp';
        $peer = $this->root . DIRECTORY_SEPARATOR . 'peer';
        $linked = $target . '.tmp-' . \str_repeat('8', 24);
        $this->write($safe, 'safe');
        $this->write($peer, 'peer');
        self::assertTrue(\symlink($peer, $linked));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertFileExists($safe);
        self::assertTrue(\is_link($linked));
        self::assertSame('peer', \file_get_contents($peer));
        self::assertFileDoesNotExist($target);
    }

    public function testHardLinkedArtifactFailsWithoutTouchingItsPeer(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('POSIX hard-link fixture.');
        }
        $target = $this->target();
        $safe = $target . '.13.' . \str_repeat('9', 8) . '.tmp';
        $peer = $this->root . DIRECTORY_SEPARATOR . 'peer';
        $linked = $target . '.tmp-' . \str_repeat('a', 24);
        $this->write($safe, 'safe');
        $this->write($peer, 'peer');
        self::assertTrue(\link($peer, $linked));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertFileExists($safe);
        self::assertFileExists($linked);
        self::assertSame('peer', \file_get_contents($peer));
        self::assertFileDoesNotExist($target);
    }

    public function testSpecialArtifactFailsBeforeDeletingCanonicalEvidence(): void
    {
        $target = $this->target();
        $safe = $target . '.14.' . \str_repeat('b', 8) . '.tmp';
        $special = $target . '.tmp-' . \str_repeat('c', 24);
        $this->write($safe, 'safe');
        self::assertTrue(\mkdir($special, 0755));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertFileExists($safe);
        self::assertDirectoryExists($special);
        self::assertFileDoesNotExist($target);
    }

    public function testOversizedArtifactFailsBeforeDeletingCanonicalEvidence(): void
    {
        $target = $this->target();
        $safe = $target . '.15.' . \str_repeat('d', 8) . '.tmp';
        $oversized = $target . '.tmp-' . \str_repeat('e', 24);
        $this->write($safe, 'safe');
        $this->write($oversized, \str_repeat('x', self::MAX_CONFIG_BYTES + 1));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('size', \strtolower((string)($result['message'] ?? '')));
        self::assertFileExists($safe);
        self::assertFileExists($oversized);
        self::assertFileDoesNotExist($target);
    }

    public function testArtifactQuotaFailsBeforeDeletingAnyEvidence(): void
    {
        $target = $this->target();
        $artifacts = [];
        for ($index = 1; $index <= 129; ++$index) {
            $artifact = $target . '.tmp-'
                . \str_pad(\dechex($index), 24, '0', STR_PAD_LEFT);
            $this->write($artifact, 'partial');
            $artifacts[] = $artifact;
        }

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('quota', \strtolower((string)($result['message'] ?? '')));
        foreach ($artifacts as $artifact) {
            self::assertFileExists($artifact);
        }
        self::assertFileDoesNotExist($target);
    }

    public function testHardLinkedStableLockIsRejectedWithoutPublication(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('POSIX hard-link fixture.');
        }
        $directory = $this->tlsDirectory();
        $target = $this->target();
        $peer = $this->root . DIRECTORY_SEPARATOR . 'lock-peer';
        $lock = $directory . DIRECTORY_SEPARATOR . '.openssl-performance.lock';
        $this->write($peer, 'peer');
        self::assertTrue(\link($peer, $lock));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertSame('peer', \file_get_contents($peer));
        self::assertFileDoesNotExist($target);
    }

    public function testForeignOwnerFailsBeforeDeletingCanonicalEvidence(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
            || !\function_exists('chown')
        ) {
            self::markTestSkipped('Requires a POSIX root fixture to assign a foreign owner.');
        }
        $account = \function_exists('posix_getpwnam') ? \posix_getpwnam('nobody') : false;
        $foreignUid = \is_array($account) ? (int)$account['uid'] : 1;
        if ($foreignUid <= 0) {
            self::markTestSkipped('No non-root account is available for the owner fixture.');
        }
        $target = $this->target();
        $safe = $target . '.16.' . \str_repeat('f', 8) . '.tmp';
        $foreign = $target . '.tmp-' . \str_repeat('0', 24);
        $this->write($safe, 'safe');
        $this->write($foreign, 'foreign');
        self::assertTrue(\chown($foreign, $foreignUid));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertStringContainsString('owner', \strtolower((string)($result['message'] ?? '')));
        self::assertFileExists($safe);
        self::assertFileExists($foreign);
        self::assertFileDoesNotExist($target);
    }

    public function testSymlinkedRuntimeDirectoryCannotPublishOutsideTheProject(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('POSIX symbolic-link fixture.');
        }
        $server = $this->root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server';
        self::assertTrue(\mkdir($server, 0755, true));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(\mkdir($outside, 0755));
        self::assertTrue(\symlink($outside, $server . DIRECTORY_SEPARATOR . 'tls'));

        $result = $this->runConfigurator();

        self::assertFalse($result['ok'] ?? true);
        self::assertFileDoesNotExist($outside . DIRECTORY_SEPARATOR . \basename($this->target()));
    }

    /** @return array<string,mixed> */
    private function runConfigurator(): array
    {
        $autoload = $this->repository . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $script = <<<'PHP'
define('BP', rtrim($argv[1], '/\\') . DIRECTORY_SEPARATOR);
require $argv[2];
putenv('OPENSSL_CONF');
unset($_ENV['OPENSSL_CONF'], $_SERVER['OPENSSL_CONF']);
$emit = static function (array $result): void {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
};
try {
    $result = (new \Weline\Server\Service\Runtime\TlsProcessProfileConfigurator())->activate(
        ['ssl' => ['key_exchange_profile' => 'performance']],
        true,
    );
    $emit(['ok' => true, 'path' => $result['openssl_conf']]);
} catch (Throwable $throwable) {
    $emit(['ok' => false, 'message' => $throwable->getMessage()]);
}
PHP;
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, '-r', $script, $this->root, $autoload],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repository,
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);
        self::assertSame(0, $exitCode, (string)$stderr);
        self::assertIsString($stdout);
        $decoded = \json_decode(\trim($stdout), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function tlsDirectory(): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'tls';
        if (!\is_dir($directory)) {
            self::assertTrue(\mkdir($directory, 0755, true));
        }
        return $directory;
    }

    private function target(): string
    {
        return $this->tlsDirectory() . DIRECTORY_SEPARATOR
            . 'openssl-performance-'
            . \substr(\hash('sha256', $this->content()), 0, 16)
            . '.cnf';
    }

    private function content(): string
    {
        return <<<'CONF'
openssl_conf = wls_init

[wls_init]
ssl_conf = wls_ssl

[wls_ssl]
system_default = wls_system_default

[wls_system_default]
Groups = X25519:P-256
CONF
            . "\n";
    }

    private function write(string $path, string $contents): void
    {
        self::assertSame(\strlen($contents), \file_put_contents($path, $contents));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0644));
        }
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        foreach ((array)@\scandir($path) as $leaf) {
            if ($leaf === '.' || $leaf === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $leaf;
            if (\is_dir($child) && !\is_link($child)) {
                $this->removeTree($child);
            } else {
                @\unlink($child);
            }
        }
        @\rmdir($path);
    }
}
