<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;

final class ProjectServingManifestStoreTest extends TestCase
{
    private string $root = '';
    private string $snapshotDigest = '';

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-serving-manifest-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/snapshots',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'var/server',
            0755,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->snapshotDigest = $this->createSnapshot(
            'certificate-bytes',
            'private-key-bytes',
            'chain-bytes',
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testNormalizeHostAllowsLoopbackLiteralsUsedByLocalWls(): void
    {
        self::assertSame('localhost', ProjectServingManifestStore::normalizeHost('localhost'));
        self::assertSame('*.localhost', ProjectServingManifestStore::normalizeHost('*.localhost'));
        self::assertSame('127.0.0.1', ProjectServingManifestStore::normalizeHost('127.0.0.1'));
        self::assertSame('::1', ProjectServingManifestStore::normalizeHost('::1'));
        self::assertSame('172.31.35.19', ProjectServingManifestStore::normalizeHost('172.31.35.19'));

        $this->expectException(\InvalidArgumentException::class);
        ProjectServingManifestStore::normalizeHost('8.8.8.8');
    }

    public function testRouteForHostFallsBackAcrossLoopbackAliases(): void
    {
        $localhost = [
            'route_id' => 'loopback',
            'domain' => 'localhost',
        ];
        $routes = ['localhost' => $localhost];

        self::assertSame($localhost, ProjectServingManifestStore::routeForHost('127.0.0.1', $routes));
        self::assertSame($localhost, ProjectServingManifestStore::routeForHost('::1', $routes));
        self::assertSame($localhost, ProjectServingManifestStore::routeForHost('localhost', $routes));

        $ip = [
            'route_id' => 'ipv4-loopback',
            'domain' => '127.0.0.1',
        ];
        self::assertSame($ip, ProjectServingManifestStore::routeForHost('localhost', [
            '127.0.0.1' => $ip,
        ]));
    }

    public function testWholeProjectPublicationIsAtomicIdempotentAndFenceBound(): void
    {
        $registration = $this->registration('primary', [
            $this->route('example.test'),
            $this->route('www.example.test'),
        ]);
        $store = new ProjectServingManifestStore($this->root);

        $first = $store->publishFromRegistration($registration);
        $same = $store->publishFromRegistration($registration);

        self::assertSame(1, $first['generation']);
        self::assertSame($first['digest'], $same['digest']);
        self::assertSame(2, $first['route_count']);
        self::assertTrue($first['converged']);
        self::assertFileExists($first['path']);
        self::assertSame(
            $first['digest'],
            $store->currentForFence($this->fence('primary'))['digest'],
        );
    }

    public function testFallbackBootstrapSelectsActiveSiblingWhenPrimaryIsInactive(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        foreach (['pending', 'disabled'] as $state) {
            $instance = $state . '-primary';
            $activeDomain = $state . '-sibling.example.test';
            $publication = $store->publishFromRegistration($this->registration(
                $instance,
                [
                    $this->inactiveRoute($state . '-primary.example.test', $state),
                    $this->route($activeDomain),
                ],
            ));

            $selection = $store->activeTlsSelectionForFence(
                $this->fence($instance),
                $publication['generation'],
                $publication['digest'],
                $publication['route_count'],
            );

            self::assertSame(1, $publication['route_count']);
            self::assertSame($activeDomain, $selection['domain']);
            self::assertSame([$activeDomain], $selection['active_domains']);
            self::assertSame(
                $publication['digest'],
                $selection['publication']['digest'],
            );
            self::assertFileExists($selection['certificate']);
            self::assertFileExists($selection['private_key']);
        }
    }

    public function testStartupCertificateFenceComesFromTheExactServingManifest(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $publication = $store->publishFromRegistration($this->registration(
            'startup-fence',
            [
                $this->route('primary.example.test'),
                $this->route('sibling.example.test'),
            ],
        ));

        $fence = ProjectServingManifestStore::activeCertificateFenceForDomain(
            $publication,
            'PRIMARY.EXAMPLE.TEST.',
        );

        self::assertSame('primary.example.test', $fence['domain']);
        self::assertSame(3, $fence['generation']);
        self::assertSame($this->snapshotDigest, $fence['source_digest']);
        self::assertFileExists($fence['cert_path']);
        self::assertFileExists($fence['key_path']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('absent from the exact serving manifest');
        ProjectServingManifestStore::activeCertificateFenceForDomain(
            $publication,
            'missing.example.test',
        );
    }

    public function testFallbackBootstrapRejectsManifestWithNoActiveCertificate(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $publication = $store->publishFromRegistration($this->registration(
            'all-pending',
            [
                $this->inactiveRoute('first-pending.example.test', 'pending'),
                $this->inactiveRoute('second-pending.example.test', 'pending'),
            ],
        ));

        self::assertSame(0, $publication['route_count']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least one ACTIVE serving route');
        $store->activeTlsSelectionForFence(
            $this->fence('all-pending'),
            $publication['generation'],
            $publication['digest'],
            $publication['route_count'],
        );
    }

    public function testFallbackBootstrapRejectsConcurrentManifestReplacement(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $firstRegistration = $this->registration('racing-primary', [
            $this->route('before-race.example.test'),
        ]);
        $first = $store->publishFromRegistration($firstRegistration);
        $nextRegistration = [
            ...$firstRegistration,
            'project_generation' => 6,
            'request_digest' => \str_repeat('e', 64),
            'non_certificate_desired_digest' => \str_repeat('f', 64),
            'routes' => [$this->route('after-race.example.test')],
        ];
        $next = $store->publishFromRegistration($nextRegistration);

        self::assertGreaterThan($first['generation'], $next['generation']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'serving manifest changed before listener activation',
        );
        $store->activeTlsSelectionForFence(
            $this->fence('racing-primary'),
            $first['generation'],
            $first['digest'],
            $first['route_count'],
        );
    }

    public function testCommittedPointerSurvivesExpiredPostCommitReferenceCleanup(): void
    {
        $now = \hrtime(true) / 1_000_000_000;
        $deadline = $now + 0.5;
        $transitionDeadlines = [];
        $store = new ProjectServingManifestStore(
            $this->root,
            publicationMonotonicClock: static function () use (&$now): float {
                return $now;
            },
            snapshotReferenceTransition: static function (
                array $_referenced,
                array $_retiring,
                ?float $transitionDeadline,
            ) use (&$now, $deadline, &$transitionDeadlines): void {
                $transitionDeadlines[] = $transitionDeadline;
                if (\count($transitionDeadlines) === 2) {
                    $now = $deadline + 1.0;
                    throw new \RuntimeException('synthetic post-commit cleanup failure');
                }
            },
        );

        $published = $store->publishFromRegistration(
            $this->registration('primary', [$this->route('deadline.example.test')]),
            deadlineMonotonic: $deadline,
        );

        self::assertSame(1, $published['generation']);
        self::assertSame($published['digest'], $store->current('primary')['digest']);
        self::assertCount(2, $transitionDeadlines);
        self::assertSame($deadline, $transitionDeadlines[0]);
        self::assertGreaterThan($deadline, $transitionDeadlines[1]);
    }

    public function testSnapshotReferenceCallbackCommitIsNotReversedByExpiredDeadline(): void
    {
        $now = 300.0;
        $store = new ProjectServingManifestStore(
            $this->root,
            publicationMonotonicClock: static function () use (&$now): float {
                return $now;
            },
        );
        $fact = $this->root . DIRECTORY_SEPARATOR
            . 'var/server/reference-callback.fact';

        $result = $store->withCertificateSnapshotReferences(
            static function (array $references) use (&$now, $fact): string {
                self::assertSame([], $references);
                GatewayProjectStateFilesystem::atomicWrite(
                    $fact,
                    "committed\n",
                    0600,
                );
                $now = 301.0;
                return 'committed';
            },
            300.5,
        );

        self::assertSame('committed', $result);
        self::assertSame("committed\n", \file_get_contents($fact));
    }

    public function testInstancePointersAndGenerationFloorsAreIsolated(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $first = $store->publishFromRegistration($this->registration(
            'primary',
            [$this->route('primary.example.test')],
        ));
        $second = $store->publishFromRegistration($this->registration(
            'secondary',
            [$this->route('secondary.example.test')],
        ));

        self::assertSame(1, $first['generation']);
        self::assertSame(1, $second['generation']);
        self::assertNotSame($first['digest'], $second['digest']);
        self::assertNotSame(
            $store->currentPointerPath('primary'),
            $store->currentPointerPath('secondary'),
        );
        self::assertSame(
            $first['digest'],
            $store->currentForFence($this->fence('primary'))['digest'],
        );
    }

    public function testPartialGatewaySubsetCannotClaimConvergence(): void
    {
        $first = $this->route('one.example.test');
        $second = $this->route('two.example.test');
        $registration = $this->registration('primary', [$first, $second]);
        $store = new ProjectServingManifestStore($this->root);

        $partial = $store->publishFromRegistration(
            $registration,
            [(string)$first['route_id']],
            [(string)$first['route_id'] => 7],
            false,
        );

        self::assertFalse($partial['converged']);
        self::assertSame(1, $partial['route_count']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot mark a partial route subset converged');
        $store->publishFromRegistration(
            $registration,
            [(string)$first['route_id']],
            [(string)$first['route_id'] => 7],
            true,
        );
    }

    public function testSelectedRouteGenerationsRejectUnboundExtraFence(): void
    {
        $first = $this->route('one-generation.example.test');
        $second = $this->route('two-generation.example.test');
        $registration = $this->registration('primary', [$first, $second]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'route generations do not exactly cover the selected route set',
        );
        (new ProjectServingManifestStore($this->root))->publishFromRegistration(
            $registration,
            [(string)$first['route_id']],
            [
                (string)$first['route_id'] => 7,
                (string)$second['route_id'] => 9,
            ],
            false,
        );
    }

    public function testRedirectTargetMustRemainInsideTheServingSubset(): void
    {
        $root = $this->route('redirect.example.test', true);
        $target = $this->route('www.redirect.example.test');
        $registration = $this->registration('primary', [$root, $target]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('target is outside the exact serving subset');
        (new ProjectServingManifestStore($this->root))->publishFromRegistration(
            $registration,
            [(string)$root['route_id']],
            [(string)$root['route_id'] => 9],
            false,
        );
    }

    public function testMaterialMutationCannotReplaceTheLastGoodGeneration(): void
    {
        $route = $this->route('stable.example.test');
        $registration = $this->registration('primary', [$route]);
        $store = new ProjectServingManifestStore($this->root);
        $active = $store->publishFromRegistration($registration);
        $pointerPath = $store->currentPointerPath('primary');
        $pointerBefore = \json_decode((string)\file_get_contents($pointerPath), true);
        $certPath = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $this->snapshotDigest
            . '/fullchain.pem';
        self::assertNotFalse(\file_put_contents($certPath, 'mutated-certificate'));

        try {
            $store->publishFromRegistration($registration);
            self::fail('Mutated material must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('snapshot integrity', $exception->getMessage());
        }
        $pointerAfter = \json_decode((string)\file_get_contents($pointerPath), true);
        self::assertIsArray($pointerBefore);
        self::assertIsArray($pointerAfter);
        self::assertSame(
            $active['digest'],
            $pointerBefore['digest'] ?? null,
        );
        self::assertSame(
            $pointerBefore['digest'] ?? null,
            $pointerAfter['digest'] ?? null,
        );
    }

    public function testRenewalPublishesANewClosureWithoutInvalidatingOldManifest(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $old = $store->publishFromRegistration($this->registration(
            'primary',
            [$this->route('renew.example.test')],
        ));
        $nextDigest = $this->createSnapshot(
            'renewed-certificate-bytes',
            'renewed-private-key-bytes',
            'renewed-chain-bytes',
        );
        $nextRegistration = $this->registration('primary', [
            $this->route('renew.example.test', false, $nextDigest, 4),
        ]);

        $next = $store->publishFromRegistration($nextRegistration);

        self::assertSame(2, $next['generation']);
        self::assertNotSame($old['digest'], $next['digest']);
        self::assertSame(
            $old['digest'],
            $store->readBound(
                (string)$old['path'],
                (int)$old['generation'],
                (string)$old['digest'],
            )['digest'],
        );
        self::assertDirectoryExists(
            $this->root . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/snapshots/' . $this->snapshotDigest,
        );
    }

    public function testRecentTwoLkgManifestsProtectTheirCertificateSnapshots(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $digests = [$this->snapshotDigest];
        for ($generation = 1; $generation <= 4; $generation++) {
            if ($generation > 1) {
                $digests[] = $this->createSnapshot(
                    'lkg-certificate-' . $generation,
                    'lkg-private-key-' . $generation,
                    'lkg-chain-' . $generation,
                );
            }
            $store->publishFromRegistration($this->registration('primary', [
                $this->route(
                    'lkg.example.test',
                    false,
                    $digests[$generation - 1],
                    $generation,
                ),
            ]));
        }

        $references = $store->referencedCertificateSnapshotDigests();
        self::assertArrayNotHasKey($digests[0], $references);
        self::assertArrayHasKey($digests[1], $references);
        self::assertArrayHasKey($digests[2], $references);
        self::assertArrayHasKey($digests[3], $references);
    }

    public function testInactiveInstanceRetirementReplaysPastCorruptHistoricalLkg(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $instanceId = 'retired-corrupt-lkg';
        $first = $store->publishFromRegistration($this->registration(
            $instanceId,
            [$this->route('retired-lkg.example.test')],
        ));
        $nextDigest = $this->createSnapshot(
            'retired-lkg-certificate-2',
            'retired-lkg-private-key-2',
            'retired-lkg-chain-2',
        );
        $current = $store->publishFromRegistration($this->registration(
            $instanceId,
            [$this->route('retired-lkg.example.test', false, $nextDigest, 4)],
        ));
        $currentEnvelope = \json_decode(
            (string)\file_get_contents((string)$current['path']),
            true,
        );
        self::assertIsArray($currentEnvelope);
        unset($currentEnvelope['digest']);
        $currentEnvelope['schema'] = 'wls-project-serving-manifest/2';
        $legacyDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($currentEnvelope),
        );
        $legacyPath = \dirname((string)$current['path']) . DIRECTORY_SEPARATOR
            . (int)$current['generation'] . '-' . $legacyDigest . '.json';
        $currentEnvelope['digest'] = $legacyDigest;
        self::assertNotFalse(\file_put_contents(
            $legacyPath,
            \json_encode($currentEnvelope, JSON_THROW_ON_ERROR),
        ));
        self::assertTrue(\unlink((string)$current['path']));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($legacyPath, 0600));
        }
        $pointerPath = $store->currentPointerPath($instanceId);
        $pointer = \json_decode((string)\file_get_contents($pointerPath), true);
        self::assertIsArray($pointer);
        $pointer['digest'] = $legacyDigest;
        $pointer['path'] = $legacyPath;
        unset($pointer['sha256']);
        $pointer['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($pointer),
        );
        self::assertNotFalse(\file_put_contents(
            $pointerPath,
            \json_encode($pointer, JSON_THROW_ON_ERROR),
        ));
        $instanceKey = \substr(\hash('sha256', $instanceId), 0, 32);
        $stateRoot = \dirname($pointerPath);
        $authorityPath = $stateRoot . DIRECTORY_SEPARATOR
            . 'authority-' . $instanceKey . '.json';
        $authority = \json_decode(
            (string)\file_get_contents($authorityPath),
            true,
        );
        self::assertIsArray($authority);
        $authority['manifest_digest'] = $legacyDigest;
        unset($authority['sha256']);
        $authority['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($authority),
        );
        self::assertNotFalse(\file_put_contents(
            $authorityPath,
            \json_encode($authority, JSON_THROW_ON_ERROR),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($pointerPath, 0600));
            self::assertTrue(\chmod($authorityPath, 0600));
        }
        $current['path'] = $legacyPath;
        $current['digest'] = $legacyDigest;
        self::assertArrayHasKey(
            $nextDigest,
            $store->referencedCertificateSnapshotDigests(),
            'A legacy schema-2 manifest remains GC evidence, never serving authority.',
        );
        self::assertNotFalse(\file_put_contents(
            (string)$first['path'],
            '{"corrupt":true}',
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod((string)$first['path'], 0600));
        }

        try {
            $store->referencedCertificateSnapshotDigests();
            self::fail('A referenced corrupt historical manifest must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'envelope integrity failed',
                $exception->getMessage(),
            );
        }

        try {
            $store->retireInactiveInstanceReferences(
                $instanceId,
                (int)$current['generation'],
                \str_repeat('f', 64),
            );
            self::fail('A mismatched endpoint serving proof must not retire references.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'does not match the selected inactive endpoint',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($store->currentPointerPath($instanceId));

        $store->retireInactiveInstanceReferences(
            $instanceId,
            (int)$current['generation'],
            (string)$current['digest'],
        );

        foreach ([
            $stateRoot . DIRECTORY_SEPARATOR . 'lkg-' . $instanceKey . '.json',
            $store->currentPointerPath($instanceId),
            $stateRoot . DIRECTORY_SEPARATOR . 'generation-' . $instanceKey,
            $stateRoot . DIRECTORY_SEPARATOR . 'authority-' . $instanceKey . '.json',
        ] as $retiredPath) {
            self::assertFileDoesNotExist($retiredPath);
        }
        self::assertFileExists((string)$first['path']);
        self::assertSame([], $store->referencedCertificateSnapshotDigests());

        // Authority is the retirement commit marker. Once it is absent and
        // every earlier mutable reference is absent, replay is idempotent.
        $store->retireInactiveInstanceReferences(
            $instanceId,
            (int)$current['generation'],
            (string)$current['digest'],
        );
        self::assertSame([], $store->referencedCertificateSnapshotDigests());
    }

    public function testMutableRecoveryBackupsRequireWholeClosureValidationBeforeCleanup(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $registration = $this->registration('primary', [
            $this->route('recovery-backup.example.test'),
        ]);
        $store->publishFromRegistration($registration);
        $nextDigest = $this->createSnapshot(
            'recovery-backup-certificate',
            'recovery-backup-private-key',
            'recovery-backup-chain',
        );
        $registration = $this->registration('primary', [
            $this->route(
                'recovery-backup.example.test',
                false,
                $nextDigest,
                4,
            ),
        ]);
        $store->publishFromRegistration($registration);

        $stateRoot = \dirname($store->currentPointerPath('primary'));
        $instanceKey = \substr(\hash('sha256', 'primary'), 0, 32);
        $targets = [
            $stateRoot . DIRECTORY_SEPARATOR . 'generation-' . $instanceKey,
            $stateRoot . DIRECTORY_SEPARATOR . 'authority-' . $instanceKey . '.json',
            $store->currentPointerPath('primary'),
            $stateRoot . DIRECTORY_SEPARATOR . 'lkg-' . $instanceKey . '.json',
            $stateRoot . DIRECTORY_SEPARATOR . 'manifest-retirement.json',
        ];
        $backups = [];
        foreach ($targets as $index => $target) {
            self::assertFileExists($target);
            $backup = $target . '.wls-backup-'
                . \str_pad(\dechex($index + 1), 16, '0', STR_PAD_LEFT);
            self::assertTrue(\copy($target, $backup));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($backup, 0600));
            }
            $backups[] = $backup;
        }

        $store->publishFromRegistration($registration);

        foreach ($backups as $backup) {
            self::assertFileDoesNotExist($backup);
        }

        $pointerBackup = $store->currentPointerPath('primary')
            . '.wls-backup-' . \str_repeat('a', 16);
        $lkg = $stateRoot . DIRECTORY_SEPARATOR . 'lkg-' . $instanceKey . '.json';
        $lkgBackup = $lkg . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($store->currentPointerPath('primary'), $pointerBackup));
        self::assertTrue(\copy($lkg, $lkgBackup));
        self::assertNotFalse(\file_put_contents($lkg, '{"corrupt":true}'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            foreach ([$pointerBackup, $lkgBackup, $lkg] as $path) {
                self::assertTrue(\chmod($path, 0600));
            }
        }

        try {
            $store->publishFromRegistration($registration);
            self::fail('A damaged paired target must fail the complete recovery closure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('LKG reference is corrupt', $exception->getMessage());
        }
        self::assertFileExists($pointerBackup);
        self::assertFileExists($lkgBackup);
    }

    public function testServingManifestStoreRejectsFilesystemProjectRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('project root is unavailable');
        new ProjectServingManifestStore($this->filesystemRoot());
    }

    public function testServingManifestStoreRecognizesExtendedWindowsFilesystemRoots(): void
    {
        $store = new ProjectServingManifestStore($this->root);
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

    public function testMutableCertificateSourcePathCannotBypassActiveAuthority(): void
    {
        $sourceDirectory = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/source';
        self::assertTrue(\mkdir($sourceDirectory, 0700));
        self::assertNotFalse(\file_put_contents(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            'mutable-certificate',
        ));
        self::assertNotFalse(\file_put_contents(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
            'mutable-private-key',
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod(
                $sourceDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem',
                0600,
            ));
            self::assertTrue(\chmod(
                $sourceDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
                0600,
            ));
        }
        $route = $this->route('mutable.example.test');
        $route['certificate']['cert']['relative_path'] = 'source/fullchain.pem';
        $route['certificate']['key']['relative_path'] = 'source/privkey.pem';
        $route['certificate']['source_digest'] = \hash(
            'sha256',
            \hash('sha256', 'mutable-certificate') . ':'
                . \hash('sha256', 'mutable-private-key') . ':',
        );
        $route['certificate']['provenance_digest']
            = ProjectCertificateGenerationStore::provenanceDigest(
                'mutable.example.test',
                (string)$route['certificate']['source_digest'],
                ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
                ProjectCertificateGenerationStore::PROVIDER_SELF_SIGNED,
                ProjectCertificateGenerationStore::MATERIAL_CLASS_SELF_SIGNED,
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'does not match the active project certificate provenance authority',
        );
        (new ProjectServingManifestStore($this->root))->publishFromRegistration(
            $this->registration('primary', [$route]),
        );
    }

    public function testIdempotentPayloadDoesNotReuseCurrentBehindGenerationFloor(): void
    {
        $registration = $this->registration('primary', [
            $this->route('floor.example.test'),
        ]);
        $store = new ProjectServingManifestStore($this->root);
        $first = $store->publishFromRegistration($registration);
        $stateRoot = \dirname($store->currentPointerPath('primary'));
        $floor = $stateRoot . DIRECTORY_SEPARATOR . 'generation-'
            . \substr(\hash('sha256', 'primary'), 0, 32);
        self::assertNotFalse(\file_put_contents($floor, "2\n"));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($floor, 0600));
        }

        $next = $store->publishFromRegistration($registration);

        self::assertSame(1, $first['generation']);
        self::assertSame(3, $next['generation']);
        self::assertNotSame($first['digest'], $next['digest']);
        self::assertSame(3, $store->current('primary')['generation']);
        self::assertSame(
            $first['digest'],
            $store->readBound(
                (string)$first['path'],
                (int)$first['generation'],
                (string)$first['digest'],
            )['digest'],
        );
    }

    public function testManifestStoreFailsClosedAtItsGenerationCountQuota(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $manifestRoot = \dirname($store->currentPointerPath('primary'))
            . DIRECTORY_SEPARATOR . 'manifests';
        self::assertTrue(\mkdir($manifestRoot, 0700, true));
        for ($generation = 1; $generation <= 128; $generation++) {
            $path = $manifestRoot . DIRECTORY_SEPARATOR . $generation . '-'
                . \hash('sha256', 'quota-' . $generation) . '.json';
            self::assertNotFalse(\file_put_contents($path, '{}'));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($path, 0600));
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no capacity for another generation');
        $store->publishFromRegistration($this->registration('primary', [
            $this->route('quota.example.test'),
        ]));
    }

    public function testCrashOrphanedImmutableManifestCandidateIsRecovered(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $manifestRoot = \dirname($store->currentPointerPath('primary'))
            . DIRECTORY_SEPARATOR . 'manifests';
        self::assertTrue(\mkdir($manifestRoot, 0700, true));
        $orphan = $manifestRoot . DIRECTORY_SEPARATOR . '1-'
            . \str_repeat('a', 64) . '.json.tmp-' . \str_repeat('b', 24);
        self::assertNotFalse(\file_put_contents($orphan, '{"partial":true}'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($orphan, 0600));
        }

        $published = $store->publishFromRegistration($this->registration(
            'primary',
            [$this->route('atomic-recovery.example.test')],
        ));

        self::assertSame(1, $published['generation']);
        self::assertFileExists($published['path']);
        self::assertFileDoesNotExist($orphan);
    }

    public function testManifestStoreGraceStartsWhenAnOldGenerationBecomesUnreferenced(): void
    {
        $wall = 1_900_000_000;
        $monotonic = 50_000.0;
        $boot = \str_repeat('7', 64);
        $store = new ProjectServingManifestStore(
            $this->root,
            publicationWallClock: static function () use (&$wall): int {
                return $wall;
            },
            publicationMonotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            publicationBootIdentityResolver: static function () use (&$boot): string {
                return $boot;
            },
        );
        $manifestRoot = \dirname($store->currentPointerPath('primary'))
            . DIRECTORY_SEPARATOR . 'manifests';
        self::assertTrue(\mkdir($manifestRoot, 0700, true));
        $expired = \time() - 604_801;
        for ($generation = 1; $generation <= 128; $generation++) {
            $path = $manifestRoot . DIRECTORY_SEPARATOR . $generation . '-'
                . \hash('sha256', 'expired-quota-' . $generation) . '.json';
            self::assertNotFalse(\file_put_contents($path, '{}'));
            self::assertTrue(\touch($path, $expired));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($path, 0600));
            }
        }

        try {
            $store->publishFromRegistration($this->registration('primary', [
                $this->route('reclaimed-quota.example.test'),
            ]));
            self::fail('Ancient file metadata must not shorten the retirement grace.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'no capacity for another generation',
                $exception->getMessage(),
            );
        }
        self::assertCount(128, \glob($manifestRoot . DIRECTORY_SEPARATOR . '*.json') ?: []);

        $wall += 604_800;
        $monotonic += 604_800.0;

        $published = $store->publishFromRegistration($this->registration('primary', [
            $this->route('reclaimed-quota.example.test'),
        ]));

        self::assertSame(1, $published['generation']);
        self::assertSame(1, $published['route_count']);
        self::assertFileExists($published['path']);
    }

    public function testManifestRetirementStateDamageRebootAndFutureClockRestartGrace(): void
    {
        $wall = 1_910_000_000;
        $monotonic = 60_000.0;
        $boot = \str_repeat('6', 64);
        $store = new ProjectServingManifestStore(
            $this->root,
            publicationWallClock: static function () use (&$wall): int {
                return $wall;
            },
            publicationMonotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            publicationBootIdentityResolver: static function () use (&$boot): string {
                return $boot;
            },
        );
        $digest = \str_repeat('5', 64);
        $entry = [[
            'path' => \dirname($store->currentPointerPath('primary'))
                . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR
                . '1-' . $digest . '.json',
            'size' => 1,
            'mtime' => 1,
            'generation' => 1,
            'digest' => $digest,
        ]];
        $collectable = new \ReflectionMethod($store, 'collectableManifestRetirementEntries');

        self::assertSame([], $collectable->invoke($store, $entry, []));
        $wall += 604_800;
        $monotonic += 604_800.0;
        self::assertCount(1, $collectable->invoke($store, $entry, []));

        $stateFile = \dirname($store->currentPointerPath('primary'))
            . DIRECTORY_SEPARATOR . 'manifest-retirement.json';
        self::assertNotFalse(\file_put_contents($stateFile, '{corrupt'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($stateFile, 0600));
        }
        self::assertSame([], $collectable->invoke($store, $entry, []));

        $wall += 604_800;
        $monotonic += 604_800.0;
        $boot = \str_repeat('4', 64);
        self::assertSame([], $collectable->invoke($store, $entry, []));

        $envelope = \json_decode((string)\file_get_contents($stateFile), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['updated_monotonic'] = $monotonic + 1.0;
        $envelope['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($envelope['payload']),
        );
        self::assertNotFalse(\file_put_contents(
            $stateFile,
            \json_encode($envelope, JSON_THROW_ON_ERROR),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($stateFile, 0600));
        }
        self::assertSame([], $collectable->invoke($store, $entry, []));
    }

    public function testManifestReReferenceClearsItsRetirementMarker(): void
    {
        $wall = 1_920_000_000;
        $monotonic = 70_000.0;
        $boot = \str_repeat('3', 64);
        $store = new ProjectServingManifestStore(
            $this->root,
            publicationWallClock: static function () use (&$wall): int {
                return $wall;
            },
            publicationMonotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            publicationBootIdentityResolver: static function () use (&$boot): string {
                return $boot;
            },
        );
        $registration = $this->registration('primary', [
            $this->route('re-reference.example.test'),
        ]);
        $published = $store->publishFromRegistration($registration);
        $entry = [[
            'path' => (string)$published['path'],
            'size' => (int)\filesize((string)$published['path']),
            'mtime' => 1,
            'generation' => (int)$published['generation'],
            'digest' => (string)$published['digest'],
        ]];
        $collectable = new \ReflectionMethod($store, 'collectableManifestRetirementEntries');
        self::assertSame([], $collectable->invoke($store, $entry, []));

        $store->publishFromRegistration($registration);

        $stateFile = \dirname($store->currentPointerPath('primary'))
            . DIRECTORY_SEPARATOR . 'manifest-retirement.json';
        $envelope = \json_decode((string)\file_get_contents($stateFile), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        self::assertArrayNotHasKey(
            (string)$published['digest'],
            $envelope['payload']['markers'] ?? [],
        );
    }

    public function testCurrentPointerRejectsRetiredLaunchAndProjectGenerationRollback(): void
    {
        $registration = $this->registration('primary', [
            $this->route('monotonic.example.test'),
        ]);
        $store = new ProjectServingManifestStore($this->root);
        $first = $store->publishFromRegistration($registration);

        $rejected = [
            [[...$registration, 'instance_generation' => 10], 'stale instance generation'],
            [[...$registration, 'master_pid' => 12346], 'another or stale Master launch'],
            [[...$registration, 'master_epoch' => 21], 'another or stale Master launch'],
            [[...$registration, 'launch_id' => \str_repeat('f', 32)],
                'another or stale Master launch'],
            [[...$registration, 'project_generation' => 4], 'stale project generation'],
            [[...$registration, 'request_digest' => \str_repeat('d', 64)],
                'conflicting desired-state digests'],
            [[...$registration, 'non_certificate_desired_digest' => \str_repeat('e', 64)],
                'conflicting desired-state digests'],
        ];
        foreach ($rejected as [$candidate, $message]) {
            try {
                $store->publishFromRegistration($candidate);
                self::fail('A non-monotonic serving manifest publication was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
            self::assertSame($first['digest'], $store->current('primary')['digest']);
        }

        $nextLaunch = [
            ...$registration,
            'instance_generation' => 12,
            'master_pid' => 12346,
            'master_epoch' => 23,
            'launch_id' => \str_repeat('f', 32),
        ];
        $next = $store->publishFromRegistration($nextLaunch);
        self::assertSame(2, $next['generation']);
        self::assertSame(12, $next['payload']['instance_generation']);
        self::assertSame(\str_repeat('f', 32), $next['payload']['launch_id']);

        try {
            $store->publishFromRegistration($registration);
            self::fail('The retired launch reacquired current serving authority.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('stale instance generation', $exception->getMessage());
        }
        self::assertSame($next['digest'], $store->current('primary')['digest']);
    }

    public function testAuthorityFenceRejectsRetiredLaunchAndRepairsMissingPointer(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $firstRegistration = $this->registration('primary', [
            $this->route('authority.example.test'),
        ]);
        $store->publishFromRegistration($firstRegistration);
        $nextRegistration = [
            ...$firstRegistration,
            'instance_generation' => 12,
            'master_pid' => 12346,
            'master_epoch' => 23,
            'launch_id' => \str_repeat('f', 32),
        ];
        $next = $store->publishFromRegistration($nextRegistration);
        $pointer = $store->currentPointerPath('primary');
        self::assertTrue(\unlink($pointer));

        try {
            $store->publishFromRegistration($firstRegistration);
            self::fail('A retired launch must not reacquire a deleted current pointer.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'stale instance generation',
                $exception->getMessage(),
            );
        }
        self::assertFileDoesNotExist($pointer);

        $recovered = $store->publishFromRegistration($nextRegistration);
        self::assertSame($next['generation'], $recovered['generation']);
        self::assertSame($next['digest'], $recovered['digest']);
        self::assertSame($next['digest'], $store->current('primary')['digest']);
    }

    public function testApexTlsRemainsPublishedAsFixedUnavailableUntilWwwIsReady(): void
    {
        $apex = $this->route('pending-target.example.test', true);
        $target = $this->route('www.pending-target.example.test');
        $target['certificate'] = [
            'generation' => 0,
            'source_digest' => \hash(
                'sha256',
                "wls-pending-certificate\0www.pending-target.example.test",
            ),
            'state' => 'pending',
            'pending' => true,
            'trust_profile' => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            'provider' => 'none',
            'material_class' => 'none',
        ];
        $target['certificate']['provenance_digest']
            = ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                'www.pending-target.example.test',
                'pending',
                (string)$target['certificate']['source_digest'],
                0,
                ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            );
        $this->publishInactiveCertificateAuthority(
            'www.pending-target.example.test',
            'pending',
            0,
            (string)$target['certificate']['source_digest'],
        );

        $manifest = (new ProjectServingManifestStore($this->root))
            ->publishFromRegistration($this->registration('primary', [$apex, $target]));

        self::assertSame(1, $manifest['route_count']);
        self::assertFalse($manifest['converged']);
        $route = $manifest['payload']['routes'][0] ?? null;
        self::assertIsArray($route);
        self::assertSame('pending-target.example.test', $route['domain'] ?? null);
        self::assertTrue($route['policy']['force_root_to_www'] ?? false);
        self::assertFalse($route['policy']['root_to_www_target_ready'] ?? true);
    }

    public function testRuntimeObservationStaticContractBindsMonotonicToCurrentBoot(): void
    {
        $publisher = $this->source('Service/Edge/Gateway/GatewayRuntimeEndpointPublisher.php');
        $projection = $this->source('Service/Edge/Gateway/GatewayRuntimeServingProjection.php');

        self::assertStringContainsString("['runtime_observed_host_boot_id']", $publisher);
        self::assertStringContainsString('GatewayHostBootIdentity::current()', $publisher);
        self::assertStringContainsString("['host_boot_id']", $publisher);
        self::assertStringNotContainsString("\$observed !== ''", $publisher);
        self::assertStringContainsString("['runtime_observed_host_boot_id']", $projection);
        self::assertStringContainsString('GatewayHostBootIdentity::current()', $projection);
        self::assertStringContainsString(
            '!\\hash_equals($currentBootId, $observedBootId)',
            $projection,
        );
    }

    public function testCertificateCoordinatorStaticContractUsesLeaseAndTargetedReload(): void
    {
        $source = $this->source('Service/Edge/CertificateMaterialUpdateCoordinator.php');

        self::assertStringNotContainsString('Processer::processExists', $source);
        self::assertStringContainsString('validateRunningLease(', $source);
        self::assertStringContainsString("['authorized']", $source);
        self::assertStringContainsString(
            'reloadSslCertAndWait(',
            $source,
        );
        self::assertStringContainsString('$instanceName,', $source);
        self::assertStringContainsString('assertNativeReloadReceipt(', $source);
        self::assertStringNotContainsString('->reloadSslCert($domains);', $source);
        $legacyStart = \strpos($source, 'if ($explicitLegacy)');
        $legacyEnd = \strpos($source, '$rawMasterPid =', (int)$legacyStart);
        self::assertIsInt($legacyStart);
        self::assertIsInt($legacyEnd);
        $legacyBlock = \substr($source, $legacyStart, $legacyEnd - $legacyStart);
        self::assertStringContainsString('validateRunningLease(', $legacyBlock);
        self::assertStringContainsString("['authorized']", $legacyBlock);
        self::assertStringContainsString('continue;', $legacyBlock);
    }

    public function testFallbackManifestStaticContractPersistsGatewayRenewalIntent(): void
    {
        $source = $this->source('Service/Edge/Gateway/GatewayRegistrationBuilder.php');
        $start = \strpos($source, 'private function buildServingManifestLocked');
        $end = \strpos($source, 'private function buildLocked', (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = \substr($source, $start, $end - $start);

        self::assertStringContainsString("['requested_mode']", $method);
        self::assertStringContainsString('GatewayStartupDecision::MODE_AUTO', $method);
        self::assertStringContainsString('GatewayStartupDecision::MODE_GATEWAY', $method);
        self::assertStringContainsString('->enqueueFromRegistration(', $method);
        self::assertStringContainsString('$registration,', $method);
        self::assertStringContainsString('$deadlineMonotonic,', $method);
        self::assertStringNotContainsString('GatewayStartupDecision::MODE_WLS', $method);
    }

    public function testCandidateConstructionChecksPublicationDeadlineInsideRouteLoop(): void
    {
        $reflection = new \ReflectionMethod(
            ProjectServingManifestStore::class,
            'candidateFromRegistration',
        );
        $lines = \file($reflection->getFileName());
        self::assertIsArray($lines);
        $method = \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            '?float $deadlineMonotonic',
            $method,
        );
        $routeLoop = \strpos($method, 'foreach ($desiredRoutes as $route)');
        self::assertIsInt($routeLoop);
        $deadlineCheck = \strpos(
            $method,
            '$this->assertPublicationDeadline($deadlineMonotonic);',
            $routeLoop,
        );
        self::assertIsInt($deadlineCheck);
        self::assertLessThan(
            \strpos($method, 'if (!\\is_array($route))', $routeLoop),
            $deadlineCheck,
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count(
                \substr($method, $routeLoop),
                '$this->assertPublicationDeadline($deadlineMonotonic);',
            ),
        );
    }

    public function testTlsWorkerStaticContractRejectsUnparsedH2AndInvalidAuthorityPorts(): void
    {
        $source = $this->source('bin/worker_ssl.php');

        self::assertStringNotContainsString("'serving_manifest_misdirected'", $source);
        self::assertStringContainsString(
            'wlsServingManifestFramingErrorResponse($frame)',
            $source,
        );
        self::assertStringContainsString('$portNumber < 1 || $portNumber > 65535', $source);
        self::assertStringContainsString(
            'wlsServingManifestRedirectTargetUnavailableResponse()',
            $source,
        );
        self::assertStringNotContainsString(
            'SNI 解析失败或为空时，用当前监听主机再选证',
            $source,
        );
        self::assertStringContainsString(
            'Empty or unknown SNI must not receive a default tenant',
            $source,
        );
    }

    /** @return array<string,mixed> */
    private function registration(string $instanceId, array $routes): array
    {
        return [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'project_root' => $this->root,
            'certificate_trust_profile'
                => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            'instance_id' => $instanceId,
            'instance_generation' => 11,
            'master_pid' => 12345,
            'master_epoch' => 22,
            'launch_id' => \str_repeat('a', 32),
            'project_generation' => 5,
            'request_digest' => \str_repeat('b', 64),
            'non_certificate_desired_digest' => \str_repeat('c', 64),
            'routes' => $routes,
        ];
    }

    /** @return array<string,mixed> */
    private function route(
        string $domain,
        bool $redirect = false,
        ?string $snapshotDigest = null,
        int $certificateGeneration = 3,
    ): array
    {
        $snapshotDigest ??= $this->snapshotDigest;
        $cert = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $snapshotDigest
            . '/fullchain.pem';
        $key = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $snapshotDigest
            . '/privkey.pem';
        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        self::assertFileExists($cert);
        self::assertFileExists($key);
        $trustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_TEST;
        $provider = ProjectCertificateGenerationStore::PROVIDER_SELF_SIGNED;
        $materialClass = ProjectCertificateGenerationStore::MATERIAL_CLASS_SELF_SIGNED;
        $provenanceDigest = ProjectCertificateGenerationStore::provenanceDigest(
            $domain,
            $snapshotDigest,
            $trustProfile,
            $provider,
            $materialClass,
        );
        $this->publishActiveCertificateAuthority(
            $domain,
            $snapshotDigest,
            $certificateGeneration,
            $trustProfile,
            $provider,
            $materialClass,
            $provenanceDigest,
        );
        return [
            'route_id' => \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
            'domain' => $domain,
            'certificate' => [
                'cert' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => '.wls-generations/snapshots/'
                        . $snapshotDigest . '/fullchain.pem',
                ],
                'key' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => '.wls-generations/snapshots/'
                        . $snapshotDigest . '/privkey.pem',
                ],
                'source_digest' => $snapshotDigest,
                'trust_profile' => $trustProfile,
                'provider' => $provider,
                'material_class' => $materialClass,
                'provenance_digest' => $provenanceDigest,
                'leaf_fingerprint_sha256' => $this->snapshotLeafFingerprint(
                    $snapshotDigest,
                ),
                'generation' => $certificateGeneration,
                'pending' => false,
            ],
            'force_https' => true,
            'force_root_to_www' => $redirect,
            'root_to_www_target' => $redirect ? 'www.' . $domain : '',
        ];
    }

    /** @return array<string,mixed> */
    private function inactiveRoute(string $domain, string $state): array
    {
        if (!\in_array($state, ['pending', 'disabled'], true)) {
            throw new \InvalidArgumentException('Unsupported inactive route state.');
        }
        $generation = $state === 'disabled' ? 7 : 0;
        $route = $this->route($domain);
        $route['certificate'] = [
            'state' => $state,
            'pending' => true,
            'generation' => $generation,
            'source_digest' => $state === 'disabled'
                ? \hash(
                    'sha256',
                    "wls-disabled-certificate\0" . $domain . "\0" . $generation,
                )
                : \hash('sha256', "wls-pending-certificate\0" . $domain),
            'trust_profile' => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            'provider' => 'none',
            'material_class' => 'none',
        ];
        $route['certificate']['provenance_digest']
            = ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                $domain,
                $state,
                (string)$route['certificate']['source_digest'],
                $generation,
                ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            );
        $this->publishInactiveCertificateAuthority(
            $domain,
            $state,
            $generation,
            (string)$route['certificate']['source_digest'],
        );
        if ($state === 'disabled') {
            $route['force_https'] = false;
        }
        return $route;
    }

    private function createSnapshot(string $certificate, string $privateKey, string $chain): string
    {
        $fixtureSeed = \hash('sha256', $certificate . "\0" . $privateKey . "\0" . $chain);
        $config = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/snapshot-'
            . \substr($fixtureSeed, 0, 16) . '-' . \bin2hex(\random_bytes(4)) . '.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<'CONF'
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = example.test

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = example.test
DNS.2 = *.example.test
DNS.3 = www.pending-target.example.test
DNS.4 = www.redirect.example.test
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => 'example.test'], $key, $arguments);
        self::assertNotFalse($request);
        $signed = \openssl_csr_sign(
            $request,
            null,
            $key,
            30,
            $arguments,
            (int)\hexdec(\substr($fixtureSeed, 0, 7)),
        );
        self::assertNotFalse($signed);
        self::assertTrue(\openssl_x509_export($signed, $certificate, false));
        self::assertTrue(\openssl_pkey_export($key, $privateKey, null, $arguments));
        $certificate = \rtrim($certificate) . "\n";
        $chain = '';
        $certHash = \hash('sha256', $certificate);
        $keyHash = \hash('sha256', $privateKey);
        $chainHash = $chain === '' ? '' : \hash('sha256', $chain);
        $digest = \hash('sha256', $certHash . ':' . $keyHash . ':');
        $directory = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $digest;
        self::assertTrue(\mkdir($directory, 0700));
        self::assertNotFalse(\file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            $certificate,
        ));
        self::assertNotFalse(\file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'privkey.pem',
            $privateKey,
        ));
        if ($chain !== '') {
            self::assertNotFalse(\file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'chain.pem',
                $chain,
            ));
        }
        $payload = [
            'schema_version' => 1,
            'source_digest' => $digest,
            'leaf_fingerprint_sha256' => \strtolower(\str_replace(
                ':',
                '',
                (string)\openssl_x509_fingerprint($signed, 'sha256'),
            )),
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
            'created_at' => '2026-08-04T00:00:00+00:00',
        ];
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        self::assertNotFalse(\file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'snapshot.json',
            (string)\json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            foreach (['fullchain.pem', 'privkey.pem', 'chain.pem', 'snapshot.json'] as $file) {
                if (\is_file($directory . DIRECTORY_SEPARATOR . $file)) {
                    self::assertTrue(\chmod($directory . DIRECTORY_SEPARATOR . $file, 0600));
                }
            }
            self::assertTrue(\chmod($directory, 0700));
        }
        return $digest;
    }

    private function snapshotLeafFingerprint(string $snapshotDigest): string
    {
        $manifest = $this->snapshotManifestPayload($snapshotDigest);
        return (string)($manifest['leaf_fingerprint_sha256'] ?? '');
    }

    /** @return array<string,mixed> */
    private function snapshotManifestPayload(string $snapshotDigest): array
    {
        $path = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $snapshotDigest
            . '/snapshot.json';
        $envelope = \json_decode((string)\file_get_contents($path), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        return $envelope['payload'];
    }

    private function publishActiveCertificateAuthority(
        string $domain,
        string $snapshotDigest,
        int $generation,
        string $trustProfile,
        string $provider,
        string $materialClass,
        string $provenanceDigest,
    ): void {
        $snapshot = $this->snapshotManifestPayload($snapshotDigest);
        $snapshotRoot = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshots/' . $snapshotDigest;
        $activeRoot = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/active';
        if (!\is_dir($activeRoot)) {
            self::assertTrue(\mkdir($activeRoot, 0700, true));
        }
        $chainHash = (string)($snapshot['chain_sha256'] ?? '');
        $payload = [
            'schema_version' => ProjectCertificateGenerationStore::SCHEMA_VERSION,
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $snapshotDigest,
            'trust_profile' => $trustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => $provenanceDigest,
            'cert_path' => $snapshotRoot . DIRECTORY_SEPARATOR . 'fullchain.pem',
            'key_path' => $snapshotRoot . DIRECTORY_SEPARATOR . 'privkey.pem',
            'chain_path' => $chainHash === ''
                ? ''
                : $snapshotRoot . DIRECTORY_SEPARATOR . 'chain.pem',
            'leaf_fingerprint_sha256'
                => (string)($snapshot['leaf_fingerprint_sha256'] ?? ''),
            'cert_sha256' => (string)($snapshot['cert_sha256'] ?? ''),
            'key_sha256' => (string)($snapshot['key_sha256'] ?? ''),
            'chain_sha256' => $chainHash,
            'activated_at' => '2026-08-04T00:00:00+00:00',
            'previous' => null,
        ];
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        $path = $activeRoot . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $domain), 0, 32) . '.json';
        self::assertNotFalse(\file_put_contents(
            $path,
            \json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0600));
            self::assertTrue(\chmod($activeRoot, 0700));
        }
    }

    private function publishInactiveCertificateAuthority(
        string $domain,
        string $state,
        int $generation,
        string $sourceDigest,
    ): void {
        $selector = \substr(\hash('sha256', $domain), 0, 32) . '.json';
        $storeRoot = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations';
        $activePath = $storeRoot . DIRECTORY_SEPARATOR . 'active'
            . DIRECTORY_SEPARATOR . $selector;
        if (\is_file($activePath)) {
            self::assertTrue(\unlink($activePath));
        }
        $disabledRoot = $storeRoot . DIRECTORY_SEPARATOR . 'disabled';
        if (!\is_dir($disabledRoot)) {
            self::assertTrue(\mkdir($disabledRoot, 0700, true));
        }
        $disabledPath = $disabledRoot . DIRECTORY_SEPARATOR . $selector;
        if ($state === 'pending') {
            if (\is_file($disabledPath)) {
                self::assertTrue(\unlink($disabledPath));
            }
            return;
        }
        $payload = [
            'schema' => 'wls-project-certificate-disabled/1',
            'state' => 'disabled',
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'disabled_at' => '2026-08-04T00:00:00+00:00',
        ];
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        self::assertNotFalse(\file_put_contents(
            $disabledPath,
            \json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($disabledPath, 0600));
            self::assertTrue(\chmod($disabledRoot, 0700));
        }
    }

    /** @return array<string,mixed> */
    private function fence(string $instanceId): array
    {
        return [
            'instance_id' => $instanceId,
            'instance_generation' => 11,
            'master_pid' => 12345,
            'master_epoch' => 22,
            'launch_id' => \str_repeat('a', 32),
        ];
    }

    private function filesystemRoot(): string
    {
        $normalized = \str_replace('\\', '/', $this->root);
        if (\preg_match('/\A([A-Za-z]:)\//D', $normalized, $match) === 1) {
            return $match[1] . DIRECTORY_SEPARATOR;
        }
        if (\preg_match('#\A//([^/]+)/([^/]+)(?:/|\z)#D', $normalized, $match) === 1) {
            return DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
                . $match[1] . DIRECTORY_SEPARATOR . $match[2]
                . DIRECTORY_SEPARATOR;
        }
        return DIRECTORY_SEPARATOR;
    }

    private function source(string $relative): string
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/' . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = \file_get_contents($path);
        self::assertIsString($source, 'Missing static contract source: ' . $path);
        return $source;
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\file_exists($path)) {
            return;
        }
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        foreach ((array)@\scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
