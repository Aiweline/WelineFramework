<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

final class ProjectCertificateGenerationStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-cert-generation-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testActivationPublishesImmutableSnapshotAndAdvancesOnlyOnChange(): void
    {
        $domain = 'store.example.test';
        $firstSource = $this->createCertificate($domain, 'first');
        $store = new ProjectCertificateGenerationStore($this->root);

        $first = self::activateForTest($store,
            $domain,
            $firstSource['cert'],
            $firstSource['key'],
        );
        self::assertSame(1, $first['generation']);
        self::assertFalse($first['retained_previous']);
        self::assertSame('', $first['activation_error']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)$first['leaf_fingerprint_sha256'],
        );
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($first['key_path']);
        self::assertStringStartsWith(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/snapshots/',
            $first['cert_path'],
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($first['key_path']) & 0777);
        }

        $idempotent = self::activateForTest($store,
            $domain,
            $firstSource['cert'],
            $firstSource['key'],
        );
        self::assertSame(1, $idempotent['generation']);
        self::assertSame($first['source_digest'], $idempotent['source_digest']);
        self::assertSame(
            $first['leaf_fingerprint_sha256'],
            $idempotent['leaf_fingerprint_sha256'],
        );
        self::assertSame($first['cert_path'], $idempotent['cert_path']);

        $secondSource = $this->createCertificate($domain, 'second');
        $second = self::activateForTest($store,
            $domain,
            $secondSource['cert'],
            $secondSource['key'],
        );
        self::assertSame(2, $second['generation']);
        self::assertNotSame($first['source_digest'], $second['source_digest']);
        self::assertSame(1, $second['previous']['generation']);
        self::assertSame($first['source_digest'], $second['previous']['source_digest']);
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($second['cert_path']);
    }

    public function testLegacySelectorsRemainGcReferencesWhileSchemaTwoActivationMigrates(): void
    {
        $store = new ProjectCertificateGenerationStore($this->root);
        $legacyDomain = 'legacy-capacity.example.test';
        $legacySource = $this->createCertificate($legacyDomain, 'legacy-capacity');
        $legacy = self::activateForTest(
            $store,
            $legacyDomain,
            $legacySource['cert'],
            $legacySource['key'],
        );
        $selector = $this->selectorManifestPath('active', $legacyDomain);
        $envelope = \json_decode((string)\file_get_contents($selector), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['schema_version'] = 1;
        foreach (['trust_profile', 'provider', 'material_class', 'provenance_digest'] as $field) {
            unset($envelope['payload'][$field]);
        }
        $envelope['sha256'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $envelope['payload'],
            ),
        );
        self::assertNotFalse(\file_put_contents(
            $selector,
            \json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($selector, 0600));
        }

        try {
            $store->active($legacyDomain);
            self::fail('A schema-1 selector must never become serving authority.');
        } catch (\Weline\Server\Service\Edge\Gateway\CertificateTrustProvenanceException) {
            self::assertTrue(true);
        }

        $nextDomain = 'schema-two-after-legacy.example.test';
        $nextSource = $this->createCertificate($nextDomain, 'schema-two-after-legacy');
        $next = self::activateForTest(
            $store,
            $nextDomain,
            $nextSource['cert'],
            $nextSource['key'],
        );
        self::assertGreaterThan((int)$legacy['generation'], (int)$next['generation']);
        self::assertFileExists($legacy['cert_path']);

        $replacement = $this->createCertificate($legacyDomain, 'legacy-capacity-replacement');
        $migrated = self::activateForTest(
            $store,
            $legacyDomain,
            $replacement['cert'],
            $replacement['key'],
        );
        self::assertGreaterThan((int)$legacy['generation'], (int)$migrated['generation']);
        self::assertSame(
            ProjectCertificateGenerationStore::SCHEMA_VERSION,
            (int)(\json_decode(
                (string)\file_get_contents($selector),
                true,
            )['payload']['schema_version'] ?? 0),
        );
    }

    public function testActivationRejectsSourceReplacementDuringSnapshotPublication(): void
    {
        $domain = 'source-cas.example.test';
        $source = $this->createCertificate($domain, 'source-cas');
        $replacement = $this->createCertificate($domain, 'source-cas-replacement');
        $replaced = false;
        $store = new ProjectCertificateGenerationStore(
            $this->root,
            function () use (&$replaced, $source, $replacement): int {
                if (!$replaced) {
                    $replaced = true;
                    if (!\copy($replacement['cert'], $source['cert'])
                        || !\copy($replacement['key'], $source['key'])
                    ) {
                        throw new \RuntimeException(
                            'Unable to replace the certificate source fixture.',
                        );
                    }
                }
                return \time();
            },
        );

        try {
            self::activateForTest($store, $domain, $source['cert'], $source['key']);
            self::fail('A source replaced during snapshot publication was activated.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Certificate source changed while publishing its immutable snapshot.',
                $exception->getMessage(),
            );
        }
        self::assertTrue($replaced);
        self::assertNull($store->active($domain));
    }

    public function testFirstActivationReconcilesExactManifestCommittedBeforeDirectorySyncFailure(): void
    {
        $domain = 'first-commit-boundary.example.test';
        $source = $this->createCertificate($domain, 'first-commit-boundary');
        $store = new ProjectCertificateGenerationStore($this->root);

        $activated = $this->activateWithPostRenameSyncFailure(
            $store,
            $domain,
            $source,
        );

        self::assertSame(1, $activated['generation']);
        self::assertFalse($activated['retained_previous']);
        self::assertStringContainsString(
            'committed and reconciled',
            (string)$activated['activation_error'],
        );
        $current = $store->active($domain);
        self::assertIsArray($current);
        self::assertSame(1, $current['generation']);
        self::assertSame($activated['source_digest'], $current['source_digest']);
    }

    public function testRenewalDoesNotReportOldGenerationAfterCommittedSelectorSyncFailure(): void
    {
        $domain = 'renew-commit-boundary.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $first = $this->createCertificate($domain, 'renew-commit-boundary-first');
        self::activateForTest($store, $domain, $first['cert'], $first['key']);
        $second = $this->createCertificate($domain, 'renew-commit-boundary-second');

        $activated = $this->activateWithPostRenameSyncFailure(
            $store,
            $domain,
            $second,
        );

        self::assertSame(2, $activated['generation']);
        self::assertFalse($activated['retained_previous']);
        self::assertStringContainsString(
            'committed and reconciled',
            (string)$activated['activation_error'],
        );
        $current = $store->active($domain);
        self::assertIsArray($current);
        self::assertSame(2, $current['generation']);
        self::assertSame($activated['source_digest'], $current['source_digest']);
    }

    public function testAtomicStagingArtifactDoesNotPoisonActiveManifestEnumeration(): void
    {
        $this->assertAtomicArtifactDoesNotPoisonActiveManifest(
            '.tmp-' . \str_repeat('a', 24),
        );
    }

    public function testWindowsBackupArtifactDoesNotPoisonActiveManifestEnumeration(): void
    {
        $this->assertAtomicArtifactDoesNotPoisonActiveManifest(
            '.wls-backup-' . \str_repeat('b', 16),
        );
    }

    public function testAtomicStagingArtifactDoesNotPoisonDisabledManifestEnumeration(): void
    {
        $this->assertAtomicArtifactDoesNotPoisonDisabledManifest(
            '.tmp-' . \str_repeat('c', 24),
        );
    }

    public function testWindowsBackupArtifactDoesNotPoisonDisabledManifestEnumeration(): void
    {
        $this->assertAtomicArtifactDoesNotPoisonDisabledManifest(
            '.wls-backup-' . \str_repeat('d', 16),
        );
    }

    public function testValidatedActiveManifestReclaimsAccumulatedAtomicArtifacts(): void
    {
        $domain = 'active-artifact-recovery.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'active-artifact-recovery');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->selectorManifestPath('active', $domain);
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        $nextDomain = 'active-artifact-recovery-next.example.test';
        $next = $this->createCertificate($nextDomain, 'active-artifact-recovery-next');
        self::assertSame(
            2,
            self::activateForTest($store, $nextDomain, $next['cert'], $next['key'])['generation'],
        );

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testValidatedDisabledManifestReclaimsAccumulatedAtomicArtifacts(): void
    {
        $domain = 'disabled-artifact-recovery.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'disabled-artifact-recovery');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $target = $this->selectorManifestPath('disabled', $domain);
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        self::assertArrayHasKey($domain, $store->disabledCertificates());

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testRetirementPhaseUpdateReclaimsDisabledAtomicArtifactsBeforeWrite(): void
    {
        $domain = 'disabled-artifact-update.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'disabled-artifact-update');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $intent = $store->pendingRetirementIntents()[$domain] ?? null;
        self::assertIsArray($intent);
        $target = $this->selectorManifestPath('disabled', $domain);
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        self::assertTrue($store->completeRetirementIntent(
            $intent,
            $this->retirementProof($intent),
        ));

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testDisabledUpdateReclaimsArtifactsForOtherTargetsInTheSameDirectory(): void
    {
        $store = new ProjectCertificateGenerationStore($this->root);
        $firstDomain = 'disabled-artifact-directory-first.example.test';
        $secondDomain = 'disabled-artifact-directory-second.example.test';
        foreach ([$firstDomain, $secondDomain] as $index => $domain) {
            $source = $this->createCertificate(
                $domain,
                'disabled-artifact-directory-' . $index,
            );
            self::activateForTest($store, $domain, $source['cert'], $source['key']);
            $store->deactivate($domain);
        }
        $intents = $store->pendingRetirementIntents();
        $firstIntent = $intents[$firstDomain] ?? null;
        self::assertIsArray($firstIntent);
        $otherTarget = $this->selectorManifestPath('disabled', $secondDomain);
        $artifacts = $this->createAtomicRecoveryArtifacts($otherTarget, 12);

        self::assertTrue($store->completeRetirementIntent(
            $firstIntent,
            $this->retirementProof($firstIntent),
        ));

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testReenableIntentUpdateReclaimsValidatedAtomicArtifactsBeforeWrite(): void
    {
        $domain = 'reenable-artifact-update.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $first = $this->createCertificate($domain, 'reenable-artifact-first');
        $second = $this->createCertificate($domain, 'reenable-artifact-second');
        self::activateForTest($store, $domain, $first['cert'], $first['key']);
        $store->deactivate($domain);
        $issue = static function () use ($store, $domain, $second): array {
            return $store->withCertificateLifecycleLock(
                fn (): array => self::issueExplicitReenableIntentForTest($store,
                    $domain,
                    $second['cert'],
                    $second['key'],
                ),
            );
        };
        self::assertTrue($issue()['required']);
        $target = $this->selectorManifestPath('reenable-intents', $domain);
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        self::assertTrue($issue()['required']);

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testSnapshotRetirementUpdateDiscardsRebuildableAtomicArtifacts(): void
    {
        $store = new ProjectCertificateGenerationStore($this->root);
        $firstDomain = 'snapshot-retirement-artifact.example.test';
        $first = $this->createCertificate(
            $firstDomain,
            'snapshot-retirement-artifact-first',
        );
        self::activateForTest($store, $firstDomain, $first['cert'], $first['key']);
        $target = $this->snapshotRetirementStatePath();
        self::assertFileExists($target);
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        $nextDomain = 'snapshot-retirement-artifact-next.example.test';
        $next = $this->createCertificate(
            $nextDomain,
            'snapshot-retirement-artifact-next',
        );
        self::activateForTest($store, $nextDomain, $next['cert'], $next['key']);

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testRetirementReplayCursorUpdateDiscardsRebuildableAtomicArtifacts(): void
    {
        $domain = 'retirement-cursor-artifact.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'retirement-cursor-artifact');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $intent = $store->pendingRetirementIntents()[$domain] ?? null;
        self::assertIsArray($intent);
        $store->advanceRetirementReplayCursor($intent);
        $target = $this->retirementReplayCursorPath();
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        $store->advanceRetirementReplayCursor($intent);

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testMissingActiveTargetFailsClosedAndPreservesItsAtomicRecoveryArtifact(): void
    {
        $domain = 'active-artifact-missing-target.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'active-artifact-missing-target');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->selectorManifestPath('active', $domain);
        $artifact = $target . '.wls-backup-' . \str_repeat('e', 16);
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));
        self::assertTrue(\unlink($target));

        $nextDomain = 'active-artifact-missing-target-next.example.test';
        $next = $this->createCertificate(
            $nextDomain,
            'active-artifact-missing-target-next',
        );
        $failure = null;
        try {
            self::activateForTest($store, $nextDomain, $next['cert'], $next['key']);
        } catch (\RuntimeException $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('recovery', $failure->getMessage());
        self::assertFileExists(
            $artifact,
            'An artifact without a validated committed target may be the only recovery copy.',
        );
    }

    public function testMissingDisabledTargetFailsClosedAndPreservesItsAtomicRecoveryArtifact(): void
    {
        $domain = 'disabled-artifact-missing-target.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'disabled-artifact-missing-target');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $target = $this->selectorManifestPath('disabled', $domain);
        $artifact = $target . '.wls-backup-' . \str_repeat('1', 16);
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));
        self::assertTrue(\unlink($target));

        $failure = null;
        try {
            $store->disabledCertificates();
        } catch (\RuntimeException $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('recovery', $failure->getMessage());
        self::assertFileExists($artifact);
    }

    public function testCorruptActiveTargetPreservesItsAtomicRecoveryArtifact(): void
    {
        $domain = 'active-artifact-corrupt-target.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'active-artifact-corrupt-target');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->selectorManifestPath('active', $domain);
        $artifact = $target . '.wls-backup-' . \str_repeat('f', 16);
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));
        self::assertNotFalse(\file_put_contents($target, "{corrupt\n"));
        self::assertTrue(\chmod($target, 0600));

        $nextDomain = 'active-artifact-corrupt-target-next.example.test';
        $next = $this->createCertificate(
            $nextDomain,
            'active-artifact-corrupt-target-next',
        );
        try {
            self::activateForTest($store, $nextDomain, $next['cert'], $next['key']);
            self::fail('A corrupt committed selector target must fail closed.');
        } catch (\RuntimeException $throwable) {
            self::assertStringContainsString('integrity', $throwable->getMessage());
        }
        self::assertFileExists(
            $artifact,
            'Recovery evidence must survive failure to validate the committed target.',
        );
    }

    public function testValidatedGenerationFloorReclaimsAccumulatedAtomicArtifacts(): void
    {
        $store = new ProjectCertificateGenerationStore($this->root);
        $domain = 'generation-floor-artifact.example.test';
        $source = $this->createCertificate($domain, 'generation-floor-artifact');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->certificateGenerationFloorPath();
        $artifacts = $this->createAtomicRecoveryArtifacts($target, 12);

        $nextDomain = 'generation-floor-artifact-next.example.test';
        $next = $this->createCertificate($nextDomain, 'generation-floor-artifact-next');
        self::assertSame(
            2,
            self::activateForTest($store, $nextDomain, $next['cert'], $next['key'])['generation'],
        );

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testMissingGenerationFloorTargetFailsClosedAndPreservesRecoveryArtifact(): void
    {
        $store = new ProjectCertificateGenerationStore($this->root);
        $domain = 'generation-floor-missing-target.example.test';
        $source = $this->createCertificate($domain, 'generation-floor-missing-target');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->certificateGenerationFloorPath();
        $artifact = $target . '.wls-backup-' . \str_repeat('a', 16);
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));
        self::assertTrue(\unlink($target));

        $nextDomain = 'generation-floor-missing-target-next.example.test';
        $next = $this->createCertificate(
            $nextDomain,
            'generation-floor-missing-target-next',
        );
        $failure = null;
        try {
            self::activateForTest($store, $nextDomain, $next['cert'], $next['key']);
        } catch (\RuntimeException $throwable) {
            $failure = $throwable;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('recovery', $failure->getMessage());
        self::assertFileExists($artifact);
    }

    public function testInvalidRenewalRetainsPreviousValidGeneration(): void
    {
        $domain = 'retain.example.test';
        $valid = $this->createCertificate($domain, 'valid');
        $mismatch = $this->createCertificate($domain, 'mismatch');
        $store = new ProjectCertificateGenerationStore($this->root);
        $active = self::activateForTest($store, $domain, $valid['cert'], $valid['key']);

        $retained = self::activateForTest($store, $domain, $valid['cert'], $mismatch['key']);
        self::assertTrue($retained['retained_previous']);
        self::assertStringContainsString('do not match', $retained['activation_error']);
        self::assertSame($active['generation'], $retained['generation']);
        self::assertSame($active['source_digest'], $retained['source_digest']);
        self::assertSame($active['cert_path'], $retained['cert_path']);
        self::assertSame(
            $active['source_digest'],
            $store->active($domain)['source_digest'] ?? null,
        );
    }

    public function testDeactivationRemovesOnlyMutableSelector(): void
    {
        $domain = 'deactivate.example.test';
        $source = $this->createCertificate($domain, 'deactivate');
        $store = new ProjectCertificateGenerationStore($this->root);
        $active = self::activateForTest($store, $domain, $source['cert'], $source['key']);

        $store->deactivate($domain);

        self::assertNull($store->active($domain));
        self::assertFileExists((string)$active['cert_path']);
        self::assertFileExists((string)$active['key_path']);
    }

    public function testDeactivationPublishesBoundPendingRetirementInTheTombstoneCommit(): void
    {
        $domain = 'retirement-intent.example.test';
        $source = $this->createCertificate($domain, 'retirement-intent');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);

        $store->deactivate($domain);

        $disabled = $store->disabled($domain);
        self::assertIsArray($disabled);
        $intent = $disabled['retirement_intent'] ?? null;
        self::assertIsArray($intent);
        self::assertSame('pending', $intent['state'] ?? null);
        self::assertSame('runtime_pending', $intent['phase'] ?? null);
        self::assertSame('projection', $intent['operation'] ?? null);
        self::assertSame($domain, $intent['domain'] ?? null);
        self::assertSame($disabled['generation'], $intent['generation'] ?? null);
        self::assertSame($disabled['source_digest'], $intent['source_digest'] ?? null);
        self::assertSame([], $intent['phase_receipts'] ?? null);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)($intent['intent_id'] ?? ''),
        );
        self::assertSame([$domain => $intent], $store->pendingRetirementIntents());
    }

    public function testRetirementReceiptMapRejectsNonEmptyListForgery(): void
    {
        $domain = 'retirement-receipt-list.example.test';
        $source = $this->createCertificate($domain, 'retirement-receipt-list');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $disabled = $store->disabled($domain);
        self::assertIsArray($disabled);
        $intent = $disabled['retirement_intent'] ?? null;
        self::assertIsArray($intent);
        $intent['phase_receipts'] = [\str_repeat('a', 64)];

        $normalizer = new \ReflectionMethod($store, 'normalizeRetirementIntent');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('phase receipts are invalid');
        $normalizer->invoke(
            $store,
            $intent,
            $domain,
            (int)$disabled['generation'],
            (string)$disabled['source_digest'],
        );
    }

    public function testHistoricalTombstoneWithoutIntentIsNotReplayedAsANewRetirement(): void
    {
        $domain = 'historical-tombstone.example.test';
        $source = $this->createCertificate($domain, 'historical-tombstone');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $this->removeRetirementIntentFromTombstone($domain);

        $disabled = $store->disabled($domain);

        self::assertIsArray($disabled);
        self::assertArrayNotHasKey('retirement_intent', $disabled);
        self::assertSame([], $store->pendingRetirementIntents());
    }

    public function testExplicitLifecycleCreatesIntentAboveHistoricalTombstone(): void
    {
        $domain = 'historical-explicit-retirement.example.test';
        $source = $this->createCertificate($domain, 'historical-explicit-retirement');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $this->removeRetirementIntentFromTombstone($domain);
        $historical = $store->disabled($domain);
        self::assertIsArray($historical);

        // Ordinary projection scans preserve the compatibility boundary.
        $store->deactivate($domain);
        self::assertSame([], $store->pendingRetirementIntents());

        // A new explicit user transition is new authority and gets its own
        // atomic outbox entry rather than replaying the historical tombstone.
        $store->deactivate($domain, ensureRetirementIntent: true);
        $current = $store->disabled($domain);
        self::assertIsArray($current);
        self::assertGreaterThan($historical['generation'], $current['generation']);
        self::assertSame(
            'pending',
            $current['retirement_intent']['state'] ?? null,
        );
        self::assertArrayHasKey($domain, $store->pendingRetirementIntents());
    }

    public function testExactRuntimeProofKeepsLaterRetirementStagesReplayable(): void
    {
        $domain = 'retirement-proof.example.test';
        $source = $this->createCertificate($domain, 'retirement-proof');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $intent = $store->pendingRetirementIntents()[$domain] ?? null;
        self::assertIsArray($intent);
        $proof = $this->retirementProof($intent);

        self::assertTrue($store->completeRetirementIntent($intent, $proof));
        self::assertTrue($store->completeRetirementIntent($intent, $proof));
        $runtimeRetired = $store->pendingRetirementIntents()[$domain] ?? null;
        self::assertIsArray($runtimeRetired);
        self::assertSame(
            'pending',
            $store->disabled($domain)['retirement_intent']['state'] ?? null,
        );
        self::assertSame('runtime_retired', $runtimeRetired['phase'] ?? null);

        $current = $runtimeRetired;
        foreach ([
            ['runtime_retired', 'legacy_retired'],
            ['legacy_retired', 'endpoint_retired'],
            ['endpoint_retired', 'source_retired'],
            ['source_retired', 'database_retired'],
            ['database_retired', 'event_dispatched'],
        ] as [$from, $to]) {
            $current = $store->advanceRetirementPhase(
                $current,
                $from,
                $to,
                \hash('sha256', $domain . ':' . $to),
            );
            self::assertIsArray($current);
        }
        self::assertTrue($store->finishRetirementIntent($current));
        self::assertSame([], $store->pendingRetirementIntents());
        self::assertSame(
            'completed',
            $store->disabled($domain)['retirement_intent']['state'] ?? null,
        );
        self::assertSame(
            'complete',
            $store->disabled($domain)['retirement_intent']['phase'] ?? null,
        );
    }

    public function testExplicitRetirementPrepareIsDurableBeforeBusinessCommit(): void
    {
        $domain = 'prepared-retirement.example.test';
        $source = $this->createCertificate($domain, 'prepared-retirement');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $rowDigest = \hash('sha256', 'pgsql-row-17');

        $intent = $store->prepareCertificateRetirement(
            $domain,
            ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE,
            17,
            'unit retirement',
            $rowDigest,
        );

        self::assertNull($store->active($domain));
        self::assertSame('pending', $intent['state'] ?? null);
        self::assertSame('prepared', $intent['phase'] ?? null);
        self::assertSame('delete', $intent['operation'] ?? null);
        self::assertSame(17, $intent['certificate_id'] ?? null);
        self::assertSame($rowDigest, $intent['expected_row_digest'] ?? null);
        self::assertSame(
            $intent['intent_id'],
            $store->pendingRetirementIntents()[$domain]['intent_id'] ?? null,
        );
    }

    public function testExplicitRetirementMustFinishItsEventBeforeReenable(): void
    {
        $domain = 'retirement-event-order.example.test';
        $source = $this->createCertificate($domain, 'retirement-event-order');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->prepareCertificateRetirement(
            $domain,
            ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DISABLE,
            31,
            'ordered event',
            \hash('sha256', 'pgsql-row-31'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('generation-bound event');
        $store->withCertificateLifecycleLock(
            static fn (): array => self::issueExplicitReenableIntentForTest($store,
                $domain,
                $source['cert'],
                $source['key'],
            ),
        );
    }

    public function testExplicitReenableRequiresTheCurrentLifecycleAuthority(): void
    {
        $domain = 'reenable-lock.example.test';
        $source = $this->createCertificate($domain, 'reenable-lock');
        $store = new ProjectCertificateGenerationStore($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current lifecycle lock');
        self::issueExplicitReenableIntentForTest($store,
            $domain,
            $source['cert'],
            $source['key'],
        );
    }

    public function testSnapshotGcGraceStartsAtReferenceRetirementNotSnapshotMtime(): void
    {
        $wall = 1_800_000_000;
        $monotonic = 10_000.0;
        $boot = \str_repeat('a', 64);
        $store = $this->generationStoreWithClock($wall, $monotonic, $boot);
        $digest = \str_repeat('1', 64);
        $inventory = $this->snapshotRetirementInventory($digest, 1);

        $store->transitionCertificateSnapshotReferences([$digest]);
        $store->transitionCertificateSnapshotReferences([], [$digest]);

        $wall += 604_799;
        $monotonic += 604_799.0;
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
        $wall++;
        $monotonic += 1.0;
        self::assertSame(
            [$digest],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
    }

    public function testSnapshotRetirementCommitIsNotReversedWhenDeadlineCrossesDuringWrite(): void
    {
        $wall = 1_805_000_000;
        $monotonicReads = [100.0, 100.0, 100.0, 101.0];
        $store = new ProjectCertificateGenerationStore(
            $this->root,
            static fn (): int => $wall,
            static function () use (&$monotonicReads): float {
                return \array_shift($monotonicReads) ?? 101.0;
            },
            static fn (): string => \str_repeat('9', 64),
        );
        $digest = \str_repeat('8', 64);

        $store->transitionCertificateSnapshotReferences(
            [],
            [$digest],
            100.5,
        );

        $envelope = \json_decode(
            (string)\file_get_contents($this->snapshotRetirementStateFile()),
            true,
        );
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        self::assertArrayHasKey($digest, $envelope['payload']['markers'] ?? []);
        self::assertSame(101.0, $envelope['payload']['updated_monotonic'] ?? null);
    }

    public function testRetirementFactCommitIsNotReversedWhenDeadlineCrossesDuringWrite(): void
    {
        $wall = 1_806_000_000;
        $monotonic = 200.0;
        $boot = \str_repeat('7', 64);
        $store = $this->generationStoreWithClock($wall, $monotonic, $boot);
        $directory = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations';
        self::assertTrue(\mkdir($directory, 0700, true));
        $fact = $directory . DIRECTORY_SEPARATOR . 'commit-boundary.fact';
        $lock = $directory . DIRECTORY_SEPARATOR . 'commit-boundary.lock';
        $method = new \ReflectionMethod($store, 'withRetirementStateLock');

        $result = $method->invoke(
            $store,
            $lock,
            static function () use (&$monotonic, $fact): string {
                GatewayProjectStateFilesystem::atomicWrite(
                    $fact,
                    "committed\n",
                    0600,
                );
                $monotonic = 201.0;
                return 'committed';
            },
            200.5,
        );

        self::assertSame('committed', $result);
        self::assertSame("committed\n", \file_get_contents($fact));
    }

    public function testSnapshotReferenceRecoveryAndHostRebootRestartTheFullGrace(): void
    {
        $wall = 1_810_000_000;
        $monotonic = 20_000.0;
        $boot = \str_repeat('b', 64);
        $store = $this->generationStoreWithClock($wall, $monotonic, $boot);
        $digest = \str_repeat('2', 64);
        $inventory = $this->snapshotRetirementInventory($digest, 1);

        $store->transitionCertificateSnapshotReferences([], [$digest]);
        $wall += 604_900;
        $monotonic += 604_900.0;
        $store->transitionCertificateSnapshotReferences([$digest]);
        $store->transitionCertificateSnapshotReferences([], [$digest]);
        $wall += 604_799;
        $monotonic += 604_799.0;
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );

        $wall += 2;
        $monotonic += 2.0;
        $boot = \str_repeat('c', 64);
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
        $wall += 604_800;
        $monotonic += 604_800.0;
        self::assertSame(
            [$digest],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
    }

    public function testMissingCorruptAndFutureSnapshotRetirementStateCannotDeleteEarly(): void
    {
        $wall = 1_820_000_000;
        $monotonic = 30_000.0;
        $boot = \str_repeat('d', 64);
        $store = $this->generationStoreWithClock($wall, $monotonic, $boot);
        $digest = \str_repeat('3', 64);
        $inventory = $this->snapshotRetirementInventory($digest, 1);

        // Ancient directory metadata is deliberately irrelevant when the
        // durable unreferenced marker is missing.
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
        $stateFile = $this->snapshotRetirementStateFile();
        $envelope = \json_decode((string)\file_get_contents($stateFile), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['markers'][$digest]['unreferenced_since_unix']
            = $wall + 86_400;
        $envelope['payload']['markers'][$digest]['unreferenced_since_monotonic']
            = $monotonic + 86_400.0;
        $envelope['sha256'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $envelope['payload'],
            ),
        );
        self::assertNotFalse(\file_put_contents(
            $stateFile,
            \json_encode($envelope, JSON_THROW_ON_ERROR),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($stateFile, 0600));
        }
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );

        self::assertNotFalse(\file_put_contents($stateFile, '{corrupt'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($stateFile, 0600));
        }
        $wall += 604_801;
        $monotonic += 604_801.0;
        self::assertSame(
            [],
            $this->collectableSnapshotRetirementDigests($store, $inventory),
        );
    }

    public function testStaticSnapshotRetirementContractUsesBootBoundMonotonicMarkers(): void
    {
        $generationSource = \file_get_contents(
            \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
                . 'app/code/Weline/Server/Service/Edge/Gateway/'
                . 'ProjectCertificateGenerationStore.php',
        );
        $servingSource = \file_get_contents(
            \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
                . 'app/code/Weline/Server/Service/Edge/Gateway/'
                . 'ProjectServingManifestStore.php',
        );
        self::assertIsString($generationSource);
        self::assertIsString($servingSource);
        self::assertStringContainsString(
            'transitionCertificateSnapshotReferences(',
            $generationSource,
        );
        self::assertStringContainsString(
            'collectableSnapshotRetirementDigests(',
            $generationSource,
        );
        self::assertStringContainsString('GatewayHostBootIdentity::current()', $generationSource);
        self::assertStringContainsString('unreferenced_since_monotonic', $generationSource);
        self::assertStringContainsString('snapshotRetirementMarkersForClock(', $generationSource);
        self::assertStringNotContainsString(
            "(int)\$entry['mtime'] <= \$cutoff",
            $generationSource,
        );
        $publishLocked = \strpos(
            $servingSource,
            'private function publishLocked(',
        );
        self::assertIsInt($publishLocked);
        $transition = \strpos(
            $servingSource,
            '->transitionCertificateSnapshotReferences(',
            $publishLocked,
        );
        self::assertIsInt($transition);
        $lockedRevalidation = \strpos(
            $servingSource,
            '$this->assertPayload($payload, true);',
            $publishLocked,
        );
        $authority = \strpos(
            $servingSource,
            '$authority = $this->readPublicationAuthority(',
            $transition,
        );
        self::assertIsInt($lockedRevalidation);
        self::assertIsInt($authority);
        self::assertLessThan($transition, $lockedRevalidation);
        self::assertLessThan($authority, $transition);
        self::assertStringContainsString(
            'synchronizeCertificateSnapshotRetirementReferences(',
            $servingSource,
        );
    }

    public function testLifecycleReentrancyDoesNotAuthorizeAnotherFiber(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('Fibers are unavailable in this PHP runtime.');
        }
        $store = new ProjectCertificateGenerationStore($this->root);
        $fiber = new \Fiber(static function () use ($store): string {
            return $store->withCertificateLifecycleLock(static function (): string {
                \Fiber::suspend('locked');
                return 'released';
            });
        });
        self::assertSame('locked', $fiber->start());

        try {
            $store->withCertificateLifecycleLock(static fn (): string => 'unsafe');
            self::fail('Another Fiber must not inherit the current lifecycle lock.');
        } catch (\RuntimeException $throwable) {
            self::assertStringContainsString(
                'another execution context',
                $throwable->getMessage(),
            );
        }

        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertSame('released', $fiber->getReturn());
    }

    public function testRetirementStateLocksShareTheCallersAbsoluteDeadline(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/Gateway/'
            . 'ProjectCertificateGenerationStore.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $methodSource = static function (string $methodName) use ($lines): string {
            $method = new \ReflectionMethod(
                ProjectCertificateGenerationStore::class,
                $methodName,
            );
            return \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };

        $stateLock = $methodSource('withRetirementStateLock');
        self::assertStringContainsString(
            '$this->retirementDeadlineRemaining($deadlineMonotonic)',
            $stateLock,
        );
        self::assertStringContainsString(
            '$waitTimeoutSeconds = $this->retirementLockWaitTimeout(',
            $stateLock,
        );
        self::assertStringContainsString(
            'waitTimeoutSeconds: $waitTimeoutSeconds',
            $stateLock,
        );
        self::assertStringContainsString(
            'deadlineMonotonic: $this->lockAcquisitionDeadline(',
            $stateLock,
        );
        $replayLease = $methodSource('withRetirementReplayLease');
        self::assertStringContainsString(
            '?float $deadlineMonotonic = null',
            $replayLease,
        );
        self::assertStringContainsString(
            '$this->retirementDeadlineRemaining($deadlineMonotonic)',
            $replayLease,
        );
        self::assertStringContainsString(
            '$waitTimeoutSeconds = $this->retirementLockWaitTimeout(',
            $replayLease,
        );
        self::assertStringContainsString(
            'waitTimeoutSeconds: $waitTimeoutSeconds',
            $replayLease,
        );
        self::assertStringContainsString(
            'deadlineMonotonic: $this->lockAcquisitionDeadline(',
            $replayLease,
        );
        foreach ([
            'active',
            'disabled',
            'issueExplicitReenableIntent',
            'prepareCertificateRetirement',
            'retirementIntent',
            'completeRetirementIntent',
            'advanceRetirementPhase',
            'finishRetirementIntent',
            'deactivate',
        ] as $methodName) {
            self::assertStringContainsString(
                '$deadlineMonotonic',
                $methodSource($methodName),
                $methodName . ' must preserve the retirement deadline.',
            );
        }
    }

    public function testMismatchedRetirementProofCannotCompletePendingIntent(): void
    {
        $domain = 'retirement-proof-mismatch.example.test';
        $source = $this->createCertificate($domain, 'retirement-proof-mismatch');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $intent = $store->pendingRetirementIntents()[$domain] ?? null;
        self::assertIsArray($intent);
        $proof = $this->retirementProof($intent);
        $proof['source_digest'] = \str_repeat('f', 64);

        try {
            $store->completeRetirementIntent($intent, $proof);
            self::fail('A mismatched retirement proof must be rejected.');
        } catch (\RuntimeException $throwable) {
            self::assertStringContainsString('proof', $throwable->getMessage());
        }
        self::assertArrayHasKey($domain, $store->pendingRetirementIntents());
    }

    public function testHigherActiveGenerationSupersedesPendingRetirementBeforeReplay(): void
    {
        $domain = 'retirement-superseded.example.test';
        $firstSource = $this->createCertificate($domain, 'retirement-superseded-first');
        $secondSource = $this->createCertificate($domain, 'retirement-superseded-second');
        $store = new ProjectCertificateGenerationStore($this->root);
        self::activateForTest($store, $domain, $firstSource['cert'], $firstSource['key']);
        $store->deactivate($domain);

        $second = $store->withCertificateLifecycleLock(
            function () use ($store, $domain, $secondSource): array {
                self::issueExplicitReenableIntentForTest($store,
                    $domain,
                    $secondSource['cert'],
                    $secondSource['key'],
                );
                return self::activateForTest($store,
                    $domain,
                    $secondSource['cert'],
                    $secondSource['key'],
                );
            },
        );

        self::assertSame([], $store->pendingRetirementIntents());
        $retirement = $store->disabled($domain)['retirement_intent'] ?? null;
        self::assertIsArray($retirement);
        self::assertSame('superseded', $retirement['state'] ?? null);
        self::assertSame($second['generation'], $retirement['superseded_by_generation'] ?? null);
        self::assertSame($second['source_digest'], $retirement['superseded_by_source_digest'] ?? null);
    }

    public function testWildcardRouteAcceptsOnlyTheExactWildcardSan(): void
    {
        $domain = '*.example.test';
        $source = $this->createCertificate($domain, 'wildcard');
        $store = new ProjectCertificateGenerationStore($this->root);

        $active = self::activateForTest($store, $domain, $source['cert'], $source['key']);

        self::assertSame($domain, $active['domain']);
        self::assertSame(1, $active['generation']);
        self::assertFalse($active['retained_previous']);
    }

    public function testWildcardSanCannotClaimADifferentWildcardRoute(): void
    {
        $source = $this->createCertificate('*.example.test', 'wildcard-mismatch');
        $store = new ProjectCertificateGenerationStore($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Certificate SAN does not cover *.sub.example.test');
        self::activateForTest($store,
            '*.sub.example.test',
            $source['cert'],
            $source['key'],
        );
    }

    public function testSymlinkSourceCannotCreateFirstGeneration(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link fixture requires POSIX symlink support.');
        }
        $domain = 'symlink.example.test';
        $source = $this->createCertificate($domain, 'source');
        $linked = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/linked.pem';
        self::assertTrue(\symlink($source['cert'], $linked));
        $store = new ProjectCertificateGenerationStore($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No valid active certificate generation');
        self::activateForTest($store, $domain, $linked, $source['key']);
    }

    public function testAccessibleCertificateOutsideProjectRequiresEnrollment(): void
    {
        $domain = 'outside.example.test';
        $source = $this->createCertificate($domain, 'outside-source');
        $outside = $this->root . '-outside';
        self::assertTrue(\mkdir($outside, 0700, true));
        $certificate = $outside . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $outside . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));

        try {
            $store = new ProjectCertificateGenerationStore($this->root);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(
                'Certificate source is outside every enrolled certificate root',
            );
            self::activateForTest($store, $domain, $certificate, $privateKey);
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testExplicitEnrollmentAllowsAdditionalProjectCertificateRoot(): void
    {
        $domain = 'enrolled.example.test';
        $source = $this->createCertificate($domain, 'enrolled-source');
        $additional = $this->root . DIRECTORY_SEPARATOR . 'secrets/tls';
        self::assertTrue(\mkdir($additional, 0700, true));
        $certificate = $additional . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $additional . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));

        $active = self::activateForTest(
            new ProjectCertificateGenerationStore($this->root),
            $domain,
            $certificate,
            $privateKey,
            '',
            ['additional' => $additional],
        );

        self::assertSame(1, $active['generation']);
        self::assertFalse($active['retained_previous']);
    }

    public function testExplicitEnrollmentRejectsCertificateRootOutsideProject(): void
    {
        $domain = 'external-enrollment.example.test';
        $source = $this->createCertificate($domain, 'external-enrollment-source');
        $outside = $this->root . '-external-enrollment';
        self::assertTrue(\mkdir($outside, 0700, true));
        $certificate = $outside . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $outside . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(
                'Enrolled certificate source roots must stay inside the project root',
            );
            self::activateForTest(
                new ProjectCertificateGenerationStore($this->root),
                $domain,
                $certificate,
                $privateKey,
                '',
                ['external' => $outside],
            );
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testCertificateGenerationRejectsFilesystemRootEnrollment(): void
    {
        $domain = 'root-enrollment.example.test';
        $source = $this->createCertificate($domain, 'root-enrollment');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Enrolled certificate source root must be a canonical directory',
        );
        self::activateForTest(
            new ProjectCertificateGenerationStore($this->root),
            $domain,
            $source['cert'],
            $source['key'],
            '',
            ['filesystem_root' => $this->filesystemRoot()],
        );
    }

    public function testCertificateGenerationStoreRejectsFilesystemProjectRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve a safe WLS project root');
        new ProjectCertificateGenerationStore($this->filesystemRoot());
    }

    public function testExtendedWindowsFilesystemRootsAreRejectedWithoutRejectingChildren(): void
    {
        $paths = [
            '//',
            '///',
            'C:\\',
            'C:/',
            '\\\\server\\',
            '\\\\server\\share\\',
            '//server/share/',
            '\\\\?\\C:\\',
            '//?/C:/',
            '\\\\?\\UNC\\server\\share\\',
            '\\\\?\\UNC\\server\\',
            '//?/UNC/server/share/',
            '\\\\.\\C:\\',
            '\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\',
        ];
        foreach ([
            new ProjectCertificateGenerationStore($this->root),
            new GatewayRegistrationBuilder(),
        ] as $subject) {
            $method = new \ReflectionMethod($subject, 'isFilesystemRoot');
            foreach ($paths as $path) {
                self::assertTrue($method->invoke($subject, $path), $path);
            }
            foreach ([
                'C:\\project',
                '\\\\server\\share\\project',
                '\\\\?\\C:\\project',
                '\\\\?\\UNC\\server\\share\\project',
            ] as $path) {
                self::assertFalse($method->invoke($subject, $path), $path);
            }
        }
    }

    public function testSnapshotGarbageCollectionRetainsCurrentPreviousAndNewlyRetiredGeneration(): void
    {
        $wall = 1_830_000_000;
        $monotonic = 40_000.0;
        $boot = \str_repeat('e', 64);
        $domain = 'gc.example.test';
        $store = $this->generationStoreWithClock($wall, $monotonic, $boot);
        $firstSource = $this->createCertificate($domain, 'gc-first');
        $first = self::activateForTest($store, $domain, $firstSource['cert'], $firstSource['key']);
        $secondSource = $this->createCertificate($domain, 'gc-second');
        $second = self::activateForTest($store, $domain, $secondSource['cert'], $secondSource['key']);
        $thirdSource = $this->createCertificate($domain, 'gc-third');
        $third = self::activateForTest($store, $domain, $thirdSource['cert'], $thirdSource['key']);
        $firstDirectory = \dirname((string)$first['cert_path']);
        self::assertTrue(\touch($firstDirectory, \time() - 604_801));

        (new \ReflectionMethod($store, 'assertSnapshotStoreCapacity'))->invoke($store, 1);

        self::assertDirectoryExists($firstDirectory);
        $wall += 604_800;
        $monotonic += 604_800.0;
        (new \ReflectionMethod($store, 'assertSnapshotStoreCapacity'))->invoke($store, 1);

        self::assertDirectoryDoesNotExist($firstDirectory);
        self::assertDirectoryExists(\dirname((string)$second['cert_path']));
        self::assertDirectoryExists(\dirname((string)$third['cert_path']));
    }

    public function testDeactivationDoesNotAllowCertificateGenerationReuse(): void
    {
        $domain = 'reactivated.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $firstSource = $this->createCertificate($domain, 'reactivated-first');
        $first = self::activateForTest($store, $domain, $firstSource['cert'], $firstSource['key']);

        $store->deactivate($domain);
        self::assertNull($store->active($domain));

        $secondSource = $this->createCertificate($domain, 'reactivated-second');
        $second = $store->withCertificateLifecycleLock(
            function () use ($store, $domain, $secondSource): array {
                self::issueExplicitReenableIntentForTest($store,
                    $domain,
                    $secondSource['cert'],
                    $secondSource['key'],
                );
                return self::activateForTest($store,
                    $domain,
                    $secondSource['cert'],
                    $secondSource['key'],
                );
            },
        );
        self::assertGreaterThan((int)$first['generation'], (int)$second['generation']);
    }

    public function testSnapshotGarbageCollectionFailsClosedOnCorruptReference(): void
    {
        $domain = 'gc-corrupt.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $snapshots = [];
        foreach (['first', 'second', 'third'] as $name) {
            $source = $this->createCertificate($domain, 'gc-corrupt-' . $name);
            $snapshots[] = self::activateForTest($store, $domain, $source['cert'], $source['key']);
        }
        $orphan = \dirname((string)$snapshots[0]['cert_path']);
        self::assertTrue(\touch($orphan, \time() - 604_801));
        $activeRoot = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/active';
        $corrupt = $activeRoot . DIRECTORY_SEPARATOR . \str_repeat('f', 32) . '.json';
        self::assertNotFalse(\file_put_contents($corrupt, '{}'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($corrupt, 0600));
        }

        try {
            (new \ReflectionMethod($store, 'assertSnapshotStoreCapacity'))->invoke($store, 1);
            self::fail('Corrupt reference state must stop snapshot GC.');
        } catch (\RuntimeException) {
            self::assertDirectoryExists($orphan);
        }
    }

    public function testGatewayEnrollmentRejectsFilesystemRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Certificate roots must be canonical, unlinked directories',
        );
        (new GatewayRegistrationBuilder())->enrollmentCertificateRoots(
            $this->root,
            ['filesystem_root' => $this->filesystemRoot()],
        );
    }

    public function testGatewayEnrollmentRejectsFilesystemProjectRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Project root for certificate enrollment is unsafe');
        (new GatewayRegistrationBuilder())->enrollmentCertificateRoots(
            $this->filesystemRoot(),
        );
    }

    public function testGatewayEnrollmentRejectsCertificateRootOutsideProject(): void
    {
        $outside = $this->root . '-outside-enrollment';
        self::assertTrue(\mkdir($outside, 0700, true));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(
                'Gateway certificate roots must stay inside the project root',
            );
            (new GatewayRegistrationBuilder())->enrollmentCertificateRoots(
                $this->root,
                ['external' => $outside],
            );
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testAdditionalProjectEnrollmentRejectsWritableRoot(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission fixture.');
        }
        $domain = 'writable-root.example.test';
        $source = $this->createCertificate($domain, 'writable-root-source');
        $additional = $this->root . DIRECTORY_SEPARATOR . 'writable-root';
        self::assertTrue(\mkdir($additional, 0700, true));
        $certificate = $additional . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $additional . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));
        self::assertTrue(\chmod($additional, 0770));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('group/world-writable directory');
            self::activateForTest(
                new ProjectCertificateGenerationStore($this->root),
                $domain,
                $certificate,
                $privateKey,
                '',
                ['additional' => $additional],
            );
        } finally {
            @\chmod($additional, 0700);
        }
    }

    public function testAdditionalProjectEnrollmentRejectsWritableDescendant(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission fixture.');
        }
        $domain = 'writable-child.example.test';
        $source = $this->createCertificate($domain, 'writable-child-source');
        $additional = $this->root . DIRECTORY_SEPARATOR . 'writable-child';
        $material = $additional . DIRECTORY_SEPARATOR . 'tenant';
        self::assertTrue(\mkdir($material, 0700, true));
        $certificate = $material . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $material . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));
        self::assertTrue(\chmod($material, 0770));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('group/world-writable directory');
            self::activateForTest(
                new ProjectCertificateGenerationStore($this->root),
                $domain,
                $certificate,
                $privateKey,
                '',
                ['additional' => $additional],
            );
        } finally {
            @\chmod($material, 0700);
        }
    }

    public function testCopiedProjectRelocatesActiveSnapshotInsideItsCurrentRoot(): void
    {
        $domain = 'migrated.example.test';
        $source = $this->createCertificate($domain, 'migrated-source');
        $original = new ProjectCertificateGenerationStore($this->root);
        $activated = self::activateForTest($original, $domain, $source['cert'], $source['key']);

        $migratedRoot = $this->root . '-migrated';
        self::assertTrue(\mkdir($migratedRoot . DIRECTORY_SEPARATOR . 'app/etc', 0700, true));
        $this->copyTree(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl',
        );
        try {
            $migrated = new ProjectCertificateGenerationStore($migratedRoot);
            $active = $migrated->active($domain);

            self::assertIsArray($active);
            self::assertSame($activated['source_digest'], $active['source_digest']);
            self::assertStringStartsWith(
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/.wls-generations/snapshots/',
                $active['cert_path'],
            );
            self::assertStringNotContainsString($this->root . DIRECTORY_SEPARATOR, $active['cert_path']);

            $idempotent = self::activateForTest($migrated,
                $domain,
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/migrated-source/fullchain.pem',
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/migrated-source/privkey.pem',
            );
            self::assertSame($activated['generation'], $idempotent['generation']);
            self::assertSame($active['cert_path'], $idempotent['cert_path']);
        } finally {
            $this->removeTree($migratedRoot);
        }
    }

    public function testCopiedProjectDoesNotFollowSnapshotDirectorySymlink(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link fixture requires POSIX symlink support.');
        }
        $domain = 'migrated-symlink.example.test';
        $source = $this->createCertificate($domain, 'migrated-symlink-source');
        $original = new ProjectCertificateGenerationStore($this->root);
        $activated = self::activateForTest($original, $domain, $source['cert'], $source['key']);

        $migratedRoot = $this->root . '-symlink-migrated';
        self::assertTrue(\mkdir(
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/active',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/snapshots',
            0700,
            true,
        ));
        $manifest = \substr(\hash('sha256', $domain), 0, 32) . '.json';
        self::assertTrue(\copy(
            $this->root . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/active/' . $manifest,
            $migratedRoot . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/active/' . $manifest,
        ));
        self::assertTrue(\symlink(
            \dirname($activated['cert_path']),
            $migratedRoot . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/snapshots/' . $activated['source_digest'],
        ));

        try {
            $migrated = new ProjectCertificateGenerationStore($migratedRoot);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Symbolic-link or non-canonical certificate paths');
            $migrated->active($domain);
        } finally {
            $this->removeTree($migratedRoot);
        }
    }

    public function testRootActivationPreservesProjectOwnerOnGeneratedArtifacts(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
            || !\function_exists('posix_getpwnam')
        ) {
            self::markTestSkipped('Root POSIX ownership regression test.');
        }
        $account = \posix_getpwnam('nobody');
        if (!\is_array($account)
            || (int)($account['uid'] ?? 0) < 1
            || (int)($account['gid'] ?? 0) < 1
        ) {
            self::markTestSkipped('A non-root nobody account is required.');
        }
        $domain = 'root-owner.example.test';
        $source = $this->createCertificate($domain, 'root-owner');
        $uid = (int)$account['uid'];
        $gid = (int)$account['gid'];
        $this->changeOwnership($this->root, $uid, $gid);

        $active = self::activateForTest(
            new ProjectCertificateGenerationStore($this->root),
            $domain,
            $source['cert'],
            $source['key'],
        );

        foreach ([
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations',
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/activation.lock',
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/generation-floor.txt',
            $active['cert_path'],
            $active['key_path'],
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/active/'
                . \substr(\hash('sha256', $domain), 0, 32) . '.json',
        ] as $path) {
            $owner = \lstat($path);
            self::assertIsArray($owner);
            self::assertSame($uid, (int)$owner['uid'], $path);
            self::assertSame($gid, (int)$owner['gid'], $path);
        }
    }

    /** @return array<string,mixed> */
    private static function activateForTest(
        ProjectCertificateGenerationStore $store,
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
        ?float $deadlineMonotonic = null,
    ): array {
        return $store->activate(
            $domain,
            $certificate,
            $privateKey,
            $chain,
            $sourceRoots,
            $deadlineMonotonic,
            ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
        );
    }

    /** @return array<string,mixed> */
    private static function issueExplicitReenableIntentForTest(
        ProjectCertificateGenerationStore $store,
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
        ?float $deadlineMonotonic = null,
    ): array {
        return $store->issueExplicitReenableIntent(
            $domain,
            $certificate,
            $privateKey,
            $chain,
            $sourceRoots,
            $deadlineMonotonic,
            ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
        );
    }

    /**
     * @return array{cert:string,key:string}
     */
    private function createCertificate(string $domain, string $name): array
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/' . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
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
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($certificate);
        self::assertTrue(\openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));

        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));
        return ['cert' => $certificatePath, 'key' => $keyPath];
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

    private function generationStoreWithClock(
        int &$wall,
        float &$monotonic,
        string &$boot,
    ): ProjectCertificateGenerationStore {
        return new ProjectCertificateGenerationStore(
            $this->root,
            static function () use (&$wall): int {
                return $wall;
            },
            static function () use (&$monotonic): float {
                return $monotonic;
            },
            static function () use (&$boot): string {
                return $boot;
            },
        );
    }

    /**
     * @return array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}>
     */
    private function snapshotRetirementInventory(string $digest, int $mtime): array
    {
        return [$digest => [
            'digest' => $digest,
            'path' => $this->root . DIRECTORY_SEPARATOR . 'unused-' . $digest,
            'bytes' => 1,
            'mtime' => $mtime,
            'cert_sha256' => \str_repeat('4', 64),
            'key_sha256' => \str_repeat('5', 64),
            'chain_sha256' => '',
        ]];
    }

    /**
     * @param array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}> $inventory
     * @return list<string>
     */
    private function collectableSnapshotRetirementDigests(
        ProjectCertificateGenerationStore $store,
        array $inventory,
    ): array {
        $method = new \ReflectionMethod(
            ProjectCertificateGenerationStore::class,
            'collectableSnapshotRetirementDigests',
        );
        $method->setAccessible(true);
        return $method->invoke($store, $inventory, []);
    }

    private function snapshotRetirementStateFile(): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshot-retirement.json';
    }

    /** @param array<string,mixed> $intent */
    private function retirementProof(array $intent): array
    {
        return [
            'schema' => 'wls-certificate-retirement-proof/1',
            'intent_id' => (string)$intent['intent_id'],
            'domain' => (string)$intent['domain'],
            'generation' => (int)$intent['generation'],
            'source_digest' => (string)$intent['source_digest'],
            'gateway' => [
                'status' => 'not_observed',
                'evidence_digest' => \str_repeat('a', 64),
            ],
            'native' => [
                'status' => 'not_observed',
                'evidence_digest' => \str_repeat('b', 64),
            ],
            'verified_at' => '2026-08-06T00:00:00+00:00',
        ];
    }

    private function removeRetirementIntentFromTombstone(string $domain): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/disabled/'
            . \substr(\hash('sha256', $domain), 0, 32) . '.json';
        $envelope = \json_decode((string)\file_get_contents($file), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        unset($envelope['payload']['retirement_intent']);
        $envelope['sha256'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $envelope['payload'],
            ),
        );
        self::assertNotFalse(\file_put_contents(
            $file,
            \json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($file, 0600));
        }
    }

    private function changeOwnership(string $root, int $uid, int $gid): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            self::assertTrue(@\chown($item->getPathname(), $uid));
            self::assertTrue(@\chgrp($item->getPathname(), $gid));
        }
        self::assertTrue(@\chown($root, $uid));
        self::assertTrue(@\chgrp($root, $gid));
    }

    private function assertAtomicArtifactDoesNotPoisonActiveManifest(string $suffix): void
    {
        $domain = 'active-artifact.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'active-artifact');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $target = $this->selectorManifestPath('active', $domain);
        $artifact = $target . $suffix;
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));

        $nextDomain = 'active-artifact-next.example.test';
        $next = $this->createCertificate($nextDomain, 'active-artifact-next');
        $activated = self::activateForTest($store, $nextDomain, $next['cert'], $next['key']);

        self::assertSame(2, $activated['generation']);
        self::assertFileDoesNotExist(
            $artifact,
            'Validated committed selector targets make paired artifacts safely reclaimable.',
        );
    }

    private function assertAtomicArtifactDoesNotPoisonDisabledManifest(string $suffix): void
    {
        $domain = 'disabled-artifact.example.test';
        $store = new ProjectCertificateGenerationStore($this->root);
        $source = $this->createCertificate($domain, 'disabled-artifact');
        self::activateForTest($store, $domain, $source['cert'], $source['key']);
        $store->deactivate($domain);
        $target = $this->selectorManifestPath('disabled', $domain);
        $artifact = $target . $suffix;
        self::assertTrue(\copy($target, $artifact));
        self::assertTrue(\chmod($artifact, 0600));

        $facts = $store->disabledCertificates();

        self::assertArrayHasKey($domain, $facts);
        self::assertFileDoesNotExist(
            $artifact,
            'Validated committed tombstones make paired artifacts safely reclaimable.',
        );
    }

    private function selectorManifestPath(string $selector, string $domain): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/'
            . $selector . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    /**
     * @param array{cert:string,key:string} $source
     * @return array<string,mixed>
     */
    private function activateWithPostRenameSyncFailure(
        ProjectCertificateGenerationStore $store,
        string $domain,
        array $source,
    ): array {
        $target = $this->selectorManifestPath('active', $domain);
        $previousMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $previousFailure = \getenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE');
        $previousTarget = \getenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256',
        );
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE=directory_fsync_after_rename_failed',
        );
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256='
                . \hash('sha256', $target),
        );
        try {
            return self::activateForTest($store,
                $domain,
                $source['cert'],
                $source['key'],
            );
        } finally {
            $previousMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousMode);
            $previousFailure === false
                ? \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE=' . $previousFailure,
                );
            $previousTarget === false
                ? \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256='
                        . $previousTarget,
                );
        }
    }

    private function certificateGenerationFloorPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/generation-floor.txt';
    }

    private function snapshotRetirementStatePath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/snapshot-retirement.json';
    }

    private function retirementReplayCursorPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/.wls-generations/retirement-replay.cursor.json';
    }

    /** @return list<string> */
    private function createAtomicRecoveryArtifacts(string $target, int $count): array
    {
        $artifacts = [];
        for ($index = 0; $index < $count; ++$index) {
            $suffix = $index % 2 === 0
                ? '.tmp-' . \str_pad(\dechex($index + 1), 24, '0', STR_PAD_LEFT)
                : '.wls-backup-'
                    . \str_pad(\dechex($index + 1), 16, '0', STR_PAD_LEFT);
            $artifact = $target . $suffix;
            self::assertTrue(\copy($target, $artifact));
            self::assertTrue(\chmod($artifact, 0600));
            $artifacts[] = $artifact;
        }
        return $artifacts;
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

    private function copyTree(string $source, string $target): void
    {
        self::assertTrue(\mkdir($target, 0700, true));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = \substr($item->getPathname(), \strlen($source) + 1);
            $destination = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::assertTrue(\mkdir($destination, 0700, true));
                continue;
            }
            self::assertTrue(\copy($item->getPathname(), $destination));
            self::assertTrue(\chmod($destination, $item->getPerms() & 0777));
        }
    }
}
