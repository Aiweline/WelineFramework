<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\TlsSessionResumptionEvidenceStore;

final class TlsSessionResumptionEvidenceStoreRecoveryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-tls-evidence-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0750, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCollectsLegacyAndMissingTargetAtomicStagingArtifacts(): void
    {
        $legacy = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-12345-' . \str_repeat('a', 12);
        $staging = $this->root . DIRECTORY_SEPARATOR
            . \str_repeat('b', 64) . '.json.tmp-' . \str_repeat('c', 24);
        $this->write($legacy, 'legacy-partial');
        $this->write($staging, 'atomic-partial');

        $this->recover();

        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($staging);
    }

    public function testMissingInvalidBackupTargetPreservesTheCompleteRecoverySet(): void
    {
        $safe = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-1-' . \str_repeat('d', 12);
        $backup = $this->root . DIRECTORY_SEPARATOR
            . \str_repeat('e', 64) . '.json.wls-backup-' . \str_repeat('f', 16);
        $this->write($safe, 'safe');
        $this->write($backup, 'backup');

        try {
            $this->recover();
            self::fail('A missing immutable target must preserve its retained backup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'backup',
                \strtolower($exception->getMessage()),
            );
        }

        self::assertFileExists($safe);
        self::assertFileExists($backup);
    }

    public function testMissingValidBackupRestoresTheImmutableTargetBeforeCleanup(): void
    {
        [$digest, $json] = $this->validEvidenceDocument();
        $target = $this->root . DIRECTORY_SEPARATOR . $digest . '.json';
        $backup = $target . '.wls-backup-' . \str_repeat('a', 16);
        $staging = $target . '.tmp-' . \str_repeat('b', 24);
        $this->write($backup, $json);
        $this->write($staging, 'uncommitted');

        $this->recover();

        self::assertSame($json, \file_get_contents($target));
        self::assertFileDoesNotExist($backup);
        self::assertFileDoesNotExist($staging);
    }

    public function testLegacyHardLinkCrashAliasIsCollectedWithoutRemovingItsTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('POSIX legacy hard-link publication fixture.');
        }
        [$digest, $json] = $this->validEvidenceDocument();
        $target = $this->root . DIRECTORY_SEPARATOR . $digest . '.json';
        $legacy = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-123-' . \str_repeat('c', 12);
        $this->write($legacy, $json);
        self::assertTrue(\link($legacy, $target));
        self::assertSame(2, (int)(\lstat($target)['nlink'] ?? 0));

        $this->recover();

        self::assertFileDoesNotExist($legacy);
        self::assertSame($json, \file_get_contents($target));
        self::assertSame(1, (int)(\lstat($target)['nlink'] ?? 0));
    }

    public function testCaseAliasPreservesEveryCanonicalArtifact(): void
    {
        $safe = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-2-' . \str_repeat('1', 12);
        $alias = $this->root . DIRECTORY_SEPARATOR
            . '.TMP-3-' . \str_repeat('2', 12);
        $this->write($safe, 'safe');
        $this->write($alias, 'alias');

        try {
            $this->recover();
            self::fail('A case alias must fail before recovery mutation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('case alias', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($safe);
        self::assertFileExists($alias);
    }

    public function testLinkedArtifactPreservesEveryCanonicalArtifactAndPeer(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('POSIX hard-link fixture.');
        }
        $safe = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-4-' . \str_repeat('3', 12);
        $linked = $this->root . DIRECTORY_SEPARATOR
            . '.tmp-5-' . \str_repeat('4', 12);
        $peer = $this->root . DIRECTORY_SEPARATOR . 'peer';
        $this->write($safe, 'safe');
        $this->write($peer, 'peer');
        self::assertTrue(\link($peer, $linked));

        try {
            $this->recover();
            self::fail('A hard-linked recovery artifact must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('linked', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($safe);
        self::assertFileExists($linked);
        self::assertSame('peer', \file_get_contents($peer));
    }

    public function testArtifactQuotaFailsBeforeDeletingAnyEvidence(): void
    {
        $artifacts = [];
        for ($index = 1; $index <= 129; ++$index) {
            $path = $this->root . DIRECTORY_SEPARATOR . '.tmp-' . $index . '-'
                . \str_pad(\dechex($index), 12, '0', STR_PAD_LEFT);
            $this->write($path, 'partial');
            $artifacts[] = $path;
        }

        try {
            $this->recover();
            self::fail('An over-quota recovery namespace must fail before cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        }

        foreach ($artifacts as $path) {
            self::assertFileExists($path);
        }
    }

    public function testImmutablePublicationUsesSharedAtomicPrimitiveWithoutInPlaceFallback(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4)
                . '/Service/Runtime/TlsSessionResumptionEvidenceStore.php',
        );
        $start = \strpos($source, 'private function publishVerified(');
        $end = \strpos($source, 'private function assessDocument(', (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $publication = \substr($source, $start, $end - $start);

        self::assertStringContainsString(
            'GatewayProjectStateFilesystem::withExclusiveLock(',
            $publication,
        );
        self::assertStringContainsString(
            'GatewayProjectStateFilesystem::atomicWrite(',
            $publication,
        );
        self::assertStringNotContainsString("@\\fopen(\$path, 'xb')", $publication);
        self::assertStringNotContainsString('@\\link(\$temporary, \$path)', $publication);
        self::assertStringNotContainsString('@\\unlink(\$path)', $publication);
    }

    private function recover(): void
    {
        $store = new TlsSessionResumptionEvidenceStore($this->root);
        $method = new \ReflectionMethod(
            TlsSessionResumptionEvidenceStore::class,
            'recoverEvidencePublicationArtifactsLocked',
        );
        $method->invoke($store, $this->root);
    }

    private function write(string $path, string $contents): void
    {
        self::assertSame(\strlen($contents), \file_put_contents($path, $contents));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0640));
        }
    }

    /** @return array{0:string,1:string} */
    private function validEvidenceDocument(): array
    {
        $store = new TlsSessionResumptionEvidenceStore($this->root);
        $invoke = static function (
            TlsSessionResumptionEvidenceStore $target,
            string $method,
            mixed ...$arguments,
        ): mixed {
            return (new \ReflectionMethod($target, $method))->invoke(
                $target,
                ...$arguments,
            );
        };
        $runtime = $invoke($store, 'currentRuntimeIdentity');
        self::assertIsArray($runtime);
        $hash = \str_repeat('1', 64);
        $config = [
            'mode' => 'external',
            'enabled' => true,
            'timeout_seconds' => 300,
            'num_tickets' => 2,
            'local_cache_size' => 64,
            'max_session_bytes' => 16384,
            'max_entries' => 1024,
            'max_total_bytes' => 16777216,
            'callback_timeout_ms' => 2.0,
            'ready_timeout_ms' => 100.0,
            'reconnect_cooldown_ms' => 100.0,
            'context_epoch' => $hash,
        ];
        $scope = [
            'instance_name' => 'ai-test-evidence',
            'mechanism' => 'php86_external_stateful_cache',
            'transport' => 'tcp',
            'topology' => 'direct',
            'worker_count' => 2,
            'policy_digest' => $hash,
            'sni_sha256' => $hash,
            'certificate_sha256' => $hash,
        ];
        $scope['instance_scope_sha256'] = \hash(
            'sha256',
            (string)$invoke($store, 'canonicalJson', $scope),
        );
        $proofKeys = (new \ReflectionClass($store))->getConstant('PROOF_KEYS');
        self::assertIsArray($proofKeys);
        $proof = \array_fill_keys($proofKeys, 0);
        foreach ([
            'reload_worker_fingerprint_changed',
            'reload_preserved_session_resumed',
            'sidecar_generation_changed',
        ] as $key) {
            $proof[$key] = false;
        }
        $proof['sidecar_fault_method'] = 'authenticated_server_shutdown';
        $proof['resumption_tls_p95_ms'] = 0.0;
        $proof['resumption_tls_p95_limit_ms'] = 1.0;
        $document = [
            'schema_version' => 2,
            'kind' => TlsSessionResumptionEvidenceStore::KIND,
            'captured_at' => \gmdate(DATE_ATOM),
            'runtime' => $runtime,
            'bindings' => [
                'integration_sha256' => $hash,
                'verifier_sha256' => $hash,
                'config_sha256' => $invoke(
                    $store,
                    'configSha256FromArray',
                    $config,
                ),
            ],
            'config' => $config,
            'verification' => [
                'same_worker_rounds' => 0,
                'cross_worker_rounds' => 0,
                'reload_rounds' => 0,
                'fault_rounds' => 0,
                'post_recovery_rounds' => 0,
                'connect_timeout_ms' => 0,
                'resumption_tls_p95_limit_ms' => 1.0,
            ],
            'scope' => $scope,
            'proof' => $proof,
            'performance_reports' => [],
        ];
        $digest = $invoke($store, 'documentSha256', $document);
        self::assertIsString($digest);
        $document['evidence_sha256'] = $digest;
        $failures = $invoke($store, 'documentSchemaFailures', $document, '');
        self::assertSame([], $failures);
        $json = \json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;

        return [$digest, $json];
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
