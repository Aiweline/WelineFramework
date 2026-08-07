<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;

final class ProjectAcmeHttp01ChallengeStoreTest extends TestCase
{
    private string $directory;
    private int $now = 1_000;
    private float $monotonicNow = 100.0;
    private string $hostBootId;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-acme-store-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
        $this->hostBootId = \str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testExplicitFilesystemRootIsRejectedAtConstruction(): void
    {
        $root = \realpath(\sys_get_temp_dir());
        self::assertIsString($root);
        $filesystemRoot = \preg_match('/\A([A-Za-z]:)[\\\\\/]/D', $root, $match) === 1
            ? $match[1] . DIRECTORY_SEPARATOR
            : DIRECTORY_SEPARATOR;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be a filesystem root');
        new ProjectAcmeHttp01ChallengeStore($filesystemRoot);
    }

    public function testStoreRecognizesExtendedWindowsFilesystemRoots(): void
    {
        $store = $this->store();
        $method = new \ReflectionMethod($store, 'isFilesystemRoot');
        foreach ([
            'C:\\',
            '\\\\server\\',
            '\\\\server\\share\\',
            '\\\\?\\C:\\',
            '\\\\?\\UNC\\server\\share\\',
            '\\\\?\\UNC\\server\\',
            '\\\\.\\C:\\',
        ] as $path) {
            self::assertTrue($method->invoke($store, $path), $path);
        }
        self::assertFalse($method->invoke(
            $store,
            '\\\\?\\UNC\\server\\share\\project',
        ));
    }

    public function testLegacyProjectionFailsClosedAndAdvancesGeneration(): void
    {
        $token = 'TOKEN_legacy';
        self::assertNotFalse(\file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'legacy_example_test.json',
            \json_encode([
                'token' => $token,
                'keyAuth' => $token . '.' . \str_repeat('L', 43),
                'generation' => 7,
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertTrue(\touch(
            $this->directory . DIRECTORY_SEPARATOR . 'legacy_example_test.json',
            $this->now,
        ));

        $desired = $this->store()->desired(['legacy.example.test']);
        self::assertSame(8, $desired['generation']);
        self::assertSame([], $desired['challenges']);
        self::assertFileExists($this->directory . DIRECTORY_SEPARATOR . '.desired.json');

        $registered = $this->store()->register(
            'legacy.example.test',
            $token,
            $token . '.' . \str_repeat('L', 43),
        );
        self::assertSame(9, $registered['generation']);
        self::assertSame(['legacy.example.test'], \array_column(
            $registered['challenges'],
            'domain',
        ));
    }

    public function testDesiredStateAndProjectionBackupsAreValidatedBeforeCollection(): void
    {
        $domain = 'recovery.example.test';
        $token = 'TOKEN_recovery';
        $store = $this->store();
        $store->register($domain, $token, $token . '.' . \str_repeat('R', 43));
        $state = $this->directory . DIRECTORY_SEPARATOR . '.desired.json';
        $projection = $this->directory . DIRECTORY_SEPARATOR
            . ProjectAcmeHttp01ChallengeStore::projectionFilename($domain) . '.json';
        $stateBackup = $state . '.wls-backup-' . \str_repeat('a', 16);
        $projectionBackup = $projection . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($state, $stateBackup));
        self::assertTrue(\copy($projection, $projectionBackup));

        self::assertSame(1, $store->desired()['generation']);
        self::assertFileDoesNotExist($stateBackup);
        self::assertFileDoesNotExist($projectionBackup);
    }

    public function testAnyMissingPairedAcmeTargetRetainsAllRecoveryEvidence(): void
    {
        $domain = 'missing-recovery.example.test';
        $token = 'TOKEN_missing_recovery';
        $store = $this->store();
        $store->register($domain, $token, $token . '.' . \str_repeat('M', 43));
        $state = $this->directory . DIRECTORY_SEPARATOR . '.desired.json';
        $projection = $this->directory . DIRECTORY_SEPARATOR
            . ProjectAcmeHttp01ChallengeStore::projectionFilename($domain) . '.json';
        $stateBackup = $state . '.wls-backup-' . \str_repeat('c', 16);
        $projectionBackup = $projection . '.wls-backup-' . \str_repeat('d', 16);
        self::assertTrue(\copy($state, $stateBackup));
        self::assertTrue(\rename($projection, $projectionBackup));

        try {
            $store->desired();
            self::fail('A missing paired ACME projection must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'paired target',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($stateBackup);
        self::assertFileExists($projectionBackup);
        self::assertFileDoesNotExist($projection);
    }

    public function testAnyBackupQuotaFailureRetainsAllAcmeRecoveryEvidence(): void
    {
        $domain = 'quota-recovery.example.test';
        $token = 'TOKEN_quota_recovery';
        $store = $this->store();
        $store->register($domain, $token, $token . '.' . \str_repeat('Q', 43));
        $state = $this->directory . DIRECTORY_SEPARATOR . '.desired.json';
        $projection = $this->directory . DIRECTORY_SEPARATOR
            . ProjectAcmeHttp01ChallengeStore::projectionFilename($domain) . '.json';
        $stateBackup = $state . '.wls-backup-' . \str_repeat('e', 16);
        self::assertTrue(\copy($state, $stateBackup));
        for ($index = 0; $index < 9; ++$index) {
            self::assertTrue(\copy(
                $projection,
                $projection . '.wls-backup-' . \str_pad(
                    \dechex($index),
                    16,
                    '0',
                    STR_PAD_LEFT,
                ),
            ));
        }

        try {
            $store->desired();
            self::fail('An exhausted ACME backup quota must fail before collection.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($stateBackup);
    }

    public function testNeverUsedEmptyDesiredDoesNotCreateAnUnauthorizedMutation(): void
    {
        $desired = $this->store()->desired();
        self::assertSame(0, $desired['generation']);
        self::assertSame([], $desired['challenges']);
        self::assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . '.desired.json');
    }

    public function testExpiredAgentDeadlineFailsBeforeDesiredStateLock(): void
    {
        $failure = null;
        try {
            $this->store()->desired(
                null,
                $this->monotonicNow - 1.0,
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('deadline', $failure->getMessage());
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
        );
    }

    public function testRegisterCannotReplaceOperationDeadlineWithLeaseDeadline(): void
    {
        $clockCalls = 0;
        $store = new ProjectAcmeHttp01ChallengeStore(
            $this->directory,
            fn (): int => $this->now,
            static function () use (&$clockCalls): float {
                ++$clockCalls;
                return $clockCalls <= 5 ? 100.0 : 116.0;
            },
            $this->hostBootId,
        );

        $failure = null;
        try {
            $store->register(
                'deadline.example.test',
                'TOKEN_deadline',
                'TOKEN_deadline.' . \str_repeat('D', 43),
                115.0,
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('deadline', $failure->getMessage());
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
        );
    }

    public function testMutationsPersistMonotonicDesiredGenerationAndLegacyProjection(): void
    {
        $store = $this->store();
        $first = $store->register(
            'one.example.test',
            'TOKEN_one',
            'TOKEN_one.' . \str_repeat('A', 43),
        );
        self::assertSame(1, $first['generation']);
        self::assertCount(1, $first['challenges']);
        self::assertSame(1_900, $first['challenges'][0]['expires_at']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first['digest']);

        $legacy = \json_decode(
            (string)\file_get_contents(
                $this->directory . DIRECTORY_SEPARATOR
                    . ProjectAcmeHttp01ChallengeStore::projectionFilename(
                        'one.example.test',
                    ) . '.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('TOKEN_one', $legacy['token']);
        self::assertSame(1_900, $legacy['expires_at']);
        self::assertSame(1, $legacy['generation']);

        $envelope = $this->readEnvelope();
        $persistedLease = $envelope['payload']['challenges']['one.example.test'] ?? [];
        self::assertSame(2, $envelope['payload']['schema_version'] ?? 0);
        self::assertSame($this->hostBootId, $persistedLease['host_boot_id'] ?? '');
        self::assertSame(100.0, $persistedLease['issued_monotonic'] ?? 0.0);
        self::assertSame(1_000.0, $persistedLease['deadline_monotonic'] ?? 0.0);
        self::assertSame(
            900.0,
            ($persistedLease['deadline_monotonic'] ?? 0.0)
                - ($persistedLease['issued_monotonic'] ?? 0.0),
        );

        $second = $store->register(
            'two.example.test',
            'TOKEN_two',
            'TOKEN_two.' . \str_repeat('B', 43),
        );
        self::assertSame(2, $second['generation']);
        self::assertCount(2, $second['challenges']);

        $reloaded = $this->store()->desired();
        self::assertSame($second, $reloaded);

        $removed = $store->remove('one.example.test');
        self::assertSame(3, $removed['generation']);
        self::assertSame(['two.example.test'], \array_column($removed['challenges'], 'domain'));
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR
                . ProjectAcmeHttp01ChallengeStore::projectionFilename(
                    'one.example.test',
                ) . '.json',
        );
    }

    public function testDesiredFiltersDomainsWithoutChangingProjectGeneration(): void
    {
        $store = $this->store();
        $store->register(
            'one.example.test',
            'TOKEN_one',
            'TOKEN_one.' . \str_repeat('A', 43),
        );
        $all = $store->register(
            'two.example.test',
            'TOKEN_two',
            'TOKEN_two.' . \str_repeat('B', 43),
        );
        $filtered = $store->desired(['two.example.test']);

        self::assertSame($all['generation'], $filtered['generation']);
        self::assertSame(['two.example.test'], \array_column($filtered['challenges'], 'domain'));
        self::assertNotSame($all['digest'], $filtered['digest']);
    }

    public function testExpiredLeaseIsPrunedAndAdvancesGenerationForClearReplay(): void
    {
        $store = $this->store();
        $store->register(
            'expired.example.test',
            'TOKEN_expired',
            'TOKEN_expired.' . \str_repeat('C', 43),
        );
        $this->now = 1_901;
        $this->monotonicNow = 1_000.0;

        $expired = $store->desired();
        self::assertSame(2, $expired['generation']);
        self::assertSame([], $expired['challenges']);
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . 'expired_example_test.json',
        );
    }

    public function testRemovePersistsExpiryOnlyGenerationAdvance(): void
    {
        $store = $this->store();
        $store->register(
            'expired.example.test',
            'TOKEN_expired',
            'TOKEN_expired.' . \str_repeat('C', 43),
        );
        $this->now = 1_901;
        $this->monotonicNow = 1_000.0;

        $removed = $store->remove('already-absent.example.test');
        self::assertSame(2, $removed['generation']);
        self::assertSame([], $removed['challenges']);

        $envelope = \json_decode(
            (string)\file_get_contents(
                $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            2,
            $envelope['payload']['generation'] ?? 0,
            'Expiry cleanup must persist the generation returned to the gateway.',
        );
    }

    public function testWallClockJumpsCannotExpireOrExtendTheMonotonicLease(): void
    {
        $store = $this->store();
        $token = 'TOKEN_clock';
        $authorization = $token . '.' . \str_repeat('D', 43);
        $store->register('clock.example.test', $token, $authorization);

        $this->now = 10_000_000;
        $this->monotonicNow = 999.0;
        self::assertSame([$authorization], \array_column(
            $store->desired()['challenges'],
            'key_authorization',
        ));
        self::assertSame(
            $authorization,
            ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
                $this->directory,
                'clock.example.test',
                $token,
                $this->now,
                $this->monotonicNow,
                $this->hostBootId,
            ),
        );

        $this->now = 1;
        self::assertSame(
            $authorization,
            ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
                $this->directory,
                'clock.example.test',
                $token,
                $this->now,
                $this->monotonicNow,
                $this->hostBootId,
            ),
        );

        $this->monotonicNow = 1_000.0;
        self::assertNull(ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
            $this->directory,
            'clock.example.test',
            $token,
            $this->now,
            $this->monotonicNow,
            $this->hostBootId,
        ));
        $expired = $store->desired();
        self::assertSame(2, $expired['generation']);
        self::assertSame([], $expired['challenges']);
    }

    public function testCrossBootLeaseFailsClosedAndCanBeReregistered(): void
    {
        $store = $this->store();
        $token = 'TOKEN_reboot';
        $authorization = $token . '.' . \str_repeat('E', 43);
        $store->register('reboot.example.test', $token, $authorization);

        $this->hostBootId = \str_repeat('b', 64);
        $this->monotonicNow = 10.0;
        self::assertNull(ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
            $this->directory,
            'reboot.example.test',
            $token,
            $this->now,
            $this->monotonicNow,
            $this->hostBootId,
        ));
        $retired = $this->store()->desired();
        self::assertSame(2, $retired['generation']);
        self::assertSame([], $retired['challenges']);

        $registered = $this->store()->register(
            'reboot.example.test',
            $token,
            $authorization,
        );
        self::assertSame(3, $registered['generation']);
        $persistedLease = $this->readEnvelope()['payload']['challenges'][
            'reboot.example.test'
        ] ?? [];
        self::assertSame($this->hostBootId, $persistedLease['host_boot_id'] ?? '');
        self::assertSame(10.0, $persistedLease['issued_monotonic'] ?? 0.0);
        self::assertSame(910.0, $persistedLease['deadline_monotonic'] ?? 0.0);
    }

    public function testDamagedLeaseFenceFailsClosedAndCanBeReregistered(): void
    {
        $store = $this->store();
        $token = 'TOKEN_damaged';
        $authorization = $token . '.' . \str_repeat('F', 43);
        $store->register('damaged.example.test', $token, $authorization);
        $envelope = $this->readEnvelope();
        $envelope['payload']['challenges']['damaged.example.test']['deadline_monotonic']
            = 1_001.0;
        $this->writeEnvelope($envelope['payload']);

        self::assertNull(ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
            $this->directory,
            'damaged.example.test',
            $token,
            $this->now,
            $this->monotonicNow,
            $this->hostBootId,
        ));
        $retired = $store->desired();
        self::assertSame(2, $retired['generation']);
        self::assertSame([], $retired['challenges']);

        $registered = $store->register('damaged.example.test', $token, $authorization);
        self::assertSame(3, $registered['generation']);
        self::assertCount(1, $registered['challenges']);
    }

    public function testSchemaOneEnvelopeFailsClosedAndCanAdvance(): void
    {
        $token = 'TOKEN_schema';
        $authorization = $token . '.' . \str_repeat('G', 43);
        $this->writeEnvelope([
            'schema_version' => 1,
            'generation' => 7,
            'challenges' => [
                'schema.example.test' => [
                    'domain' => 'schema.example.test',
                    'token' => $token,
                    'key_authorization' => $authorization,
                    'expires_at' => 1_900,
                    'generation' => 11,
                ],
            ],
            'updated_at' => '1970-01-01T00:16:40+00:00',
        ]);

        self::assertNull(ProjectAcmeHttp01ChallengeStore::resolvePublishedChallenge(
            $this->directory,
            'schema.example.test',
            $token,
            $this->now,
            $this->monotonicNow,
            $this->hostBootId,
        ));
        $retired = $this->store()->desired();
        self::assertSame(12, $retired['generation']);
        self::assertSame([], $retired['challenges']);
        self::assertSame(2, $this->readEnvelope()['payload']['schema_version'] ?? 0);

        self::assertSame(
            13,
            $this->store()->register(
                'schema.example.test',
                $token,
                $authorization,
            )['generation'],
        );
    }

    public function testInvalidMonotonicClockCannotMintALease(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $invalidNow) {
            $store = new ProjectAcmeHttp01ChallengeStore(
                $this->directory,
                fn (): int => $this->now,
                static fn (): float => $invalidNow,
                $this->hostBootId,
            );
            try {
                $store->register(
                    'invalid-clock.example.test',
                    'TOKEN_invalid_clock',
                    'TOKEN_invalid_clock.' . \str_repeat('I', 43),
                );
                self::fail('An invalid monotonic clock minted an ACME lease.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('monotonic clock', $exception->getMessage());
            }
            self::assertFileDoesNotExist(
                $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
            );
        }
    }

    public function testWildcardAndMalformedProofFailClosed(): void
    {
        $store = $this->store();
        foreach ([
            ['*.example.test', 'TOKEN', 'TOKEN.' . \str_repeat('A', 43)],
            ['example.test', 'bad token', 'bad token.' . \str_repeat('A', 43)],
            ['example.test', 'TOKEN', 'OTHER.' . \str_repeat('A', 43)],
        ] as [$domain, $token, $authorization]) {
            try {
                $store->register($domain, $token, $authorization);
                self::fail('Invalid ACME HTTP-01 challenge was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
        );
    }

    private function store(): ProjectAcmeHttp01ChallengeStore
    {
        return new ProjectAcmeHttp01ChallengeStore(
            $this->directory,
            fn (): int => $this->now,
            fn (): float => $this->monotonicNow,
            $this->hostBootId,
        );
    }

    /** @return array<string,mixed> */
    private function readEnvelope(): array
    {
        return \json_decode(
            (string)\file_get_contents(
                $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string,mixed> $payload */
    private function writeEnvelope(array $payload): void
    {
        self::assertNotFalse(\file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
            GatewayClient::canonicalJson([
                'payload' => $payload,
                'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
            ]),
        ));
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($target) && !\is_link($target)) {
                $this->removeTree($target);
            } else {
                @\unlink($target);
            }
        }
        @\rmdir($path);
    }
}
