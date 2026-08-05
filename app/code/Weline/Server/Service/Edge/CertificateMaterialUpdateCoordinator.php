<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;
use Weline\Server\Service\Edge\Gateway\GatewayEmergencyRevocationClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayProjectEndpointReader;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
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
    ): void
    {
        $revokedDomains = $this->validatedRevocationIntent($domain, $paths);
        unset($paths);
        $domains = $domain !== '' ? [$domain] : [];
        $failures = [];
        $revocationCommitted = $revokedDomains !== [];

        $endpoints = (new GatewayProjectEndpointReader())->all();
        $masterLeases = new MasterLeaseManager();
        $legacyManaged = false;
        $liveGatewayParticipants = [];
        $liveProjectInstances = [];
        $nativeReloadRequired = [];
        $nativeContainmentTargets = [];
        $preflightFailures = [];
        $gatewayRevocationAcknowledged = false;
        foreach ($endpoints as $instanceName => $endpoint) {
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
                $legacyLease = $masterLeases->validateRunningLease(
                    MasterLeaseManager::pathForInstance($instanceName),
                    expectedInstance: $instanceName,
                    expectedMasterPid: $legacyMasterPid,
                    expectedEpoch: $legacyMasterEpoch,
                    requireManagedName: true,
                );
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
            $potentialNativeTls = $revocationCommitted
                && \hash_equals(
                    EdgeAdapterInterface::NAME_WLS,
                    \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
                )
                && ($endpoint['ssl_enabled'] ?? false) === true
                && $rawMasterPid > 0
                && Processer::isRunningByPid($rawMasterPid);
            if ($potentialNativeTls) {
                $nativeContainmentTargets[$instanceName] = true;
            }
            $gatewayParticipant = GatewayRuntimeServingProjection::participatesInGateway(
                $endpoint,
            );
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
            $leaseValidation = $masterLeases->validateRunningLease(
                MasterLeaseManager::pathForInstance($instanceName),
                expectedInstance: $instanceName,
                expectedMasterPid: (int)$fence['master_pid'],
                expectedEpoch: (int)$fence['master_epoch'],
                requireManagedName: true,
            );
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
                        &$gatewayIntentErrors,
                        &$gatewayRevocationAcknowledged,
                    ): void {
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
                                    $registration = $buildGateway();
                                    $publication = $this->manifestPublication($registration);
                                    // Enqueue alone is not convergence. Wait for
                                    // authenticated host publication and exact
                                    // own-status acknowledgement while every
                                    // project serving lock remains held.
                                    (new GatewayHostManager())
                                        ->submitBuiltRegistration($registration);
                                    if ($revocationCommitted) {
                                        $gatewayRevocationAcknowledged = true;
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
                            $reloadLease = $masterLeases->validateRunningLease(
                                MasterLeaseManager::pathForInstance($instanceName),
                                expectedInstance: $instanceName,
                                expectedMasterPid: (int)$authorizedFence['master_pid'],
                                expectedEpoch: (int)$authorizedFence['master_epoch'],
                                requireManagedName: true,
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
                            $native = $nativeControl->reloadSslCertAndWait(
                                $domains,
                                $instanceName,
                                $operationId,
                                $manifestGeneration,
                                $manifestDigest,
                                $tlsRouteCount,
                                8.0,
                            );
                            $this->assertNativeReloadReceipt(
                                $native,
                                $instanceName,
                                $operationId,
                                $manifestGeneration,
                                $manifestDigest,
                                $tlsRouteCount,
                            );
                        }
                    },
                );
            } catch (\Throwable $throwable) {
                $failures[] = 'project serving manifest/native TLS transaction: '
                    . $throwable->getMessage();
            }
            foreach ($gatewayIntentErrors as $gatewayIntentError) {
                $failures[] = 'gateway renewal intent ' . $gatewayIntentError;
            }
        }

        foreach ($preflightFailures as $preflightFailure) {
            $failures[] = $preflightFailure;
        }
        if ($revocationCommitted
            && !$gatewayRevocationAcknowledged
            && $liveGatewayParticipants !== []
        ) {
            $generationStore = new ProjectCertificateGenerationStore();
            foreach ($revokedDomains as $revokedDomain => $revokedGeneration) {
                try {
                    $tombstone = $generationStore->disabled($revokedDomain);
                    if (!\is_array($tombstone)
                        || (int)($tombstone['generation'] ?? 0) !== $revokedGeneration
                    ) {
                        throw new \RuntimeException(
                            'Durable project revocation tombstone changed before guardian fallback.',
                        );
                    }
                    (new GatewayEmergencyRevocationClient())->revoke([
                        'domain' => $revokedDomain,
                        'generation' => $revokedGeneration,
                        'source_digest' => (string)($tombstone['source_digest'] ?? ''),
                    ]);
                } catch (\Throwable $throwable) {
                    $failures[] = 'host gateway emergency revocation ' . $revokedDomain
                        . ': ' . $throwable->getMessage();
                }
            }
        }
        if ($revocationCommitted && $failures !== [] && $nativeContainmentTargets !== []) {
            foreach ($this->quarantineNativeTlsFaces(
                \array_keys($nativeContainmentTargets),
                $revokedDomains,
            ) as $containmentFailure) {
                $failures[] = $containmentFailure;
            }
        }

        $legacyCompatibilityProbe = $sourceAdapter === EdgeAdapterInterface::NAME_NGINX
            && $liveProjectInstances === []
            && !$legacyManaged;
        if ($legacyManaged || $legacyCompatibilityProbe) {
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
                $failures[] = 'legacy managed Nginx: ' . $throwable->getMessage();
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
     * @return array<string,int> domain => disabled generation
     */
    private function validatedRevocationIntent(string $domain, array $paths): array
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
        if ($domain === ''
            || !\hash_equals($intentDomain, \strtolower(\rtrim(\trim($domain), '.')))
            || !\is_int($generation)
            || $generation < 1
            || !\hash_equals(
                \hash(
                    'sha256',
                    "wls-disabled-certificate\0" . $intentDomain . "\0" . $generation,
                ),
                $sourceDigest,
            )
        ) {
            throw new \RuntimeException(
                'Certificate revocation intent is not bound to its exact tombstone.',
            );
        }
        $disabled = (new ProjectCertificateGenerationStore())->disabled($intentDomain);
        if (!\is_array($disabled)
            || (int)($disabled['generation'] ?? 0) !== $generation
            || !\hash_equals(
                $sourceDigest,
                (string)($disabled['source_digest'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Certificate revocation intent no longer matches durable project authority.',
            );
        }
        return [$intentDomain => $generation];
    }

    /**
     * @param list<string> $instanceNames
     * @param array<string,int> $revokedDomains
     * @return list<string>
     */
    private function quarantineNativeTlsFaces(
        array $instanceNames,
        array $revokedDomains,
    ): array {
        $failures = [];
        $reason = 'certificate-revocation:' . \substr(\hash(
            'sha256',
            \json_encode($revokedDomains, JSON_THROW_ON_ERROR),
        ), 0, 32);
        \sort($instanceNames, SORT_STRING);
        foreach ($instanceNames as $instanceName) {
            $operationId = \bin2hex(\random_bytes(16));
            try {
                /** @var IpcControlGateway $control */
                $control = ObjectManager::getInstance(IpcControlGateway::class);
                $receipt = $control->quarantineSslServingAndWait(
                    $instanceName,
                    $operationId,
                    $reason,
                    8.0,
                );
                $this->assertNativeQuarantineReceipt(
                    $receipt,
                    $instanceName,
                    $operationId,
                );
            } catch (\Throwable $throwable) {
                $failures[] = 'native TLS quarantine ' . $instanceName . ': '
                    . $throwable->getMessage();
            }
        }
        return $failures;
    }

    /** @param array<string,mixed> $receipt */
    private function assertNativeQuarantineReceipt(
        array $receipt,
        string $instanceName,
        string $operationId,
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

    /** @param array<string,mixed> $endpoint */
    private function nativeReloadRequired(array $endpoint): bool
    {
        if (!\hash_equals(
            EdgeAdapterInterface::NAME_WLS,
            \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
        ) || ($endpoint['ssl_enabled'] ?? false) !== true) {
            return false;
        }
        $explicit = GatewayRuntimeServingProjection::explicitPureWlsServingEndpoint(
            $endpoint,
        );
        if (\is_array($explicit) && ($explicit['https'] ?? false) === true) {
            return true;
        }
        if (GatewayRuntimeServingProjection::fallbackWlsIsServing($endpoint)) {
            return true;
        }
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        if (GatewayRuntimeServingProjection::gatewayIsServing($endpoint)
            && \hash_equals(
                'NATIVE_EDGE_DRAINING',
                \strtoupper(\trim((string)($gateway['fallback_state'] ?? ''))),
            )
        ) {
            return true;
        }
        $runtime = GatewayStartupRuntimeView::resolve($endpoint);
        return \hash_equals(
            GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS,
            (string)($runtime['source'] ?? ''),
        ) && \in_array(
            \strtoupper((string)($runtime['native_edge_state'] ?? '')),
            ['ACTIVE', 'DRAINING'],
            true,
        );
    }
}
