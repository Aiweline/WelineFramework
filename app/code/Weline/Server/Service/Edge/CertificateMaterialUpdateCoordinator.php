<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayEmergencyRevocationClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayProjectEndpointReader;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\MasterLeaseManager;

/**
 * Dispatch one certificate material change to every eligible serving face.
 *
 * Native TLS reload, durable gateway convergence, and explicit legacy Nginx
 * reload are independent targets. Immutable env adapter selection must never
 * suppress one target while an auto project is switching serving surfaces.
 */
final class CertificateMaterialUpdateCoordinator
{
    public function notify(
        string $domain = '',
        array $paths = [],
        string $sourceAdapter = '',
        ?float $deadlineMonotonic = null,
    ): void
    {
        $retirementIntents = $this->validatedRevocationIntent(
            $domain,
            $paths,
            $deadlineMonotonic,
        );
        $revokedDomains = [];
        foreach ($retirementIntents as $retiredDomain => $intent) {
            $revokedDomains[$retiredDomain] = (int)$intent['generation'];
        }
        unset($paths);
        $domains = $domain !== '' ? [$domain] : [];
        $failures = [];
        $runtimeConvergenceFailures = [];
        $revocationCommitted = $revokedDomains !== [];
        if ($revocationCommitted) {
            $this->assertRetirementBudget($deadlineMonotonic, 0.25);
        }

        $endpoints = (new GatewayProjectEndpointReader())->all($deadlineMonotonic);
        $endpointSnapshotDigest = $revocationCommitted
            ? \hash('sha256', GatewayClient::canonicalJson($endpoints))
            : \hash('sha256', 'wls-no-retirement-observation');
        $masterLeases = new MasterLeaseManager();
        $legacyManaged = false;
        $liveGatewayParticipants = [];
        $liveProjectInstances = [];
        $nativeReloadRequired = [];
        $nativeContainmentTargets = [];
        $preflightFailures = [];
        $gatewayParticipationObserved = false;
        $gatewayObservationIndeterminate = false;
        $gatewayHostTrustState = 'not_checked';
        $gatewayEnrollmentState = 'not_checked';
        $gatewayProofDigests = [];
        $nativeProofDigests = [];
        if ($revocationCommitted) {
            try {
                $hostTrust = $this->hostGatewayTrustObservation(
                    $deadlineMonotonic,
                );
                $gatewayHostTrustState = $hostTrust;
                if (\hash_equals('present', $hostTrust)) {
                    (new GatewayCredentialStore())->load(
                        (new GatewayRegistrationBuilder())->projectUuid(
                            $deadlineMonotonic,
                        ),
                    );
                    $gatewayEnrollmentState = 'enrolled';
                    // Host-bound enrollment survives a project-process crash
                    // even when no endpoint lease remains live. It is
                    // sufficient evidence that guardian retirement is still
                    // required.
                    $gatewayParticipationObserved = true;
                } else {
                    $gatewayEnrollmentState = 'not_applicable';
                }
            } catch (\Throwable $throwable) {
                if (\hash_equals(
                    'This project is not enrolled on the trusted WLS 2.0 host gateway.',
                    $throwable->getMessage(),
                )) {
                    // A missing project-side capability does not prove that the
                    // host controller has forgotten this project. Only a signed
                    // host absence response may close that ambiguity.
                    $gatewayEnrollmentState = 'credential_missing';
                    $gatewayObservationIndeterminate = true;
                    $preflightFailures[] = 'host gateway exists but the project enrollment '
                        . 'credential is missing; retirement remains pending';
                } else {
                    $gatewayHostTrustState = 'indeterminate';
                    $gatewayEnrollmentState = 'indeterminate';
                    $gatewayObservationIndeterminate = true;
                    $preflightFailures[] = 'host gateway enrollment state is invalid: '
                        . $throwable->getMessage();
                }
            }
        }
        foreach ($endpoints as $instanceName => $endpoint) {
            if ($revocationCommitted) {
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
            }
            $instanceName = (string)$instanceName;
            $explicitLegacy = GatewayRuntimeServingProjection::isExplicitLegacyManagedNginx(
                $endpoint,
            );
            if ($explicitLegacy) {
                $legacyMasterPid = (int)($endpoint['master_pid'] ?? 0);
                $legacyMasterEpoch = (int)($endpoint['master_epoch'] ?? 0);
                if ($legacyMasterPid < 1 || $legacyMasterEpoch < 1) {
                    continue;
                }
                if ($revocationCommitted) {
                    $this->assertRetirementBudget($deadlineMonotonic, 5.0);
                }
                $legacyLease = $masterLeases->validateRunningLease(
                    MasterLeaseManager::pathForInstance($instanceName),
                    expectedInstance: $instanceName,
                    expectedMasterPid: $legacyMasterPid,
                    expectedEpoch: $legacyMasterEpoch,
                    requireManagedName: true,
                );
                if ($revocationCommitted) {
                    $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                }
                $legacyOwner = $legacyLease['lease'] ?? null;
                if (($legacyLease['authorized'] ?? false) === true
                    && \is_array($legacyOwner)
                    && (int)($legacyOwner['master_pid'] ?? 0) === $legacyMasterPid
                    && (int)($legacyOwner['master_epoch'] ?? 0) === $legacyMasterEpoch
                ) {
                    $legacyManaged = true;
                }
                // WLS 1.x compatibility remains isolated from the WLS 2.0
                // manifest/intent contract. A stale endpoint is not authority
                // to reload a project-managed Nginx process.
                continue;
            }
            $rawMasterPid = (int)($endpoint['master_pid'] ?? 0);
            $gatewayParticipant = GatewayRuntimeServingProjection::participatesInGateway(
                $endpoint,
            );
            if ($revocationCommitted && $gatewayParticipant) {
                // A stale/dead project endpoint is still recovery evidence
                // that this host gateway may retain the project's old TLS.
                // The guardian path below does not require a live Master.
                $gatewayParticipationObserved = true;
            }
            $potentialNativeTls = $revocationCommitted
                && $this->nativeReloadRequired($endpoint);
            if ($potentialNativeTls) {
                // Preserve the exact observed generation. Final retirement
                // must close this generation independently from whatever
                // generation occupies the same instance name later.
                $nativeContainmentTargets[$instanceName] = $endpoint;
                if ($rawMasterPid < 1) {
                    continue;
                }
                try {
                    if (!$this->processIsDefinitelyRunning(
                        $rawMasterPid,
                        $deadlineMonotonic,
                    )) {
                        continue;
                    }
                } catch (\Throwable $throwable) {
                    $preflightFailures[] = 'native TLS Master liveness is indeterminate: '
                        . $instanceName . ': ' . $throwable->getMessage();
                    continue;
                }
            }
            $fence = GatewayRuntimeServingProjection::endpointFence($endpoint);
            if ($fence === null) {
                if ($potentialNativeTls) {
                    $preflightFailures[] = 'live native TLS endpoint fence is invalid: '
                        . $instanceName;
                }
                continue;
            }
            $gateway = \is_array($endpoint['gateway'] ?? null)
                ? $endpoint['gateway']
                : [];
            if (!\hash_equals($instanceName, (string)($gateway['instance_id'] ?? ''))) {
                if ($potentialNativeTls) {
                    $preflightFailures[] = 'live native TLS endpoint identity changed: '
                        . $instanceName;
                }
                continue;
            }
            if ($revocationCommitted) {
                $this->assertRetirementBudget($deadlineMonotonic, 5.0);
            }
            $leaseValidation = $masterLeases->validateRunningLease(
                MasterLeaseManager::pathForInstance($instanceName),
                expectedInstance: $instanceName,
                expectedMasterPid: (int)$fence['master_pid'],
                expectedEpoch: (int)$fence['master_epoch'],
                requireManagedName: true,
            );
            if ($revocationCommitted) {
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
            }
            $lease = $leaseValidation['lease'] ?? null;
            if (($leaseValidation['authorized'] ?? false) !== true
                || !\is_array($lease)
                || (int)($lease['master_pid'] ?? 0) !== (int)$fence['master_pid']
                || (int)($lease['master_epoch'] ?? 0) !== (int)$fence['master_epoch']
            ) {
                if ($potentialNativeTls) {
                    $preflightFailures[] = 'live native TLS Master lease is not authoritative: '
                        . $instanceName;
                }
                continue;
            }
            $liveProjectInstances[$instanceName] = $fence;
            $nativeReloadRequired[$instanceName] = $this->nativeReloadRequired($endpoint);
            if ($revocationCommitted && $potentialNativeTls) {
                // A stale serving projection is not authority to skip a
                // security transition. Exact reload or containment will prove
                // that this live WLS Master no longer serves the old context.
                $nativeReloadRequired[$instanceName] = true;
            }
            if ($gatewayParticipant) {
                $liveGatewayParticipants[$instanceName] = true;
            }
        }

        // One project transaction freezes every live instance pointer behind
        // the same desired-state lock. A/B instances therefore either all
        // prove the new certificate generation or every potentially live TLS
        // face is quarantined after the lock set is released.
        if ($liveProjectInstances !== []) {
            $builder = new GatewayRegistrationBuilder();
            $gatewayIntentErrors = [];
            try {
                $builder->withServingPublicationTransactions(
                    \array_keys($liveProjectInstances),
                    function (array $transactions) use (
                        $domains,
                        $masterLeases,
                        $liveGatewayParticipants,
                        $liveProjectInstances,
                        $nativeReloadRequired,
                        $revocationCommitted,
                        $retirementIntents,
                        $deadlineMonotonic,
                        &$gatewayIntentErrors,
                        &$gatewayProofDigests,
                        &$nativeProofDigests,
                    ): void {
                        $publish = function () use (
                            $transactions,
                            $domains,
                            $masterLeases,
                            $liveGatewayParticipants,
                            $liveProjectInstances,
                            $nativeReloadRequired,
                            $revocationCommitted,
                            $retirementIntents,
                            $deadlineMonotonic,
                            &$gatewayIntentErrors,
                            &$gatewayProofDigests,
                            &$nativeProofDigests,
                        ): void {
                            if ($revocationCommitted) {
                                $generationStore = new ProjectCertificateGenerationStore();
                                foreach ($retirementIntents as $retiredDomain => $intent) {
                                    $current = $generationStore->retirementIntent(
                                        $retiredDomain,
                                        $deadlineMonotonic,
                                    );
                                    if (!\is_array($current)
                                        || !$this->sameRetirementIntentIdentity(
                                            $current,
                                            $intent,
                                        )
                                        || !\hash_equals(
                                            'pending',
                                            (string)($current['state'] ?? ''),
                                        )
                                        || !\hash_equals(
                                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                                            (string)($current['phase'] ?? ''),
                                        )
                                    ) {
                                        throw new \RuntimeException(
                                            'Certificate retirement authority changed before serving publication.',
                                        );
                                    }
                                }
                            }
                            foreach ($liveProjectInstances as $instanceName => $authorizedFence) {
                                $transaction = $transactions[$instanceName] ?? null;
                                if (!\is_array($transaction)
                                    || !($transaction['build_gateway'] ?? null) instanceof \Closure
                                    || !($transaction['build_serving'] ?? null) instanceof \Closure
                                ) {
                                    throw new \RuntimeException(
                                        'Serving publication transaction set is incomplete: '
                                            . $instanceName,
                                    );
                                }
                                $buildGateway = $transaction['build_gateway'];
                                $buildServing = $transaction['build_serving'];
                                if (isset($liveGatewayParticipants[$instanceName])) {
                                    try {
                                        $this->assertRetirementBudget(
                                            $deadlineMonotonic,
                                            2.0,
                                        );
                                        $registration = $buildGateway();
                                        $publication = $this->manifestPublication($registration);
                                        // Enqueue alone is not convergence. Wait for
                                        // authenticated host publication and exact
                                        // own-status acknowledgement while every
                                        // project serving lock remains held.
                                        $gatewayResult = (new GatewayHostManager())
                                            ->submitBuiltRegistration(
                                                $registration,
                                                $deadlineMonotonic,
                                        );
                                        if ($revocationCommitted) {
                                            foreach (\array_keys(
                                                $retirementIntents,
                                            ) as $retiredDomain) {
                                                $proofKey = 'register:' . $retiredDomain;
                                                if (!isset($gatewayProofDigests[$proofKey])) {
                                                    $gatewayProofDigests[$proofKey] = \hash(
                                                        'sha256',
                                                        GatewayClient::canonicalJson([
                                                            'request_digest' => (string)(
                                                                $registration['request_digest'] ?? ''
                                                            ),
                                                            'publication' => $gatewayResult,
                                                        ]),
                                                    );
                                                }
                                            }
                                        }
                                    } catch (\Throwable $gatewayError) {
                                        if ($revocationCommitted) {
                                            throw new \RuntimeException(
                                                'Shared gateway did not acknowledge certificate revocation for '
                                                    . $instanceName . ': '
                                                    . $gatewayError->getMessage(),
                                                0,
                                                $gatewayError,
                                            );
                                        }
                                        // Keep native fallback serviceable while
                                        // the gateway intent remains retryable.
                                        $publication = $this->manifestPublication(
                                            $buildServing(),
                                        );
                                        $gatewayIntentErrors[] = $instanceName . ': '
                                            . $gatewayError->getMessage();
                                    }
                                } else {
                                    $publication = $this->manifestPublication(
                                        $buildServing(),
                                    );
                                }
                                if (($nativeReloadRequired[$instanceName] ?? false) !== true) {
                                    continue;
                                }
                                $this->assertRetirementBudget(
                                    $deadlineMonotonic,
                                    5.0,
                                );
                                $reloadLease = $masterLeases->validateRunningLease(
                                    MasterLeaseManager::pathForInstance($instanceName),
                                    expectedInstance: $instanceName,
                                    expectedMasterPid: (int)$authorizedFence['master_pid'],
                                    expectedEpoch: (int)$authorizedFence['master_epoch'],
                                    requireManagedName: true,
                                );
                                $this->assertRetirementBudget(
                                    $deadlineMonotonic,
                                    0.01,
                                );
                                $reloadOwner = $reloadLease['lease'] ?? null;
                                if (($reloadLease['authorized'] ?? false) !== true
                                    || !\is_array($reloadOwner)
                                    || (int)($reloadOwner['master_pid'] ?? 0)
                                        !== (int)$authorizedFence['master_pid']
                                    || (int)($reloadOwner['master_epoch'] ?? 0)
                                        !== (int)$authorizedFence['master_epoch']
                                ) {
                                    throw new \RuntimeException(
                                        'Master lease changed after manifest publication.',
                                    );
                                }
                                $operationId = \bin2hex(\random_bytes(16));
                                $manifestGeneration = (int)$publication['generation'];
                                $manifestDigest = (string)$publication['digest'];
                                $tlsRouteCount = (int)$publication['route_count'];
                                $nativeControl = ObjectManager::getInstance(
                                    BroadcastControlDispatchService::class,
                                );
                                $remaining = $this->assertRetirementBudget(
                                    $deadlineMonotonic,
                                    0.5,
                                );
                                $native = $nativeControl->reloadSslCertAndWait(
                                    $domains,
                                    $instanceName,
                                    $operationId,
                                    $manifestGeneration,
                                    $manifestDigest,
                                    $tlsRouteCount,
                                    \min(8.0, $remaining),
                                );
                                $this->assertNativeReloadReceipt(
                                    $native,
                                    $instanceName,
                                    $operationId,
                                    $manifestGeneration,
                                    $manifestDigest,
                                    $tlsRouteCount,
                                );
                                if ($revocationCommitted) {
                                    $nativeProofDigests[$instanceName] = \hash(
                                        'sha256',
                                        GatewayClient::canonicalJson($native),
                                    );
                                }
                            }
                        };
                        if ($revocationCommitted) {
                            $this->withRetirementLifecycleLock(
                                new ProjectCertificateGenerationStore(),
                                $deadlineMonotonic,
                                $publish,
                            );
                            return;
                        }
                        $publish();
                    },
                    $deadlineMonotonic,
                );
            } catch (\Throwable $throwable) {
                $message = 'project serving manifest/native TLS transaction: '
                    . $throwable->getMessage();
                if ($revocationCommitted) {
                    $runtimeConvergenceFailures[] = $message;
                } else {
                    $failures[] = $message;
                }
            }
            foreach ($gatewayIntentErrors as $gatewayIntentError) {
                $failures[] = 'gateway renewal intent ' . $gatewayIntentError;
            }
        }

        foreach ($preflightFailures as $preflightFailure) {
            if ($revocationCommitted) {
                $runtimeConvergenceFailures[] = $preflightFailure;
            } else {
                $failures[] = $preflightFailure;
            }
        }
        $gatewayRetirementRequired = $gatewayParticipationObserved
            || $gatewayObservationIndeterminate;
        $gatewayAbsenceEvidenceDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'host_trust' => $gatewayHostTrustState,
                'enrollment' => $gatewayEnrollmentState,
                'endpoint_snapshot_digest' => $endpointSnapshotDigest,
            ]),
        );
        // This stage owns only WLS 2.0 gateway/native retirement. The durable
        // outbox stays pending after this proof so the certificate lifecycle
        // can independently retire legacy Nginx before source cleanup.
        if ($revocationCommitted) {
            $finalizeRetirement = function (array $transactions = []) use (
                $retirementIntents,
                $revokedDomains,
                $domains,
                $masterLeases,
                $gatewayRetirementRequired,
                $gatewayObservationIndeterminate,
                $gatewayAbsenceEvidenceDigest,
                $endpointSnapshotDigest,
                $nativeContainmentTargets,
                $deadlineMonotonic,
                &$gatewayProofDigests,
                &$nativeProofDigests,
                &$runtimeConvergenceFailures,
                &$failures,
            ): void {
                $generationStore = new ProjectCertificateGenerationStore();
                $this->withRetirementLifecycleLock(
                    $generationStore,
                    $deadlineMonotonic,
                    function () use (
                        $generationStore,
                        $retirementIntents,
                        $revokedDomains,
                        $domains,
                        $masterLeases,
                        $transactions,
                        $gatewayRetirementRequired,
                        $gatewayObservationIndeterminate,
                        $gatewayAbsenceEvidenceDigest,
                        $endpointSnapshotDigest,
                        $nativeContainmentTargets,
                        $deadlineMonotonic,
                        &$gatewayProofDigests,
                        &$nativeProofDigests,
                        &$runtimeConvergenceFailures,
                        &$failures,
                    ): void {
                    $nativeListenerGuards = [];
                    try {
                    // A publisher may have won the desired-state lock after the
                    // first snapshot. Revalidate under the lifecycle fence
                    // before any guardian or native containment side effect.
                    foreach ($retirementIntents as $retiredDomain => $intent) {
                        $current = $generationStore->retirementIntent(
                            $retiredDomain,
                            $deadlineMonotonic,
                        );
                        if (!\is_array($current)
                            || !$this->sameRetirementIntentIdentity($current, $intent)
                        ) {
                            throw new \RuntimeException(
                                'Certificate retirement authority changed before finalization.',
                            );
                        }
                        if (!\hash_equals('pending', (string)($current['state'] ?? ''))
                            || !\hash_equals(
                                ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                                (string)($current['phase'] ?? ''),
                            )
                        ) {
                            return;
                        }
                    }

                    // Native reload/absence is a volatile per-process fact, so
                    // it is always re-established while the exact desired-state,
                    // per-instance publication and certificate lifecycle locks
                    // are held together. A gateway security-retirement receipt
                    // is durable, domain/tombstone/config-generation bound; keep
                    // it, but re-observe the host trust root below so an install
                    // or damaged host appearing after the first snapshot cannot
                    // reuse stale absence evidence.
                    $nativeProofDigests = [];
                    $currentEndpoints = (new GatewayProjectEndpointReader())
                        ->all($deadlineMonotonic);
                    $finalGatewayRetirementRequired = $gatewayRetirementRequired;
                    $finalGatewayObservationIndeterminate
                        = $gatewayObservationIndeterminate;
                    foreach ($currentEndpoints as $currentEndpoint) {
                        if (\is_array($currentEndpoint)
                            && GatewayRuntimeServingProjection::participatesInGateway(
                                $currentEndpoint,
                            )
                        ) {
                            $finalGatewayRetirementRequired = true;
                        }
                    }
                    try {
                        $finalHostTrust = $this->hostGatewayTrustObservation(
                            $deadlineMonotonic,
                        );
                        if (\hash_equals('present', $finalHostTrust)) {
                            // The host may have been installed/enrolled after the
                            // first snapshot. Require an exact guardian/register
                            // receipt instead of preserving stale absence proof.
                            $finalGatewayRetirementRequired = true;
                        }
                    } catch (\Throwable $throwable) {
                        $finalHostTrust = 'indeterminate';
                        $finalGatewayObservationIndeterminate = true;
                        $runtimeConvergenceFailures[] = 'host gateway final trust observation: '
                            . $throwable->getMessage();
                    }
                    $finalGatewayAbsenceEvidenceDigest = \hash(
                        'sha256',
                        GatewayClient::canonicalJson([
                            'host_trust' => $finalHostTrust,
                            'initial_evidence_digest' => $gatewayAbsenceEvidenceDigest,
                            'endpoint_snapshot_digest' => \hash(
                                'sha256',
                                GatewayClient::canonicalJson($currentEndpoints),
                            ),
                        ]),
                    );
                    // Close every observed native generation independently.
                    // A receipt for the current Master must never overwrite a
                    // failed absence proof for an older Master that used the
                    // same instance name.
                    $finalNativeContainmentTargets = $nativeContainmentTargets;
                    $unlockedFinalNativeTargets = [];
                    foreach ($currentEndpoints as $instanceName => $currentEndpoint) {
                        $instanceName = (string)$instanceName;
                        if (!\is_array($currentEndpoint)
                            || !$this->nativeReloadRequired($currentEndpoint)
                            || isset($finalNativeContainmentTargets[$instanceName])
                        ) {
                            continue;
                        }
                        $finalNativeContainmentTargets[$instanceName] = $currentEndpoint;
                        if (!isset($transactions[$instanceName])) {
                            // The outer deterministic lock set was selected
                            // from the first snapshot. Never act on a newly
                            // appeared instance without its publication lock;
                            // the retry will include it from the beginning.
                            $unlockedFinalNativeTargets[$instanceName] = true;
                            $runtimeConvergenceFailures[] = 'native TLS instance appeared '
                                . 'outside the final publication lock set: ' . $instanceName;
                        }
                    }

                    $nativeProofComponents = [];
                    $unclosedNativeGenerations = $unlockedFinalNativeTargets;
                    $liveNativeProofTargets = [];
                    foreach ($finalNativeContainmentTargets as $instanceName => $expectedEndpoint) {
                        $instanceName = (string)$instanceName;
                        if (isset($unlockedFinalNativeTargets[$instanceName])) {
                            continue;
                        }
                        if (!\is_array($expectedEndpoint)) {
                            $unclosedNativeGenerations[$instanceName] = true;
                            $runtimeConvergenceFailures[] = 'native TLS observed generation '
                                . 'is malformed: ' . $instanceName;
                            continue;
                        }
                        $currentEndpoint = \is_array($currentEndpoints[$instanceName] ?? null)
                            ? $currentEndpoints[$instanceName]
                            : null;
                        $sameEndpointSnapshot = \is_array($currentEndpoint)
                            && \hash_equals(
                                \hash(
                                    'sha256',
                                    GatewayClient::canonicalJson($expectedEndpoint),
                                ),
                                \hash(
                                    'sha256',
                                    GatewayClient::canonicalJson($currentEndpoint),
                                ),
                            );
                        $sameRuntimeFence = \is_array($currentEndpoint)
                            && $this->sameNativeRuntimeFence(
                                $expectedEndpoint,
                                $currentEndpoint,
                            );

                        try {
                            $expectedMasterPid = (int)($expectedEndpoint['master_pid'] ?? 0);
                            $expectedMasterRunning = $expectedMasterPid > 0
                                && $this->processIsDefinitelyRunning(
                                    $expectedMasterPid,
                                    $deadlineMonotonic,
                                );
                            if ($expectedMasterRunning) {
                                if (!$sameRuntimeFence || !\is_array($currentEndpoint)) {
                                    throw new \RuntimeException(
                                        'The observed native TLS generation remains running '
                                            . 'after its endpoint fence changed.',
                                    );
                                }
                                // The exact observed Master is still current.
                                // A fresh reload/quarantine receipt for this
                                // same fence closes both observations.
                                $liveNativeProofTargets[$instanceName] = $currentEndpoint;
                            } else {
                                $nativeProofComponents[$instanceName]
                                    ['observed_generation_absence'] = $this
                                        ->proveDeadNativeTlsEndpointAbsent(
                                            $instanceName,
                                            $expectedEndpoint,
                                            $deadlineMonotonic,
                                            $nativeListenerGuards,
                                        );
                            }
                        } catch (\Throwable $throwable) {
                            $unclosedNativeGenerations[$instanceName] = true;
                            $runtimeConvergenceFailures[] = 'native TLS observed generation proof '
                                . $instanceName . ': ' . $throwable->getMessage();
                        }

                        if (!\is_array($currentEndpoint)
                            || $sameEndpointSnapshot
                            || !$this->nativeReloadRequired($currentEndpoint)
                        ) {
                            continue;
                        }
                        try {
                            $currentFence = GatewayRuntimeServingProjection::endpointFence(
                                $currentEndpoint,
                            );
                            $currentGateway = \is_array($currentEndpoint['gateway'] ?? null)
                                ? $currentEndpoint['gateway']
                                : [];
                            $currentMasterPid = (int)($currentEndpoint['master_pid'] ?? 0);
                            $currentMasterRunning = $currentMasterPid > 0
                                && $this->processIsDefinitelyRunning(
                                    $currentMasterPid,
                                    $deadlineMonotonic,
                                );
                            if ($currentMasterRunning) {
                                if ($currentFence === null
                                    || !\hash_equals(
                                        $instanceName,
                                        (string)($currentGateway['instance_id'] ?? ''),
                                    )
                                ) {
                                    throw new \RuntimeException(
                                        'The final live native TLS generation has no exact fence.',
                                    );
                                }
                                $liveNativeProofTargets[$instanceName] = $currentEndpoint;
                            } else {
                                $nativeProofComponents[$instanceName]
                                    ['final_generation_absence'] = $this
                                        ->proveDeadNativeTlsEndpointAbsent(
                                            $instanceName,
                                            $currentEndpoint,
                                            $deadlineMonotonic,
                                            $nativeListenerGuards,
                                        );
                            }
                        } catch (\Throwable $throwable) {
                            $unclosedNativeGenerations[$instanceName] = true;
                            $runtimeConvergenceFailures[] = 'native TLS final generation proof '
                                . $instanceName . ': ' . $throwable->getMessage();
                        }
                    }

                    $nativeLiveProofDigests = [];
                    if ($liveNativeProofTargets !== []) {
                        $refresh = $this->refreshNativeTlsProofs(
                            \array_keys($liveNativeProofTargets),
                            $transactions,
                            $currentEndpoints,
                            $domains,
                            $masterLeases,
                            $deadlineMonotonic,
                        );
                        foreach ($refresh['proofs'] as $instanceName => $proofDigest) {
                            $instanceName = (string)$instanceName;
                            $nativeLiveProofDigests[$instanceName] = \hash(
                                'sha256',
                                GatewayClient::canonicalJson([
                                    'method' => 'reload',
                                    'endpoint_digest' => \hash(
                                        'sha256',
                                        GatewayClient::canonicalJson(
                                            $liveNativeProofTargets[$instanceName],
                                        ),
                                    ),
                                    'receipt_digest' => (string)$proofDigest,
                                ]),
                            );
                        }
                        foreach ($refresh['failures'] as $refreshFailure) {
                            $runtimeConvergenceFailures[] = $refreshFailure;
                        }
                    }

                    $missingLiveNativeProofs = \array_diff_key(
                        $liveNativeProofTargets,
                        $nativeLiveProofDigests,
                    );
                    if ($missingLiveNativeProofs !== []) {
                        $quarantine = $this->quarantineNativeTlsFaces(
                            \array_keys($missingLiveNativeProofs),
                            $revokedDomains,
                            $deadlineMonotonic,
                            $liveNativeProofTargets,
                        );
                        foreach ($quarantine['proofs'] as $instanceName => $proofDigest) {
                            $nativeLiveProofDigests[(string)$instanceName]
                                = (string)$proofDigest;
                        }
                        foreach ($quarantine['failures'] as $containmentFailure) {
                            $runtimeConvergenceFailures[] = $containmentFailure;
                        }
                    }

                    foreach ($liveNativeProofTargets as $instanceName => $_endpoint) {
                        if (!isset($nativeLiveProofDigests[$instanceName])) {
                            $unclosedNativeGenerations[$instanceName] = true;
                            continue;
                        }
                        $nativeProofComponents[$instanceName]
                            ['live_generation_containment']
                                = $nativeLiveProofDigests[$instanceName];
                    }
                    $nativeProofDigests = [];
                    foreach ($finalNativeContainmentTargets as $instanceName => $_endpoint) {
                        $instanceName = (string)$instanceName;
                        $components = $nativeProofComponents[$instanceName] ?? [];
                        if (isset($unclosedNativeGenerations[$instanceName])
                            || !\is_array($components)
                            || $components === []
                        ) {
                            continue;
                        }
                        \ksort($components, SORT_STRING);
                        $nativeProofDigests[$instanceName] = \hash(
                            'sha256',
                            GatewayClient::canonicalJson($components),
                        );
                    }

                    if ($finalGatewayRetirementRequired
                        && !$finalGatewayObservationIndeterminate
                    ) {
                        foreach ($revokedDomains as $revokedDomain => $revokedGeneration) {
                            if (isset($gatewayProofDigests['register:' . $revokedDomain])) {
                                continue;
                            }
                            try {
                                $this->assertRetirementBudget(
                                    $deadlineMonotonic,
                                    1.0,
                                );
                                $tombstone = $generationStore->disabled(
                                    $revokedDomain,
                                    $deadlineMonotonic,
                                );
                                if (!\is_array($tombstone)
                                    || (int)($tombstone['generation'] ?? 0)
                                        !== $revokedGeneration
                                ) {
                                    throw new \RuntimeException(
                                        'Durable project revocation tombstone changed before guardian fallback.',
                                    );
                                }
                                $guardian = (new GatewayEmergencyRevocationClient())->revoke(
                                    [
                                        'domain' => $revokedDomain,
                                        'generation' => $revokedGeneration,
                                        'source_digest' => (string)(
                                            $tombstone['source_digest'] ?? ''
                                        ),
                                    ],
                                    $deadlineMonotonic,
                                );
                                $gatewayProofDigests['guardian:' . $revokedDomain] = \hash(
                                    'sha256',
                                    GatewayClient::canonicalJson($guardian),
                                );
                            } catch (\Throwable $throwable) {
                                $runtimeConvergenceFailures[] = 'host gateway emergency revocation '
                                    . $revokedDomain . ': ' . $throwable->getMessage();
                            }
                        }
                    }

                    \ksort($gatewayProofDigests, SORT_STRING);
                    \ksort($nativeProofDigests, SORT_STRING);
                    $selectedGatewayProofs = [];
                    $gatewayProofComplete = true;
                    if ($finalGatewayRetirementRequired) {
                        foreach (\array_keys($revokedDomains) as $revokedDomain) {
                            $registerKey = 'register:' . $revokedDomain;
                            $guardianKey = 'guardian:' . $revokedDomain;
                            if (isset($gatewayProofDigests[$registerKey])) {
                                $selectedGatewayProofs[$registerKey]
                                    = $gatewayProofDigests[$registerKey];
                            } elseif (isset($gatewayProofDigests[$guardianKey])) {
                                $selectedGatewayProofs[$guardianKey]
                                    = $gatewayProofDigests[$guardianKey];
                            } else {
                                $gatewayProofComplete = false;
                            }
                        }
                    }
                    $gatewayProofDigests = $selectedGatewayProofs;
                    \ksort($gatewayProofDigests, SORT_STRING);
                    $expectedNativeTargets = $finalNativeContainmentTargets;
                    \ksort($expectedNativeTargets, SORT_STRING);
                    if ($failures !== []
                        || $finalGatewayObservationIndeterminate
                        || !$gatewayProofComplete
                        || ($finalNativeContainmentTargets !== []
                            && \array_keys($expectedNativeTargets)
                                !== \array_keys($nativeProofDigests))
                    ) {
                        foreach (\array_values(\array_unique(
                            $runtimeConvergenceFailures,
                        )) as $runtimeFailure) {
                            $failures[] = $runtimeFailure;
                        }
                        $failures[] = 'certificate retirement proof set is incomplete';
                        return;
                    }
                    foreach ($retirementIntents as $retiredDomain => $intent) {
                        try {
                            $generationStore->completeRetirementIntent(
                                $intent,
                                [
                                    'schema' => 'wls-certificate-retirement-proof/1',
                                    'intent_id' => (string)$intent['intent_id'],
                                    'domain' => $retiredDomain,
                                    'generation' => (int)$intent['generation'],
                                    'source_digest' => (string)$intent['source_digest'],
                                    'gateway' => [
                                        'status' => $finalGatewayRetirementRequired
                                            ? 'retired'
                                            : 'not_observed',
                                        'evidence_digest' => $finalGatewayRetirementRequired
                                            ? \hash(
                                                'sha256',
                                                GatewayClient::canonicalJson(
                                                    $gatewayProofDigests,
                                                ),
                                            )
                                            : $finalGatewayAbsenceEvidenceDigest,
                                    ],
                                    'native' => [
                                        'status' => $finalNativeContainmentTargets !== []
                                            ? 'retired'
                                            : 'not_observed',
                                        'evidence_digest' => $finalNativeContainmentTargets !== []
                                            ? \hash(
                                                'sha256',
                                                GatewayClient::canonicalJson(
                                                    $nativeProofDigests,
                                                ),
                                            )
                                            : $endpointSnapshotDigest,
                                    ],
                                    'verified_at' => \gmdate(DATE_ATOM),
                                ],
                                $deadlineMonotonic,
                            );
                        } catch (\Throwable $throwable) {
                            $failures[] = 'certificate retirement outbox '
                                . $retiredDomain . ': ' . $throwable->getMessage();
                        }
                    }
                    } finally {
                        foreach ($nativeListenerGuards as $guard) {
                            if (\is_resource($guard)) {
                                @\fclose($guard);
                            }
                        }
                    }
                    },
                );
            };
            $finalizationInstances = \array_keys(
                $liveProjectInstances + $nativeContainmentTargets,
            );
            \sort($finalizationInstances, SORT_STRING);
            try {
                if ($finalizationInstances === []) {
                    $finalizeRetirement([]);
                } else {
                    (new GatewayRegistrationBuilder())->withServingPublicationTransactions(
                        $finalizationInstances,
                        static fn (array $transactions): mixed => $finalizeRetirement(
                            $transactions,
                        ),
                        $deadlineMonotonic,
                    );
                }
            } catch (\Throwable $throwable) {
                $failures[] = 'certificate retirement finalization: '
                    . $throwable->getMessage();
            }
        }

        $legacyCompatibilityProbe = $sourceAdapter === EdgeAdapterInterface::NAME_NGINX
            && $liveProjectInstances === []
            && !$legacyManaged;
        if (!$revocationCommitted && ($legacyManaged || $legacyCompatibilityProbe)) {
            try {
                $managed = ManagedNginxService::fromEnv();
                if (!$managed->isEdgeNginxManaged() || !$managed->paths()->isInstalled()) {
                    if ($legacyManaged) {
                        $failures[] = 'legacy managed Nginx is not installed or no longer owned';
                    }
                } else {
                    $result = $managed->reload();
                    if (!($result['ok'] ?? false)
                        && ($legacyManaged
                            || (string)($result['message'] ?? '')
                                !== 'managed nginx is not running')
                    ) {
                        $failures[] = (string)(
                            $result['message']
                            ?? 'Project-managed Nginx certificate reload failed.'
                        );
                    }
                }
            } catch (\Throwable $throwable) {
                // A compatibility probe against a missing or broken managed Nginx
                // tree must not fail certificate persistence for pure-WLS / first
                // install. Only a live legacy-managed owner hard-fails here.
                if ($legacyManaged) {
                    $failures[] = 'legacy managed Nginx: ' . $throwable->getMessage();
                }
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(GatewayBoundedText::singleLine(
                \implode('; ', $failures),
                4096,
                'Certificate material update did not reach every eligible runtime.',
            ));
        }
    }

    /**
     * A historical tombstone is not evidence that this notification is a new
     * irreversible revoke. Only the lifecycle transaction that just committed
     * the exact domain/generation/digest may request project-wide containment.
     *
     * @param array<mixed,mixed> $paths
     * @return array<string,array<string,mixed>> domain => pending retirement intent
     */
    private function validatedRevocationIntent(
        string $domain,
        array $paths,
        ?float $deadlineMonotonic,
    ): array
    {
        $intent = $paths['wls_revocation_intent'] ?? null;
        if ($intent === null) {
            return [];
        }
        if (!\is_array($intent) || \array_is_list($intent)) {
            throw new \RuntimeException('Certificate revocation intent is malformed.');
        }
        $intentDomain = \strtolower(\rtrim(\trim((string)(
            $intent['domain'] ?? ''
        )), '.'));
        $generation = $intent['generation'] ?? null;
        $sourceDigest = \strtolower(\trim((string)(
            $intent['source_digest'] ?? ''
        )));
        $intentId = \strtolower(\trim((string)($intent['intent_id'] ?? '')));
        $createdAt = \trim((string)($intent['created_at'] ?? ''));
        if ($domain === ''
            || !\hash_equals(
                'wls-project-certificate-retirement/1',
                (string)($intent['schema'] ?? ''),
            )
            || !\in_array(
                (string)($intent['state'] ?? ''),
                ['pending', 'completed', 'superseded'],
                true,
            )
            || !\hash_equals($intentDomain, \strtolower(\rtrim(\trim($domain), '.')))
            || !\is_int($generation)
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1
            || !\hash_equals(
                \hash(
                    'sha256',
                    "wls-certificate-retirement\0" . $intentDomain . "\0"
                        . $generation . "\0" . $sourceDigest,
                ),
                $intentId,
            )
            || !\hash_equals(
                \hash(
                    'sha256',
                    "wls-disabled-certificate\0" . $intentDomain . "\0" . $generation,
                ),
                $sourceDigest,
            )
            || $createdAt === ''
            || \strlen($createdAt) > 128
            || \strtotime($createdAt) === false
        ) {
            throw new \RuntimeException(
                'Certificate revocation intent is not bound to its exact tombstone.',
            );
        }
        $stored = (new ProjectCertificateGenerationStore())
            ->retirementIntent($intentDomain, $deadlineMonotonic);
        if (!\is_array($stored)
            || !\hash_equals($intentId, (string)($stored['intent_id'] ?? ''))
            || (int)($stored['generation'] ?? 0) !== $generation
            || !\hash_equals(
                $sourceDigest,
                (string)($stored['source_digest'] ?? ''),
            )
            || !\hash_equals(
                (string)($intent['metadata_digest'] ?? ''),
                (string)($stored['metadata_digest'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Certificate revocation intent no longer matches durable project authority.',
            );
        }
        if (!\hash_equals('pending', (string)($stored['state'] ?? ''))
            || !\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                (string)($stored['phase'] ?? ''),
            )
        ) {
            return [];
        }
        return [$intentDomain => $stored];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameRetirementIntentIdentity(array $left, array $right): bool
    {
        return \hash_equals(
            (string)($left['intent_id'] ?? ''),
            (string)($right['intent_id'] ?? ''),
        )
            && \hash_equals(
                (string)($left['domain'] ?? ''),
                (string)($right['domain'] ?? ''),
            )
            && \is_int($left['generation'] ?? null)
            && \is_int($right['generation'] ?? null)
            && (int)$left['generation'] === (int)$right['generation']
            && \hash_equals(
                (string)($left['source_digest'] ?? ''),
                (string)($right['source_digest'] ?? ''),
            )
            && \hash_equals(
                (string)($left['metadata_digest'] ?? ''),
                (string)($right['metadata_digest'] ?? ''),
            );
    }

    /**
     * Rebuild and acknowledge the exact current native TLS generation while
     * the caller holds every project publication lock and the certificate
     * lifecycle lock. A proof from the earlier convergence pass is never
     * reused across that lock boundary.
     *
     * @param list<string> $instanceNames
     * @param array<string,array<string,mixed>> $transactions
     * @param array<string,array<string,mixed>> $endpoints
     * @param list<string> $domains
     * @return array{proofs:array<string,string>,failures:list<string>}
     */
    private function refreshNativeTlsProofs(
        array $instanceNames,
        array $transactions,
        array $endpoints,
        array $domains,
        MasterLeaseManager $masterLeases,
        ?float $deadlineMonotonic,
    ): array {
        $instanceNames = \array_values(\array_unique(\array_map(
            'strval',
            $instanceNames,
        )));
        \sort($instanceNames, SORT_STRING);
        $proofs = [];
        $failures = [];
        foreach ($instanceNames as $instanceName) {
            try {
                $this->assertRetirementBudget($deadlineMonotonic, 0.5);
                $endpoint = $endpoints[$instanceName] ?? null;
                $transaction = $transactions[$instanceName] ?? null;
                if (!\is_array($endpoint)
                    || !\is_array($transaction)
                    || !($transaction['build_serving'] ?? null) instanceof \Closure
                ) {
                    throw new \RuntimeException(
                        'The final native TLS publication fence is incomplete.',
                    );
                }
                $fence = GatewayRuntimeServingProjection::endpointFence($endpoint);
                $gateway = \is_array($endpoint['gateway'] ?? null)
                    ? $endpoint['gateway']
                    : [];
                if ($fence === null
                    || !\hash_equals(
                        $instanceName,
                        (string)($gateway['instance_id'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'The final native TLS endpoint identity is invalid.',
                    );
                }
                $this->assertRetirementBudget($deadlineMonotonic, 5.0);
                $leaseValidation = $masterLeases->validateRunningLease(
                    MasterLeaseManager::pathForInstance($instanceName),
                    expectedInstance: $instanceName,
                    expectedMasterPid: (int)$fence['master_pid'],
                    expectedEpoch: (int)$fence['master_epoch'],
                    requireManagedName: true,
                );
                $this->assertRetirementBudget($deadlineMonotonic, 0.5);
                $lease = $leaseValidation['lease'] ?? null;
                if (($leaseValidation['authorized'] ?? false) !== true
                    || !\is_array($lease)
                    || (int)($lease['master_pid'] ?? 0) !== (int)$fence['master_pid']
                    || (int)($lease['master_epoch'] ?? 0) !== (int)$fence['master_epoch']
                ) {
                    throw new \RuntimeException(
                        'The final native TLS Master lease is not authoritative.',
                    );
                }
                $buildServing = $transaction['build_serving'];
                $publication = $this->manifestPublication($buildServing());
                $operationId = \bin2hex(\random_bytes(16));
                $remaining = $this->assertRetirementBudget(
                    $deadlineMonotonic,
                    0.5,
                );
                $nativeControl = ObjectManager::getInstance(
                    BroadcastControlDispatchService::class,
                );
                $native = $nativeControl->reloadSslCertAndWait(
                    $domains,
                    $instanceName,
                    $operationId,
                    (int)$publication['generation'],
                    (string)$publication['digest'],
                    (int)$publication['route_count'],
                    \min(8.0, $remaining),
                );
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                $this->assertNativeReloadReceipt(
                    $native,
                    $instanceName,
                    $operationId,
                    (int)$publication['generation'],
                    (string)$publication['digest'],
                    (int)$publication['route_count'],
                );
                $proofs[$instanceName] = \hash(
                    'sha256',
                    GatewayClient::canonicalJson($native),
                );
            } catch (\Throwable $throwable) {
                $failures[] = 'native TLS final generation proof ' . $instanceName
                    . ': ' . $throwable->getMessage();
            }
        }
        \ksort($proofs, SORT_STRING);
        return ['proofs' => $proofs, 'failures' => $failures];
    }

    /**
     * @param list<string> $instanceNames
     * @param array<string,int> $revokedDomains
     * @param array<string,array<string,mixed>> $expectedEndpoints
     * @return array{proofs:array<string,string>,failures:list<string>}
     */
    private function quarantineNativeTlsFaces(
        array $instanceNames,
        array $revokedDomains,
        ?float $deadlineMonotonic = null,
        array $expectedEndpoints = [],
    ): array {
        $proofs = [];
        $failures = [];
        $reason = 'certificate-revocation:' . \substr(\hash(
            'sha256',
            \json_encode($revokedDomains, JSON_THROW_ON_ERROR),
        ), 0, 32);
        \sort($instanceNames, SORT_STRING);
        foreach ($instanceNames as $instanceName) {
            $operationId = \bin2hex(\random_bytes(16));
            try {
                $remaining = $this->assertRetirementBudget(
                    $deadlineMonotonic,
                    0.5,
                );
                /** @var IpcControlGateway $control */
                $control = ObjectManager::getInstance(IpcControlGateway::class);
                $receipt = $control->quarantineSslServingAndWait(
                    $instanceName,
                    $operationId,
                    $reason,
                    \min(8.0, $remaining),
                );
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                $this->assertNativeQuarantineReceipt(
                    $receipt,
                    $instanceName,
                    $operationId,
                    \is_array($expectedEndpoints[$instanceName] ?? null)
                        ? $expectedEndpoints[$instanceName]
                        : null,
                );
                $proofs[$instanceName] = \hash(
                    'sha256',
                    GatewayClient::canonicalJson([
                        'reason' => $reason,
                        'endpoint_digest' => \hash(
                            'sha256',
                            GatewayClient::canonicalJson(
                                $expectedEndpoints[$instanceName] ?? [],
                            ),
                        ),
                        'receipt' => $receipt,
                    ]),
                );
            } catch (\Throwable $throwable) {
                $failures[] = 'native TLS quarantine ' . $instanceName . ': '
                    . $throwable->getMessage();
            }
        }
        \ksort($proofs, SORT_STRING);
        return ['proofs' => $proofs, 'failures' => $failures];
    }

    /**
     * A stale endpoint is zero-serving only when its Master is proven absent
     * through a bounded OS probe and every persisted listener address can be
     * exclusively rebound under the final project publication lock.
     *
     * @param array<string,mixed> $endpoint
     */
    private function proveDeadNativeTlsEndpointAbsent(
        string $instanceName,
        array $endpoint,
        ?float $deadlineMonotonic,
        ?array &$listenerGuards = null,
    ): string {
        $this->assertRetirementBudget($deadlineMonotonic, 0.05);
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \RuntimeException('Native TLS instance identity is invalid.');
        }
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        if ($masterPid > 0 && $this->processIsDefinitelyRunning(
            $masterPid,
            $deadlineMonotonic,
        )) {
            throw new \RuntimeException('The recorded Master is still running.');
        }

        $endpointHost = \strtolower(\trim(
            (string)($endpoint['host'] ?? '127.0.0.1'),
            " \t\n\r\0\x0B[]",
        ));
        if (\filter_var($endpointHost, FILTER_VALIDATE_IP) === false) {
            $endpointHost = '127.0.0.1';
        }
        $listeners = [];
        $addListener = static function (array &$targets, string $host, int $port): void {
            $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
            if (\filter_var($host, FILTER_VALIDATE_IP) === false
                || $port < 1
                || $port > 65535
            ) {
                return;
            }
            $targets[$host . "\0" . $port] = ['host' => $host, 'port' => $port];
        };
        foreach (['port', 'main_port', 'worker_port'] as $field) {
            $port = (int)($endpoint[$field] ?? 0);
            $addListener($listeners, $endpointHost, $port);
        }
        $workerBase = (int)($endpoint['worker_port'] ?? 0);
        $workerCount = \min(1024, \max(0, (int)($endpoint['count'] ?? 0)));
        if ($workerBase > 0) {
            for ($offset = 0; $offset < $workerCount; ++$offset) {
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                $addListener($listeners, $endpointHost, $workerBase + $offset);
            }
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $publicLease = \is_array($gateway['public_lease'] ?? null)
            ? $gateway['public_lease']
            : [];
        $fallbackProof = \is_array($gateway['fallback_lease_proof'] ?? null)
            ? $gateway['fallback_lease_proof']
            : [];
        $fallbackHost = (string)($fallbackProof['bind_host']
            ?? $publicLease['bind_host']
            ?? $gateway['fallback_bind_host']
            ?? $endpointHost);
        foreach (['fallback_port'] as $field) {
            $addListener($listeners, $fallbackHost, (int)($gateway[$field] ?? 0));
        }
        foreach ([$publicLease, $fallbackProof] as $lease) {
            $addListener(
                $listeners,
                (string)($lease['bind_host'] ?? $fallbackHost),
                (int)($lease['port'] ?? 0),
            );
        }
        $servingMode = \strtolower(\trim((string)($gateway['serving_mode'] ?? '')));
        if (\in_array($servingMode, ['fallback_wls', 'native_wls'], true)) {
            // public_https=443 while serving_mode=gateway belongs to the host
            // gateway and must never be mistaken for this dead project Master.
            $addListener(
                $listeners,
                $fallbackHost,
                (int)($gateway['public_https'] ?? 0),
            );
        }
        if ($listeners === []) {
            throw new \RuntimeException('The stale TLS endpoint has no listener identity.');
        }
        \ksort($listeners, SORT_STRING);
        $bound = [];
        $localGuards = [];
        try {
            foreach ($listeners as $listener) {
                $host = (string)$listener['host'];
                $port = (int)$listener['port'];
                $bindAuthority = \str_contains($host, ':') ? '[' . $host . ']' : $host;
                foreach (['tcp', 'udp'] as $transport) {
                    $this->assertRetirementBudget($deadlineMonotonic, 0.05);
                    $guardKey = $transport . "\0" . $host . "\0" . $port;
                    if ($listenerGuards !== null
                        && \is_resource($listenerGuards[$guardKey] ?? null)
                    ) {
                        // A prior observed generation may share a listener with
                        // the final generation. The already-open exclusive bind
                        // is the stronger continuous absence guard.
                        $bound[] = $transport . ':' . $host . ':' . $port;
                        continue;
                    }
                    $errno = 0;
                    $error = '';
                    $socket = @\stream_socket_server(
                        $transport . '://' . $bindAuthority . ':' . $port,
                        $errno,
                        $error,
                        $transport === 'tcp'
                            ? STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
                            : STREAM_SERVER_BIND,
                    );
                    $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                    if (!\is_resource($socket)) {
                        throw new \RuntimeException(
                            'A persisted TLS listener cannot be proven absent: '
                                . $transport . '/' . $host . ':' . $port,
                        );
                    }
                    $localGuards[$guardKey] = $socket;
                    $bound[] = $transport . ':' . $host . ':' . $port;
                }
            }
            $digest = \hash(
                'sha256',
                GatewayClient::canonicalJson([
                    'instance' => $instanceName,
                    'master_pid' => $masterPid,
                    'recorded_master_absent' => true,
                    'exclusive_listener_binds' => $bound,
                    'endpoint_digest' => \hash(
                        'sha256',
                        GatewayClient::canonicalJson($endpoint),
                    ),
                ]),
            );
            if ($listenerGuards !== null) {
                foreach ($localGuards as $guardKey => $guard) {
                    $listenerGuards[$guardKey] = $guard;
                }
                $localGuards = [];
            }
            return $digest;
        } finally {
            foreach ($localGuards as $guard) {
                if (\is_resource($guard)) {
                    @\fclose($guard);
                }
            }
        }
    }

    /**
     * Return only a definite RUNNING/EXITED observation. Command-backed
     * platforms execute inside the retirement's absolute wall-clock budget;
     * ambiguous output is an exception and therefore cannot become absence.
     */
    private function processIsDefinitelyRunning(
        int $pid,
        ?float $deadlineMonotonic,
    ): bool {
        if ($pid < 1) {
            return false;
        }
        $this->assertRetirementBudget($deadlineMonotonic, 0.01);
        if (\PHP_OS_FAMILY === 'Linux') {
            $processDirectory = '/proc/' . $pid;
            if (!\is_dir($processDirectory)) {
                return false;
            }
            $status = @\file_get_contents(
                $processDirectory . '/status',
                false,
                null,
                0,
                4097,
            );
            $this->assertRetirementBudget($deadlineMonotonic, 0.01);
            if (!\is_string($status)) {
                // The kernel still exposes the PID directory. Permission to
                // read status is not evidence that the process exited.
                return true;
            }
            return \preg_match('/^State:\s+Z/m', $status) !== 1;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $remaining = $this->assertRetirementBudget(
                $deadlineMonotonic,
                12.36,
            );
            // GatewayBoundedCommandRunner documents a maximum twelve-second
            // native Job cleanup tail. Reserve it inside the same deadline.
            $timeout = \min(2.0, $remaining - 12.25);
            $script = '$ErrorActionPreference="SilentlyContinue";'
                . '$p=Get-Process -Id ' . $pid . ';'
                . 'if($null -ne $p){[Console]::Out.Write("WELINE_RUNNING")}'
                . 'else{[Console]::Out.Write("WELINE_EXITED")}';
            $result = GatewayBoundedCommandRunner::run([
                'powershell',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                $script,
            ], $timeout);
            $this->assertRetirementBudget($deadlineMonotonic, 0.01);
            if ((int)($result['code'] ?? 1) !== 0) {
                throw new \RuntimeException(
                    'The bounded Windows process probe failed.',
                );
            }
            $token = \trim((string)($result['stdout'] ?? ''));
            if (\hash_equals('WELINE_RUNNING', $token)) {
                return true;
            }
            if (\hash_equals('WELINE_EXITED', $token)) {
                return false;
            }
            throw new \RuntimeException(
                'The bounded Windows process probe was indeterminate.',
            );
        }

        $ps = null;
        foreach (['/bin/ps', '/usr/bin/ps'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $ps = $candidate;
                break;
            }
        }
        if ($ps === null) {
            throw new \RuntimeException(
                'The bounded POSIX process probe is unavailable.',
            );
        }
        $timeout = \min(
            2.0,
            $this->assertRetirementBudget($deadlineMonotonic, 0.11),
        );
        $result = GatewayBoundedCommandRunner::run([
            $ps,
            '-p',
            (string)$pid,
            '-o',
            'state=',
        ], $timeout);
        $this->assertRetirementBudget($deadlineMonotonic, 0.01);
        $state = \trim((string)($result['stdout'] ?? ''));
        $code = (int)($result['code'] ?? 1);
        if ($code === 1 && $state === '') {
            return false;
        }
        if ($code !== 0 || \preg_match('/\A([A-Za-z])/', $state, $match) !== 1) {
            throw new \RuntimeException(
                'The bounded POSIX process probe was indeterminate.',
            );
        }
        return \strtoupper((string)$match[1]) !== 'Z';
    }

    /** Return the remaining monotonic budget or throw before new external I/O. */
    private function assertRetirementBudget(
        ?float $deadlineMonotonic,
        float $minimumSeconds,
    ): float {
        if ($deadlineMonotonic === null) {
            return 8.0;
        }
        if (!\is_finite($deadlineMonotonic)
            || !\is_finite($minimumSeconds)
            || $minimumSeconds <= 0.0
        ) {
            throw new \RuntimeException('Certificate retirement deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining < $minimumSeconds) {
            throw new \RuntimeException(
                'Certificate retirement replay exhausted its global time budget.',
            );
        }
        return $remaining;
    }

    private function withRetirementLifecycleLock(
        ProjectCertificateGenerationStore $store,
        ?float $deadlineMonotonic,
        callable $callback,
    ): mixed {
        $remaining = $this->assertRetirementBudget($deadlineMonotonic, 0.05);
        $waitTimeout = \max(0.01, \min(0.25, $remaining / 2.0));
        return $store->withCertificateLifecycleLock(
            function () use ($deadlineMonotonic, $callback): mixed {
                $this->assertRetirementBudget($deadlineMonotonic, 0.01);
                return $callback();
            },
            $waitTimeout,
        );
    }

    /**
     * Distinguish a host where the gateway was never installed from a partial
     * or damaged trust root. Only complete absence may prove "not observed";
     * any footprint without the host identity remains pending for repair.
     */
    private function hostGatewayTrustObservation(
        ?float $deadlineMonotonic,
    ): string
    {
        $this->assertRetirementBudget($deadlineMonotonic, 0.01);
        $paths = new GatewayPaths();
        $hostId = $paths->hostIdFile();
        $status = @\lstat($hostId);
        if (\is_array($status)) {
            return 'present';
        }
        if (\file_exists($hostId) || \is_link($hostId)) {
            throw new \RuntimeException(
                'Trusted WLS Gateway host identity is indeterminate or unsafe.',
            );
        }
        foreach ([
            $paths->home(),
            $paths->serviceDefinitionFile(),
            $paths->launcherFile(),
            $paths->projectSocketFile(),
            $paths->adminSocketFile(),
            $paths->controllerSocketFile(),
        ] as $footprint) {
            $this->assertRetirementBudget($deadlineMonotonic, 0.01);
            $footprintStatus = @\lstat($footprint);
            if (\is_array($footprintStatus)
                || \file_exists($footprint)
                || \is_link($footprint)
            ) {
                throw new \RuntimeException(
                    'Trusted WLS Gateway footprint exists without its host identity.',
                );
            }
        }
        $this->assertRetirementBudget($deadlineMonotonic, 0.01);
        return 'absent';
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed>|null $expectedEndpoint
     */
    private function assertNativeQuarantineReceipt(
        array $receipt,
        string $instanceName,
        string $operationId,
        ?array $expectedEndpoint = null,
    ): void {
        $data = \is_array($receipt['data'] ?? null) ? $receipt['data'] : [];
        $eligible = $data['eligible_workers'] ?? null;
        $contained = $data['contained_workers'] ?? null;
        $remaining = $data['remaining_workers'] ?? null;
        $failures = $data['failures'] ?? null;
        $elapsed = $data['elapsed_ms'] ?? null;
        $valid = ($receipt['success'] ?? false) === true
            && ($data['success'] ?? false) === true
            && ($data['async'] ?? null) === false
            && \hash_equals($operationId, (string)($data['operation_id'] ?? ''))
            && \is_int($data['master_pid'] ?? null)
            && (int)$data['master_pid'] > 0
            && \is_int($data['master_epoch'] ?? null)
            && (int)$data['master_epoch'] > 0
            && \is_int($data['instance_generation'] ?? null)
            && (int)$data['instance_generation'] > 0
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($data['launch_id'] ?? ''),
            ) === 1
            && ($data['quarantined'] ?? false) === true
            && ($data['zero_serving'] ?? false) === true
            && \is_array($eligible)
            && \array_is_list($eligible)
            && \is_array($contained)
            && \array_is_list($contained)
            && \is_array($remaining)
            && \array_is_list($remaining)
            && $remaining === []
            && \is_array($failures)
            && \array_is_list($failures)
            && $failures === []
            && (\is_int($elapsed) || \is_float($elapsed))
            && \is_finite((float)$elapsed)
            && (float)$elapsed >= 0.0
            && (float)$elapsed <= 60000.0;
        if ($valid && $expectedEndpoint !== null) {
            $expectedFence = GatewayRuntimeServingProjection::endpointFence(
                $expectedEndpoint,
            );
            $expectedGateway = \is_array($expectedEndpoint['gateway'] ?? null)
                ? $expectedEndpoint['gateway']
                : [];
            $valid = $expectedFence !== null
                && \hash_equals(
                    $instanceName,
                    (string)($expectedGateway['instance_id'] ?? ''),
                )
                && (int)$data['master_pid'] === (int)$expectedFence['master_pid']
                && (int)$data['master_epoch'] === (int)$expectedFence['master_epoch']
                && (int)$data['instance_generation']
                    === (int)$expectedFence['instance_generation']
                && \hash_equals(
                    (string)$expectedFence['launch_id'],
                    (string)($data['launch_id'] ?? ''),
                );
        }
        if ($valid) {
            $expected = [];
            foreach ($eligible as $identity) {
                $key = $this->nativeQuarantineIdentityKey($identity);
                if ($key === null || isset($expected[$key])) {
                    $valid = false;
                    break;
                }
                $expected[$key] = true;
            }
            if ($valid) {
                foreach ($contained as $identity) {
                    $key = $this->nativeQuarantineIdentityKey($identity);
                    if ($key === null
                        || ($identity['released'] ?? false) !== true
                        || !isset($expected[$key])
                    ) {
                        $valid = false;
                        break;
                    }
                    unset($expected[$key]);
                }
                $valid = $valid && $expected === [];
            }
        }
        if ($valid) {
            return;
        }
        throw new \RuntimeException(
            'Exact zero-serving TLS quarantine acknowledgement is incomplete for '
                . $instanceName . '.',
        );
    }

    private function nativeQuarantineIdentityKey(mixed $identity): ?string
    {
        if (!\is_array($identity)
            || !\is_string($identity['role'] ?? null)
            || \preg_match(
                '/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D',
                $identity['role'],
            ) !== 1
            || !\is_int($identity['instance_id'] ?? null)
            || (int)$identity['instance_id'] < 1
            || !\is_string($identity['slot_id'] ?? null)
            || $identity['slot_id'] === ''
            || \strlen($identity['slot_id']) > 128
            || \preg_match('/[\x00-\x1f\x7f]/D', $identity['slot_id']) === 1
            || !\is_string($identity['lease_id'] ?? null)
            || $identity['lease_id'] === ''
            || \strlen($identity['lease_id']) > 128
            || \preg_match('/[\x00-\x1f\x7f]/D', $identity['lease_id']) === 1
            || !\is_int($identity['generation'] ?? null)
            || (int)$identity['generation'] < 1
            || !\is_int($identity['pid'] ?? null)
            || (int)$identity['pid'] < 1
        ) {
            return null;
        }
        return \implode("\0", [
            $identity['role'],
            (string)$identity['instance_id'],
            $identity['slot_id'],
            $identity['lease_id'],
            (string)$identity['generation'],
            (string)$identity['pid'],
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @return array{generation:int,digest:string,route_count:int,path:string}
     */
    private function manifestPublication(array $result): array
    {
        $generation = (int)($result['serving_manifest_generation']
            ?? $result['generation']
            ?? 0);
        $digest = \strtolower(\trim((string)(
            $result['serving_manifest_digest'] ?? $result['digest'] ?? ''
        )));
        $routeCountValue = $result['serving_manifest_route_count']
            ?? $result['route_count']
            ?? null;
        $path = \trim((string)(
            $result['serving_manifest_path'] ?? $result['path'] ?? ''
        ));
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\is_int($routeCountValue)
            || $routeCountValue < 0
            || $routeCountValue > ProjectServingManifestStore::MAX_ROUTES
            || $path === ''
            || \str_contains($path, "\0")
        ) {
            throw new \RuntimeException(
                'Serving manifest publication returned no exact generation binding.',
            );
        }
        return [
            'generation' => $generation,
            'digest' => $digest,
            'route_count' => $routeCountValue,
            'path' => $path,
        ];
    }

    /** @param array<string,mixed> $native */
    private function assertNativeReloadReceipt(
        array $native,
        string $instanceName,
        string $operationId,
        int $manifestGeneration,
        string $manifestDigest,
        int $tlsRouteCount,
    ): void {
        $expectedMode = $tlsRouteCount === 0 ? 'neutral' : 'routes';
        $expectedTlsState = $tlsRouteCount === 0 ? 'disabled' : 'active';
        $eligible = $native['eligible_workers'] ?? null;
        $acked = $native['acked_workers'] ?? null;
        $failed = $native['failed_workers'] ?? null;
        $expectedRetiredCount = $native['expected_retired_context_count'] ?? null;
        $expectedRetiredDigest = \strtolower(\trim((string)(
            $native['expected_retired_context_digest'] ?? ''
        )));
        $failedByInstance = (array)($native['failed_by_instance'] ?? []);
        $skippedByInstance = (array)($native['skipped_by_instance'] ?? []);
        $valid = ($native['success'] ?? false) === true
            && $failedByInstance === []
            && $skippedByInstance === []
            && \hash_equals($operationId, (string)($native['operation_id'] ?? ''))
            && (int)($native['expected_manifest_generation'] ?? 0)
                === $manifestGeneration
            && \hash_equals(
                $manifestDigest,
                (string)($native['expected_manifest_digest'] ?? ''),
            )
            && (int)($native['expected_tls_route_count'] ?? -1) === $tlsRouteCount
            && \hash_equals(
                $expectedMode,
                (string)($native['expected_serving_mode'] ?? ''),
            )
            && \is_array($eligible)
            && \array_is_list($eligible)
            && $eligible !== []
            && \is_array($acked)
            && \array_is_list($acked)
            && \count($acked) === \count($eligible)
            && \is_array($failed)
            && \array_is_list($failed)
            && $failed === []
            && \is_int($expectedRetiredCount)
            && $expectedRetiredCount >= 0
            && \preg_match('/\A[a-f0-9]{64}\z/D', $expectedRetiredDigest) === 1;
        if ($valid) {
            $expectedWorkers = [];
            foreach ($eligible as $identity) {
                if (!\is_array($identity)) {
                    $valid = false;
                    break;
                }
                $clientId = $identity['client_id'] ?? null;
                if (!\is_int($clientId) || $clientId < 1 || isset($expectedWorkers[$clientId])) {
                    $valid = false;
                    break;
                }
                $expectedWorkers[$clientId] = $identity;
            }
        }
        if ($valid) {
            foreach ($acked as $receipt) {
                $clientId = \is_array($receipt) ? ($receipt['client_id'] ?? null) : null;
                $identity = \is_int($clientId) ? ($expectedWorkers[$clientId] ?? null) : null;
                if (!\is_array($receipt)
                    || !\is_array($identity)
                    || (int)($receipt['worker_id'] ?? 0)
                        !== (int)($identity['instance_id'] ?? -1)
                    || !\hash_equals(
                        (string)($identity['role'] ?? ''),
                        (string)($receipt['role'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)($identity['slot_id'] ?? ''),
                        (string)($receipt['slot_id'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)($identity['lease_id'] ?? ''),
                        (string)($receipt['lease_id'] ?? ''),
                    )
                    || (int)($receipt['generation'] ?? 0)
                        !== (int)($identity['generation'] ?? -1)
                    || (int)($receipt['pid'] ?? 0)
                        !== (int)($identity['pid'] ?? -1)
                    || (int)($receipt['applied_manifest_generation'] ?? 0)
                        !== $manifestGeneration
                    || !\hash_equals(
                        $manifestDigest,
                        (string)($receipt['applied_manifest_digest'] ?? ''),
                    )
                    || (int)($receipt['applied_tls_route_count'] ?? -1)
                        !== $tlsRouteCount
                    || !\hash_equals(
                        $expectedMode,
                        (string)($receipt['serving_mode'] ?? ''),
                    )
                    || !\hash_equals(
                        $expectedTlsState,
                        (string)($receipt['tls_context_state'] ?? ''),
                    )
                    || !\is_int($receipt['retired_context_count'] ?? null)
                    || (int)$receipt['retired_context_count'] !== $expectedRetiredCount
                    || !\hash_equals(
                        $expectedRetiredDigest,
                        \strtolower(\trim((string)(
                            $receipt['retired_context_digest'] ?? ''
                        ))),
                    )
                ) {
                    $valid = false;
                    break;
                }
                unset($expectedWorkers[$clientId]);
            }
            $valid = $valid && $expectedWorkers === [];
        }
        if ($valid) {
            return;
        }
        $messages = [];
        foreach ($failedByInstance + $skippedByInstance as $instance => $message) {
            $messages[] = (string)$instance . ': ' . (string)$message;
        }
        throw new \RuntimeException(
            'Exact TLS reload acknowledgement is incomplete for ' . $instanceName
                . ($messages === [] ? '.' : ': ' . \implode('; ', $messages)),
        );
    }

    /**
     * Compare the immutable Master generation identity, not only the endpoint
     * map key. Reusing an instance name cannot transfer retirement authority
     * from an old process generation to a replacement process.
     *
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $current
     */
    private function sameNativeRuntimeFence(array $expected, array $current): bool
    {
        $expectedFence = GatewayRuntimeServingProjection::endpointFence($expected);
        $currentFence = GatewayRuntimeServingProjection::endpointFence($current);
        if ($expectedFence === null || $currentFence === null) {
            return false;
        }
        $expectedGateway = \is_array($expected['gateway'] ?? null)
            ? $expected['gateway']
            : [];
        $currentGateway = \is_array($current['gateway'] ?? null)
            ? $current['gateway']
            : [];
        return (int)$expectedFence['master_pid'] === (int)$currentFence['master_pid']
            && (int)$expectedFence['master_epoch'] === (int)$currentFence['master_epoch']
            && (int)$expectedFence['instance_generation']
                === (int)$currentFence['instance_generation']
            && \hash_equals(
                (string)$expectedFence['launch_id'],
                (string)$currentFence['launch_id'],
            )
            && \hash_equals(
                (string)($expectedGateway['instance_id'] ?? ''),
                (string)($currentGateway['instance_id'] ?? ''),
            )
            && \hash_equals(
                (string)($expectedGateway['project_uuid'] ?? ''),
                (string)($currentGateway['project_uuid'] ?? ''),
            );
    }

    /** @param array<string,mixed> $endpoint */
    private function nativeReloadRequired(array $endpoint): bool
    {
        $adapterIsWls = \hash_equals(
            EdgeAdapterInterface::NAME_WLS,
            \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
        );
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $requestedMode = \strtolower(\trim((string)(
            $gateway['requested_mode'] ?? $gateway['mode'] ?? ''
        )));
        $fallbackState = \strtoupper(\trim((string)(
            $gateway['fallback_state'] ?? ''
        )));
        $nativeEdge = \is_array($gateway['native_edge'] ?? null)
            ? $gateway['native_edge']
            : [];
        $nativeState = \strtoupper(\trim((string)(
            $nativeEdge['state'] ?? ''
        )));

        $explicit = GatewayRuntimeServingProjection::explicitPureWlsServingEndpoint(
            $endpoint,
        );
        if (\is_array($explicit) && ($explicit['https'] ?? false) === true) {
            return true;
        }
        if (GatewayRuntimeServingProjection::fallbackWlsIsServing($endpoint)) {
            return true;
        }
        if (\in_array($fallbackState, ['DEGRADED_WLS', 'NATIVE_EDGE_DRAINING'], true)) {
            return true;
        }

        // WLS TLS intent remains a containment target even for an old endpoint
        // that predates requested_mode/runtime observations. The sole safe
        // exception is an auto edge whose current Master publishes both a fresh
        // authenticated gateway projection and the exact DRAINED native state.
        // NATIVE_EDGE_STANDBY is not drained: the transition publisher uses it
        // before drain convergence.
        if ($adapterIsWls && ($endpoint['ssl_enabled'] ?? false) === true) {
            if (\hash_equals(GatewayStartupDecision::MODE_AUTO, $requestedMode)
                && GatewayRuntimeServingProjection::gatewayIsServing($endpoint)
                && \hash_equals('GATEWAY_ACTIVE', $fallbackState)
                && \hash_equals('DRAINED', $nativeState)
            ) {
                return false;
            }
            return true;
        }

        $runtime = GatewayStartupRuntimeView::resolve($endpoint);
        return \hash_equals(
            GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS,
            (string)($runtime['source'] ?? ''),
        ) && ($endpoint['ssl_enabled'] ?? false) === true && \in_array(
            \strtoupper((string)($runtime['native_edge_state'] ?? '')),
            ['ACTIVE', 'DRAINING'],
            true,
        );
    }
}
