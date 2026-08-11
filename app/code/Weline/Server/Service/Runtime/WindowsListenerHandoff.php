<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Lossless Windows listener transfer using the ext-sockets WSAPROTOCOL API.
 *
 * Windows does not inherit arbitrary socket handles through the isolated WMI
 * launcher. The current owner therefore keeps its listening socket open while
 * exporting a duplicate that is cryptographically scoped by Winsock to one
 * already-created target PID. The small project-local envelope only carries
 * that target-bound identifier and immutable WLS lease/launch fences.
 */
final class WindowsListenerHandoff
{
    public const TRANSPORT = 'windows_wsaprotocol_info';

    private const SCHEMA_VERSION = 1;
    private const MAX_ENVELOPE_BYTES = 24_576;
    private const HANDOFF_TIMEOUT_SECONDS = 20.0;
    private const ENVELOPE_LIFETIME_SECONDS = 60;
    private const POLL_INTERVAL_MICROSECONDS = 10_000;
    private const PENDING_REGISTRY_SCHEMA_VERSION = 2;
    private const MAX_PENDING_EXPORTS = 256;
    private const MAX_PENDING_REGISTRY_BYTES = 4_194_304;
    private const PENDING_REGISTRY_FILE = '.wls-listener-handoff-pending.json';
    private const PENDING_REGISTRY_LOCK = '.wls-listener-handoff-pending.lock';
    private const EXPORT_MUTEX_WAIT_MILLISECONDS = 20_000;

    /** @var array<string,array{socket:\Socket,stream:mixed,intent:array<string,mixed>}> */
    private static array $masterSources = [];
    private static string $primaryIntentDigest = '';
    private static ?WindowsListenerHandoffMutexGuard $exportMutexGuard = null;
    /** @var array<string,true> */
    private static array $ownedExportProtocols = [];
    private static int $exportMutexSourcePid = 0;
    private static string $exportMutexSourceBirth = '';
    private static string $exportMutexSourcePidNamespaceId = '';
    private static string $exportMutexRecoveryState = 'NONE';

    /**
     * @param array<string,mixed> $lease
     * @return array<string,mixed>
     */
    public static function createIntent(
        string $wlsInstance,
        string $launchId,
        array $lease,
    ): array {
        self::assertWindowsCapability();
        $wlsInstance = self::boundedInstance($wlsInstance);
        $launchId = self::hex($launchId, 32, 'Master launch identity');
        $leaseId = self::hex(
            (string)($lease['lease_id'] ?? ''),
            32,
            'Windows listener lease identity',
        );
        $leaseInstance = self::boundedInstance((string)($lease['instance'] ?? ''));
        $host = self::normalizeHost((string)($lease['bind_host'] ?? ''));
        $port = self::port($lease['port'] ?? 0);
        if ((int)($lease['schema_version'] ?? 0)
                !== GatewayPortLeaseAllocator::SCHEMA_VERSION
            || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Windows startup listener requires one RESERVED schema-6 lease.'
            );
        }

        $handoffId = \bin2hex(\random_bytes(16));
        $intent = [
            'schema_version' => self::SCHEMA_VERSION,
            'transport' => self::TRANSPORT,
            'continuous_ownership' => true,
            'handoff_id' => $handoffId,
            'lease_id' => $leaseId,
            'instance' => $leaseInstance,
            'wls_instance' => $wlsInstance,
            'bind_host' => $host,
            'port' => $port,
            'launch_id' => $launchId,
            'master_path' => self::masterPath($handoffId),
        ];
        $intent['intent_digest'] = self::digest($intent);

        return $intent;
    }

    /**
     * Validate the endpoint copy and require an exact match with one and only
     * one schema-6 startup lease.
     *
     * @param array<string,mixed> $gateway
     * @return array<string,mixed>|null
     */
    public static function validatePersistedIntent(
        string $wlsInstance,
        int $port,
        array $gateway,
    ): ?array {
        $raw = $gateway['startup_listener_handoff'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!\is_array($raw)) {
            throw new \RuntimeException('Windows listener handoff metadata is invalid.');
        }
        $intent = self::normalizeIntent($raw);
        if (!\hash_equals(self::boundedInstance($wlsInstance), $intent['wls_instance'])
            || $port !== $intent['port']
            || !\hash_equals(
                (string)($gateway['launch_id'] ?? ''),
                $intent['launch_id'],
            )
        ) {
            throw new \RuntimeException(
                'Windows listener handoff does not match the current Master endpoint.'
            );
        }

        $matches = 0;
        foreach (['public_lease', 'backend_lease'] as $field) {
            $lease = $gateway[$field] ?? null;
            if (!\is_array($lease) || $lease === []) {
                continue;
            }
            if ((int)($lease['schema_version'] ?? 0)
                    === GatewayPortLeaseAllocator::SCHEMA_VERSION
                && \hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                && \hash_equals($intent['lease_id'], (string)($lease['lease_id'] ?? ''))
                && \hash_equals($intent['instance'], (string)($lease['instance'] ?? ''))
                && \hash_equals($intent['bind_host'], (string)($lease['bind_host'] ?? ''))
                && $intent['port'] === (int)($lease['port'] ?? 0)
            ) {
                ++$matches;
            }
        }
        if ($matches !== 1
            || !\in_array($intent['instance'], [
                $wlsInstance,
                GatewayLeaseIdentity::forRole(
                    $wlsInstance,
                    GatewayLeaseIdentity::ROLE_INITIAL_BACKEND,
                ),
            ], true)
        ) {
            throw new \RuntimeException(
                'Windows listener handoff does not match exactly one startup lease.'
            );
        }

        return $intent;
    }

    /** @param resource $stream @param array<string,mixed> $intent */
    public static function publishStreamToMaster(
        mixed $stream,
        array $intent,
        int $masterPid,
        ?float $deadlineMonotonic = null,
    ): void {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $runtime = new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        if (!\is_resource($stream) || $masterPid <= 0) {
            throw new \RuntimeException(
                'Windows Master listener handoff requires a retained stream and target PID.'
            );
        }
        $socket = @\socket_import_stream($stream);
        if ($socket === false) {
            throw new \RuntimeException(
                'Windows startup listener could not be imported into ext-sockets.'
            );
        }
        self::assertListeningSocket(
            $socket,
            $intent['bind_host'],
            $intent['port'],
        );
        self::publishEnvelope(
            $socket,
            $intent['master_path'],
            $intent,
            'start_to_master',
            $masterPid,
            $intent['launch_id'],
            'master',
            0,
            $runtime,
            $deadlineMonotonic,
        );
    }

    /** @param resource $stream @param array<string,mixed> $intent */
    public static function installCurrentProcessSource(
        mixed $stream,
        array $intent,
        ?float $deadlineMonotonic = null,
    ): void {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $runtime = new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        if (!\is_resource($stream)) {
            throw new \RuntimeException(
                'Foreground Windows Master has no retained listener stream.'
            );
        }
        $socket = @\socket_import_stream($stream);
        if ($socket === false) {
            throw new \RuntimeException(
                'Foreground Windows listener could not be imported into ext-sockets.'
            );
        }
        self::assertListeningSocket($socket, $intent['bind_host'], $intent['port']);
        self::installMasterSocket(
            $socket,
            $intent,
            $stream,
            $runtime,
            $deadlineMonotonic,
        );
    }

    /** @param array<string,mixed> $intent */
    public static function awaitInstallForMaster(
        array $intent,
        ?float $deadlineMonotonic = null,
    ): void
    {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $runtime = new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $imported = self::awaitEnvelope(
            $intent['master_path'],
            $intent,
            'start_to_master',
            (int)\getmypid(),
            $intent['launch_id'],
            'master',
            0,
            $runtime,
            $deadlineMonotonic,
        );
        self::installMasterSocket(
            $imported['socket'],
            $intent,
            null,
            $runtime,
            $deadlineMonotonic,
        );
    }

    public static function hasMasterSocket(
        ?string $intentDigest = null,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        $runtime = new WindowsListenerHandoffRuntime();
        self::sweepPendingExportsBestEffort(
            runtime: $runtime,
            deadlineMonotonic: self::operationDeadline(
                $deadlineMonotonic,
                $runtime,
            ),
        );
        $intentDigest = $intentDigest !== null
            ? \strtolower(\trim($intentDigest))
            : self::$primaryIntentDigest;
        return $intentDigest !== ''
            && (self::$masterSources[$intentDigest]['socket'] ?? null) instanceof \Socket;
    }

    /** @return array<string,mixed> */
    public static function masterIntent(
        ?string $intentDigest = null,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $runtime = new WindowsListenerHandoffRuntime();
        self::sweepPendingExportsBestEffort(
            runtime: $runtime,
            deadlineMonotonic: self::operationDeadline(
                $deadlineMonotonic,
                $runtime,
            ),
        );
        $intentDigest = $intentDigest !== null
            ? \strtolower(\trim($intentDigest))
            : self::$primaryIntentDigest;
        $intent = $intentDigest !== ''
            ? (self::$masterSources[$intentDigest]['intent'] ?? [])
            : [];
        return \is_array($intent) ? $intent : [];
    }

    /** @param array<string,mixed> $intent */
    public static function childPath(
        array $intent,
        string $launchId,
        string $slotId,
        int $generation,
    ): string {
        $intent = self::normalizeIntent($intent);
        $launchId = self::hex($launchId, 32, 'Dispatcher launch identity');
        $slotId = self::slotId($slotId);
        if ($generation <= 0) {
            throw new \RuntimeException('Dispatcher listener handoff generation is invalid.');
        }
        $suffix = \substr(\hash('sha256', \implode('|', [
            $intent['handoff_id'],
            $launchId,
            $slotId,
            (string)$generation,
        ])), 0, 24);

        return self::handoffDirectory()
            . DIRECTORY_SEPARATOR
            . '.wls-listener-handoff-'
            . $intent['handoff_id']
            . '-'
            . $suffix
            . '.json';
    }

    /** @param array<string,mixed> $intent */
    public static function publishMasterSocketToChild(
        array $intent,
        string $path,
        int $targetPid,
        string $launchId,
        string $slotId,
        int $generation,
        ?float $deadlineMonotonic = null,
    ): array {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $runtime = new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $intentDigest = (string)$intent['intent_digest'];
        if (!self::hasMasterSocket($intentDigest, $deadlineMonotonic)) {
            throw new \RuntimeException(
                'Windows Master no longer owns the listener needed for Dispatcher recovery.'
            );
        }
        $expectedPath = self::childPath($intent, $launchId, $slotId, $generation);
        if (!self::samePath($path, $expectedPath) || $targetPid <= 0) {
            throw new \RuntimeException('Windows Dispatcher listener handoff target is invalid.');
        }
        return self::publishEnvelope(
            self::$masterSources[$intentDigest]['socket'],
            $expectedPath,
            $intent,
            'master_to_dispatcher',
            $targetPid,
            $launchId,
            $slotId,
            $generation,
            $runtime,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array{
     *   handoff_id:string,intent_digest:string,wls_instance:string,lease_id:string,
     *   bind_host:string,port:int,launch_id:string,slot_id:string,generation:int
     * } $expected
     * @return array{socket:\Socket,proof:array<string,mixed>}
     */
    public static function awaitChildSocket(
        string $path,
        array $expected,
        ?float $deadlineMonotonic = null,
    ): array
    {
        self::assertWindowsCapability();
        $runtime = new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $intent = [
            'schema_version' => self::SCHEMA_VERSION,
            'transport' => self::TRANSPORT,
            'continuous_ownership' => true,
            'handoff_id' => self::hex($expected['handoff_id'] ?? '', 32, 'handoff identity'),
            'lease_id' => self::hex($expected['lease_id'] ?? '', 32, 'host lease identity'),
            'instance' => self::boundedInstance((string)($expected['lease_instance'] ?? $expected['wls_instance'] ?? '')),
            'wls_instance' => self::boundedInstance((string)($expected['wls_instance'] ?? '')),
            'bind_host' => self::normalizeHost((string)($expected['bind_host'] ?? '')),
            'port' => self::port($expected['port'] ?? 0),
            'launch_id' => self::hex(
                (string)($expected['master_launch_id'] ?? $expected['launch_id'] ?? ''),
                32,
                'Master launch identity',
            ),
            'master_path' => self::masterPath(
                self::hex($expected['handoff_id'] ?? '', 32, 'handoff identity'),
            ),
            'intent_digest' => self::hex(
                (string)($expected['intent_digest'] ?? ''),
                64,
                'handoff intent digest',
            ),
        ];
        $launchId = self::hex(
            (string)($expected['launch_id'] ?? ''),
            32,
            'Dispatcher launch identity',
        );
        $slotId = self::slotId((string)($expected['slot_id'] ?? ''));
        $generation = (int)($expected['generation'] ?? 0);
        $expectedPath = self::childPath($intent, $launchId, $slotId, $generation);
        if (!self::samePath($path, $expectedPath)) {
            throw new \RuntimeException('Windows Dispatcher handoff path is not launch-bound.');
        }
        $imported = self::awaitEnvelope(
            $expectedPath,
            $intent,
            'master_to_dispatcher',
            (int)\getmypid(),
            $launchId,
            $slotId,
            $generation,
            $runtime,
            $deadlineMonotonic,
        );

        return [
            'socket' => $imported['socket'],
            'proof' => [
                'bound' => true,
                'mode' => self::TRANSPORT,
                'inherited' => true,
                'continuous_ownership' => true,
                'host' => $intent['bind_host'],
                'port' => $intent['port'],
                'handoff_id' => $intent['handoff_id'],
                'intent_digest' => $intent['intent_digest'],
                'host_lease_id' => $intent['lease_id'],
                'target_pid' => (int)\getmypid(),
                'target_process_birth' => (string)($imported['envelope']['target_process_birth'] ?? ''),
                'source_pid' => (int)($imported['envelope']['source_pid'] ?? 0),
                'source_process_birth' => (string)($imported['envelope']['source_process_birth'] ?? ''),
                'adoption_nonce' => (string)($imported['envelope']['adoption_nonce'] ?? ''),
                'envelope_digest' => (string)($imported['envelope']['payload_digest'] ?? ''),
                'master_launch_id' => $intent['launch_id'],
                'launch_id' => $launchId,
                'slot_id' => $slotId,
                'generation' => $generation,
            ],
        ];
    }

    public static function closeMasterSocket(?float $deadlineMonotonic = null): void
    {
        $runtime = new WindowsListenerHandoffRuntime();
        self::sweepPendingExportsBestEffort(
            runtime: $runtime,
            deadlineMonotonic: self::operationDeadline(
                $deadlineMonotonic,
                $runtime,
            ),
        );
        foreach (\array_keys(self::$masterSources) as $intentDigest) {
            self::releaseMasterSource($intentDigest);
        }
        self::$primaryIntentDigest = '';
    }

    public static function releaseMasterSource(string $intentDigest): void
    {
        $intentDigest = self::hex($intentDigest, 64, 'handoff intent digest');
        $source = self::$masterSources[$intentDigest] ?? null;
        if (!\is_array($source)) {
            return;
        }
        if (\is_resource($source['stream'] ?? null)) {
            @\fclose($source['stream']);
        } elseif (($source['socket'] ?? null) instanceof \Socket) {
            @\socket_close($source['socket']);
        }
        unset(self::$masterSources[$intentDigest]);
        if (\hash_equals(self::$primaryIntentDigest, $intentDigest)) {
            self::$primaryIntentDigest = (string)(\array_key_first(self::$masterSources) ?? '');
        }
    }

    /**
     * @param array<string,mixed> $intent
     * @return array{socket:\Socket,envelope:array<string,mixed>}
     */
    private static function awaitEnvelope(
        string $path,
        array $intent,
        string $stage,
        int $targetPid,
        string $launchId,
        string $slotId,
        int $generation,
        ?WindowsListenerHandoffRuntime $runtime = null,
        ?float $deadlineMonotonic = null,
    ): array {
        $runtime ??= new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        if ($targetPid !== $runtime->currentPid()) {
            throw new \RuntimeException(
                'Windows listener handoff target PID is not the importing process.'
            );
        }
        self::sweepPendingExportsBestEffort(
            \dirname($path),
            $runtime,
            $deadlineMonotonic,
        );
        $deadline = \min(
            $deadlineMonotonic,
            $runtime->monotonicNow() + self::HANDOFF_TIMEOUT_SECONDS,
        );
        $encoded = null;
        while ($runtime->monotonicNow() < $deadline) {
            $encoded = GatewayProjectStateFilesystem::readOptional(
                $path,
                self::MAX_ENVELOPE_BYTES,
                'Windows listener handoff envelope',
            );
            if ($encoded !== null) {
                break;
            }
            SchedulerSystem::usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
        if (!\is_string($encoded) || $encoded === '') {
            throw new \RuntimeException(
                'Timed out waiting for the target-bound Windows listener handoff.'
            );
        }
        self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);

        $socket = false;
        $protocolId = null;
        $protocolOwnershipTransferred = false;
        $targetIdentity = null;
        try {
            $envelope = self::decodeEnvelope($encoded);
            $protocolId = \base64_decode(
                (string)($envelope['protocol_info_b64'] ?? ''),
                true,
            );
            if (!\is_string($protocolId) || $protocolId === '') {
                throw new \RuntimeException('Windows WSAPROTOCOL identifier is invalid.');
            }
            $targetIdentity = $runtime->captureProcessIdentity($targetPid);
            self::assertEnvelope(
                $envelope,
                $intent,
                $stage,
                $targetPid,
                $launchId,
                $slotId,
                $generation,
                $runtime,
            );
            $ownedProtocolId = $protocolId;
            $protocolId = null;
            $protocolOwnershipTransferred = true;
            $socket = self::consumePendingExport(
                $ownedProtocolId,
                $path,
                $intent['bind_host'],
                $intent['port'],
                $targetPid,
                $targetIdentity,
                $generation,
                $runtime,
                $deadlineMonotonic,
            );
        } finally {
            if (!$protocolOwnershipTransferred) {
                try {
                    $targetIdentity ??= $runtime->captureProcessIdentity($targetPid);
                    self::requestPendingExportReleaseForConsumer(
                        $path,
                        \is_string($protocolId) && $protocolId !== ''
                            ? $protocolId
                            : null,
                        $targetPid,
                        $targetIdentity,
                        $generation,
                        'REJECTED',
                        $runtime,
                        $deadlineMonotonic,
                    );
                } catch (\Throwable) {
                    // The token is owned by the exporter process. If the
                    // protected release request cannot be recorded, its exact
                    // source/target birth and monotonic deadline remain in the
                    // existing registry for a later source-process sweep.
                }
            }
            GatewayProjectStateFilesystem::removeRegular(
                $path,
                'Windows listener handoff envelope',
            );
        }

        return ['socket' => $socket, 'envelope' => $envelope];
    }

    /** @param array<string,mixed> $intent */
    private static function publishEnvelope(
        mixed $socket,
        string $path,
        array $intent,
        string $stage,
        int $targetPid,
        string $launchId,
        string $slotId,
        int $generation,
        ?WindowsListenerHandoffRuntime $runtime = null,
        ?float $deadlineMonotonic = null,
    ): array {
        if (!$socket instanceof \Socket || $targetPid <= 0) {
            throw new \RuntimeException('Windows listener export target is invalid.');
        }
        $runtime ??= new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        self::sweepPendingExportsBestEffort(
            \dirname($path),
            $runtime,
            $deadlineMonotonic,
        );
        $targetIdentity = $runtime->captureProcessIdentity($targetPid);
        $sourcePid = $runtime->currentPid();
        $sourceIdentity = $runtime->captureProcessIdentity($sourcePid);
        $protocolId = null;
        $registered = false;
        $mutexReady = false;
        try {
            $mutexRecoveryState = self::ensureExportMutex(
                $sourcePid,
                $sourceIdentity,
                $runtime,
                $deadlineMonotonic,
            );
            $mutexReady = true;
            if ($mutexRecoveryState === 'ABANDONED') {
                self::recoverAbandonedPendingExports(
                    \dirname($path),
                    $runtime,
                    $deadlineMonotonic,
                );
            }
            $exported = $runtime->export($socket, $targetPid);
            self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
            if (!\is_string($exported) || $exported === '') {
                throw new \RuntimeException(
                    'Winsock refused to export the listener for the requested target PID.'
                );
            }
            $protocolId = $exported;
            self::trackOwnedExportProtocol($protocolId);
            $publishedAt = $runtime->wallNow();
            self::registerPendingExport(
                $protocolId,
                $path,
                $sourcePid,
                $sourceIdentity,
                $targetPid,
                $targetIdentity,
                $generation,
                $mutexRecoveryState,
                $runtime,
                $deadlineMonotonic,
            );
            $registered = true;
            self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
            $envelope = [
                'schema_version' => self::SCHEMA_VERSION,
                'transport' => self::TRANSPORT,
                'stage' => $stage,
                'handoff_id' => $intent['handoff_id'],
                'intent_digest' => $intent['intent_digest'],
                'instance' => $intent['instance'],
                'wls_instance' => $intent['wls_instance'],
                'lease_id' => $intent['lease_id'],
                'bind_host' => $intent['bind_host'],
                'port' => $intent['port'],
                'source_pid' => $sourcePid,
                'source_process_birth' => $sourceIdentity['birth'],
                'target_pid' => $targetPid,
                'target_process_birth' => $targetIdentity['birth'],
                'adoption_nonce' => \bin2hex(\random_bytes(16)),
                'launch_id' => $launchId,
                'slot_id' => $slotId,
                'generation' => $generation,
                'expires_at' => $publishedAt + self::ENVELOPE_LIFETIME_SECONDS,
                'protocol_info_b64' => \base64_encode($protocolId),
            ];
            $envelope['payload_digest'] = self::digest($envelope);
            $encoded = \json_encode(
                $envelope,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if (\strlen($encoded) > self::MAX_ENVELOPE_BYTES) {
                throw new \RuntimeException('Windows listener handoff envelope is too large.');
            }
            self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
            GatewayProjectStateFilesystem::atomicWrite($path, $encoded, 0600);
            self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
            if (!$runtime->publisherReleaseWaitIsExternallyDriven($path)) {
                self::awaitSourceExportRelease(
                    $protocolId,
                    $path,
                    $targetPid,
                    $targetIdentity,
                    $generation,
                    $runtime,
                    $deadlineMonotonic,
                );
            }
        } catch (\Throwable $throwable) {
            if (\is_string($protocolId) && $protocolId !== '') {
                try {
                    if ($registered) {
                        $releasedByRegistry = self::requestPendingExportReleaseForConsumer(
                            $path,
                            $protocolId,
                            $targetPid,
                            $targetIdentity,
                            $generation,
                            'CANCELLED',
                            $runtime,
                            $deadlineMonotonic,
                        );
                        if (!$releasedByRegistry) {
                            $runtime->release($protocolId);
                            self::releaseOwnedExportProtocol($protocolId);
                        }
                    } else {
                        $runtime->release($protocolId);
                        self::releaseOwnedExportProtocol($protocolId);
                    }
                } catch (\Throwable) {
                    $runtime->release($protocolId);
                    self::releaseOwnedExportProtocol($protocolId);
                }
            } elseif ($mutexReady) {
                self::releaseExportMutexIfIdle();
            }
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $path,
                    'failed Windows listener handoff envelope',
                );
            } catch (\Throwable) {
            }
            throw $throwable;
        }
        return [
            'handoff_id' => $intent['handoff_id'],
            'intent_digest' => $intent['intent_digest'],
            'lease_id' => $intent['lease_id'],
            'bind_host' => $intent['bind_host'],
            'port' => $intent['port'],
            'source_pid' => $sourcePid,
            'source_process_birth' => $sourceIdentity['birth'],
            'target_pid' => $targetPid,
            'target_process_birth' => $targetIdentity['birth'],
            'adoption_nonce' => $envelope['adoption_nonce'],
            'envelope_digest' => $envelope['payload_digest'],
            'master_launch_id' => $intent['launch_id'],
            'launch_id' => $launchId,
            'slot_id' => $slotId,
            'generation' => $generation,
        ];
    }

    /** @param array{birth:string,pid_namespace_id:string} $targetIdentity */
    private static function awaitSourceExportRelease(
        string $protocolId,
        string $path,
        int $targetPid,
        array $targetIdentity,
        int $generation,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): void {
        $protocolDigest = \hash('sha256', $protocolId);
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $deadline = \min(
            $deadlineMonotonic,
            $runtime->monotonicNow() + self::ENVELOPE_LIFETIME_SECONDS,
        );
        do {
            self::sweepPendingExports(
                \dirname($path),
                $runtime,
                $deadlineMonotonic,
            );
            if (!isset(self::$ownedExportProtocols[$protocolDigest])) {
                return;
            }
            SchedulerSystem::usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while ($runtime->monotonicNow() < $deadline);

        try {
            self::requestPendingExportReleaseForConsumer(
                $path,
                $protocolId,
                $targetPid,
                $targetIdentity,
                $generation,
                'CANCELLED',
                $runtime,
                $deadlineMonotonic,
            );
        } catch (\Throwable) {
            // The exact exporter still owns the process-local php-src mapping.
            // Registry failure cannot authorize leaving the session mutex held.
        }
        if (isset(self::$ownedExportProtocols[$protocolDigest])) {
            $runtime->release($protocolId);
            self::releaseOwnedExportProtocol($protocolId);
        }
        throw new \RuntimeException(
            'Timed out waiting for the target to consume the Windows listener export.'
        );
    }

    /**
     * @param array{birth:string,pid_namespace_id:string} $sourceIdentity
     * @param array{birth:string,pid_namespace_id:string} $targetIdentity
     */
    private static function registerPendingExport(
        string $protocolId,
        string $path,
        int $sourcePid,
        array $sourceIdentity,
        int $targetPid,
        array $targetIdentity,
        int $generation,
        string $mutexRecoveryState,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): void {
        if ($sourcePid < 1
            || $targetPid < 1
            || $generation < 0
            || !\in_array($mutexRecoveryState, ['NORMAL', 'ABANDONED'], true)
        ) {
            throw new \RuntimeException(
                'Windows listener handoff pending export identity is invalid.'
            );
        }
        $protocolDigest = \hash('sha256', $protocolId);
        $pathDigest = self::pendingPathDigest($path);
        $createdMonotonic = $runtime->monotonicNow();
        $createdTimestamp = $runtime->wallNow();
        $record = [
            'protocol_digest' => $protocolDigest,
            'protocol_info_b64' => \base64_encode($protocolId),
            'envelope_leaf' => \basename($path),
            'path_digest' => $pathDigest,
            'source_pid' => $sourcePid,
            'source_process_birth' => (string)$sourceIdentity['birth'],
            'source_pid_namespace_id' => (string)$sourceIdentity['pid_namespace_id'],
            'target_pid' => $targetPid,
            'target_process_birth' => (string)$targetIdentity['birth'],
            'target_pid_namespace_id' => (string)$targetIdentity['pid_namespace_id'],
            'socket_generation' => $generation,
            'mutex_recovery_state' => $mutexRecoveryState,
            'host_boot_id' => $runtime->hostBootId(),
            'created_monotonic' => $createdMonotonic,
            'expires_monotonic' => $createdMonotonic + self::ENVELOPE_LIFETIME_SECONDS,
            'created_timestamp' => $createdTimestamp,
            'expires_timestamp' => $createdTimestamp + self::ENVELOPE_LIFETIME_SECONDS,
            'release_requested' => false,
            'consumer_state' => 'PENDING',
        ];
        self::withPendingRegistry(
            \dirname($path),
            static function (array &$records) use (
                $protocolDigest,
                $pathDigest,
                $record,
            ): void {
                foreach ($records as $pending) {
                    if (\hash_equals(
                        $pathDigest,
                        (string)($pending['path_digest'] ?? ''),
                    )) {
                        throw new \RuntimeException(
                            'Windows listener handoff path already owns a pending export.'
                        );
                    }
                }
                if (isset($records[$protocolDigest])
                    || \count($records) >= self::MAX_PENDING_EXPORTS
                ) {
                    throw new \RuntimeException(
                        'Windows listener handoff pending export registry is full or duplicated.'
                    );
                }
                $records[$protocolDigest] = $record;
            },
            $runtime,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array{birth:string,pid_namespace_id:string} $sourceIdentity
     */
    private static function ensureExportMutex(
        int $sourcePid,
        array $sourceIdentity,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): string {
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        if (self::$exportMutexGuard instanceof WindowsListenerHandoffMutexGuard) {
            if ($sourcePid !== self::$exportMutexSourcePid
                || !\hash_equals(
                    self::$exportMutexSourceBirth,
                    (string)$sourceIdentity['birth'],
                )
                || !\hash_equals(
                    self::$exportMutexSourcePidNamespaceId,
                    (string)$sourceIdentity['pid_namespace_id'],
                )
            ) {
                throw new \RuntimeException(
                    'Windows listener export mutex is owned by another source birth.'
                );
            }
            return self::$exportMutexRecoveryState;
        }

        $guard = $runtime->acquireExportMutex((int)\max(1, \min(
            self::EXPORT_MUTEX_WAIT_MILLISECONDS,
            \ceil(self::remainingDeadlineSeconds(
                $deadlineMonotonic,
                $runtime,
            ) * 1000.0),
        )));
        self::$exportMutexSourcePid = $sourcePid;
        self::$exportMutexSourceBirth = (string)$sourceIdentity['birth'];
        self::$exportMutexSourcePidNamespaceId = (string)$sourceIdentity['pid_namespace_id'];
        self::$exportMutexRecoveryState = $guard->wasAbandoned()
            ? 'ABANDONED'
            : 'NORMAL';
        self::$exportMutexGuard = $guard;
        return self::$exportMutexRecoveryState;
    }

    private static function trackOwnedExportProtocol(string $protocolId): void
    {
        $digest = \hash('sha256', $protocolId);
        if (isset(self::$ownedExportProtocols[$digest])) {
            throw new \RuntimeException(
                'Windows listener exporter reused a live WSAPROTOCOL mapping identifier.'
            );
        }
        self::$ownedExportProtocols[$digest] = true;
    }

    private static function recoverAbandonedPendingExports(
        string $directory,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): void {
        $removedPaths = [];
        self::withPendingRegistry(
            $directory,
            static function (array &$records) use (
                $directory,
                $runtime,
                &$removedPaths,
            ): void {
                $hostBootId = $runtime->hostBootId();
                foreach ($records as $digest => $record) {
                    $crossBoot = !\hash_equals(
                        $hostBootId,
                        (string)$record['host_boot_id'],
                    );
                    $sourceState = $crossBoot
                        ? MasterLeaseRuntimeIdentity::OWNER_MISSING
                        : $runtime->observeProcessIdentity(
                            (int)$record['source_pid'],
                            (string)$record['source_process_birth'],
                            (string)$record['source_pid_namespace_id'],
                        );
                    if (!$crossBoot && !\in_array($sourceState, [
                        MasterLeaseRuntimeIdentity::OWNER_MISSING,
                        MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
                    ], true)) {
                        throw new \RuntimeException(
                            'Abandoned Windows export mutex has an unretired source birth.'
                        );
                    }
                    $removedPaths[] = $directory . DIRECTORY_SEPARATOR
                        . (string)$record['envelope_leaf'];
                    unset($records[$digest]);
                }
            },
            $runtime,
            $deadlineMonotonic,
        );
        foreach ($removedPaths as $path) {
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $path,
                    'abandoned Windows listener handoff envelope',
                );
            } catch (\Throwable) {
            }
        }
    }

    private static function releaseOwnedExportProtocol(string $protocolId): void
    {
        unset(self::$ownedExportProtocols[\hash('sha256', $protocolId)]);
        self::releaseExportMutexIfIdle();
    }

    private static function releaseExportMutexIfIdle(): void
    {
        if (self::$ownedExportProtocols !== []) {
            return;
        }
        if (self::$exportMutexGuard instanceof WindowsListenerHandoffMutexGuard) {
            self::$exportMutexGuard->release();
        }
        self::$exportMutexGuard = null;
        self::$exportMutexSourcePid = 0;
        self::$exportMutexSourceBirth = '';
        self::$exportMutexSourcePidNamespaceId = '';
        self::$exportMutexRecoveryState = 'NONE';
    }

    /**
     * @param array{birth:string,pid_namespace_id:string} $targetIdentity
     */
    private static function requestPendingExportReleaseForConsumer(
        string $path,
        ?string $protocolId,
        int $targetPid,
        array $targetIdentity,
        int $generation,
        string $consumerState,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): bool {
        if (!\in_array($consumerState, ['CONSUMED', 'REJECTED', 'CANCELLED'], true)) {
            throw new \RuntimeException(
                'Windows listener handoff consumer state is invalid.'
            );
        }
        $pathDigest = self::pendingPathDigest($path);
        $released = false;
        self::withPendingRegistry(
            \dirname($path),
            static function (array &$records) use (
                $pathDigest,
                $protocolId,
                $targetPid,
                $targetIdentity,
                $generation,
                $consumerState,
                $runtime,
                &$released,
            ): void {
                foreach ($records as $digest => $record) {
                    if (!\hash_equals(
                        $pathDigest,
                        (string)($record['path_digest'] ?? ''),
                    )) {
                        continue;
                    }
                    $recordedProtocol = \base64_decode(
                        (string)($record['protocol_info_b64'] ?? ''),
                        true,
                    );
                    if ((int)($record['target_pid'] ?? 0) !== $targetPid
                        || !\hash_equals(
                            (string)$targetIdentity['birth'],
                            (string)($record['target_process_birth'] ?? ''),
                        )
                        || !\hash_equals(
                            (string)$targetIdentity['pid_namespace_id'],
                            (string)($record['target_pid_namespace_id'] ?? ''),
                        )
                        || (int)($record['socket_generation'] ?? -1) !== $generation
                        || !\is_string($recordedProtocol)
                        || $recordedProtocol === ''
                        || ($protocolId !== null
                            && !\hash_equals($protocolId, $recordedProtocol))
                    ) {
                        throw new \RuntimeException(
                            'Windows listener handoff pending export does not match its target birth identity.'
                        );
                    }
                    $records[$digest]['release_requested'] = true;
                    $records[$digest]['consumer_state'] = $consumerState;
                    $released = self::releasePendingRecordIfOwned(
                        $records,
                        (string)$digest,
                        $runtime,
                    );
                    return;
                }
            },
            $runtime,
            $deadlineMonotonic,
        );
        // A registry-less token belongs to a legacy exporter process. PHP keeps
        // exported mapping handles in that process's SOCKETS_G(wsa_info), so a
        // different target process must never pretend it can release them.
        return $released;
    }

    /** @param array<string,array<string,mixed>> $records */
    private static function releasePendingRecordIfOwned(
        array &$records,
        string $digest,
        WindowsListenerHandoffRuntime $runtime,
    ): bool {
        $record = $records[$digest] ?? null;
        if (!\is_array($record) || !self::currentProcessOwnsPendingRecord($record, $runtime)) {
            return false;
        }
        $protocolId = \base64_decode(
            (string)($record['protocol_info_b64'] ?? ''),
            true,
        );
        if (!\is_string($protocolId) || $protocolId === '') {
            throw new \RuntimeException(
                'Windows listener handoff owned pending token is invalid.'
            );
        }
        // php-src implements release as zend_hash_del() against the exporting
        // process's SOCKETS_G(wsa_info). For the exact source process, either
        // return value leaves no matching local mapping handle: true deleted it;
        // false proves it was already absent.
        $runtime->release($protocolId);
        unset($records[$digest]);
        self::releaseOwnedExportProtocol($protocolId);
        return true;
    }

    /** @param array<string,mixed> $record */
    private static function currentProcessOwnsPendingRecord(
        array $record,
        WindowsListenerHandoffRuntime $runtime,
    ): bool {
        if (!\hash_equals(
            (string)($record['host_boot_id'] ?? ''),
            $runtime->hostBootId(),
        )) {
            return false;
        }
        $currentPid = $runtime->currentPid();
        if ($currentPid !== (int)($record['source_pid'] ?? 0)) {
            return false;
        }
        try {
            $currentIdentity = $runtime->captureProcessIdentity($currentPid);
        } catch (\Throwable) {
            return false;
        }
        return \hash_equals(
            (string)($record['source_process_birth'] ?? ''),
            (string)$currentIdentity['birth'],
        ) && \hash_equals(
            (string)($record['source_pid_namespace_id'] ?? ''),
            (string)$currentIdentity['pid_namespace_id'],
        );
    }

    /**
     * @param array{birth:string,pid_namespace_id:string} $targetIdentity
     */
    private static function consumePendingExport(
        string $protocolId,
        string $path,
        string $expectedHost,
        int $expectedPort,
        int $targetPid,
        array $targetIdentity,
        int $generation,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): \Socket {
        $pathDigest = self::pendingPathDigest($path);
        $socket = null;
        $completed = false;
        try {
            self::withPendingRegistry(
                \dirname($path),
                static function (array &$records) use (
                    $pathDigest,
                    $protocolId,
                    $expectedHost,
                    $expectedPort,
                    $targetPid,
                    $targetIdentity,
                    $generation,
                    $runtime,
                    &$socket,
                ): void {
                    $recordKey = null;
                    foreach ($records as $digest => $record) {
                        if (!\hash_equals(
                            $pathDigest,
                            (string)($record['path_digest'] ?? ''),
                        )) {
                            continue;
                        }
                        $recordedProtocol = \base64_decode(
                            (string)($record['protocol_info_b64'] ?? ''),
                            true,
                        );
                        if (!\is_string($recordedProtocol)
                            || !\hash_equals($protocolId, $recordedProtocol)
                            || (int)($record['target_pid'] ?? 0) !== $targetPid
                            || !\hash_equals(
                                (string)$targetIdentity['birth'],
                                (string)($record['target_process_birth'] ?? ''),
                            )
                            || !\hash_equals(
                                (string)$targetIdentity['pid_namespace_id'],
                                (string)($record['target_pid_namespace_id'] ?? ''),
                            )
                            || (int)($record['socket_generation'] ?? -1) !== $generation
                        ) {
                            throw new \RuntimeException(
                                'Windows listener handoff pending export identity changed before import.'
                            );
                        }
                        $recordKey = $digest;
                        $expired = !\hash_equals(
                            $runtime->hostBootId(),
                            (string)($record['host_boot_id'] ?? ''),
                        ) || $runtime->monotonicNow()
                            >= (float)($record['expires_monotonic'] ?? 0.0);
                        if ($expired) {
                            $records[$digest]['release_requested'] = true;
                            $records[$digest]['consumer_state'] = 'REJECTED';
                            self::releasePendingRecordIfOwned(
                                $records,
                                (string)$digest,
                                $runtime,
                            );
                            throw new \RuntimeException(
                                'Windows listener handoff pending export expired before import.'
                            );
                        }
                        break;
                    }

                    if ($recordKey === null) {
                        throw new \RuntimeException(
                            'Windows listener handoff has no protected pending export record.'
                        );
                    }

                    $consumerState = 'REJECTED';
                    try {
                        $imported = $runtime->import($protocolId);
                        if (!$imported instanceof \Socket) {
                            throw new \RuntimeException(
                                'Windows target process could not import its WSAPROTOCOL handoff.'
                            );
                        }
                        $socket = $imported;
                        self::assertListeningSocket($socket, $expectedHost, $expectedPort);
                        $consumerState = 'CONSUMED';
                    } finally {
                        if ($recordKey !== null && isset($records[$recordKey])) {
                            $records[$recordKey]['release_requested'] = true;
                            $records[$recordKey]['consumer_state'] = $consumerState;
                            self::releasePendingRecordIfOwned(
                                $records,
                                (string)$recordKey,
                                $runtime,
                            );
                        }
                    }
                },
                $runtime,
                $deadlineMonotonic,
            );
            if (!$socket instanceof \Socket) {
                throw new \RuntimeException(
                    'Windows target process did not receive a listener socket.'
                );
            }
            $completed = true;
            return $socket;
        } finally {
            if (!$completed && $socket instanceof \Socket) {
                $runtime->close($socket);
            }
        }
    }

    private static function sweepPendingExports(
        string $directory,
        WindowsListenerHandoffRuntime $runtime,
        ?float $deadlineMonotonic = null,
    ): int {
        $removedPaths = [];
        $reclaimed = 0;
        self::withPendingRegistry(
            $directory,
            static function (array &$records) use (
                $directory,
                $runtime,
                &$removedPaths,
                &$reclaimed,
            ): void {
                $now = $runtime->monotonicNow();
                $hostBootId = $runtime->hostBootId();
                foreach ($records as $digest => $record) {
                    $targetState = $runtime->observeProcessIdentity(
                        (int)$record['target_pid'],
                        (string)$record['target_process_birth'],
                        (string)$record['target_pid_namespace_id'],
                    );
                    $crossBoot = !\hash_equals(
                        $hostBootId,
                        (string)$record['host_boot_id'],
                    );
                    $expired = $crossBoot
                        || $now >= (float)$record['expires_monotonic'];
                    $targetExited = \in_array($targetState, [
                        MasterLeaseRuntimeIdentity::OWNER_MISSING,
                        MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
                    ], true);
                    $releaseRequested = ($record['release_requested'] ?? false) === true;
                    $reclaimedHere = false;
                    $currentSourceOwns = self::currentProcessOwnsPendingRecord(
                        $record,
                        $runtime,
                    );
                    if ($crossBoot) {
                        // The kernel mapping handle cannot survive a host boot.
                        unset($records[$digest]);
                        $reclaimedHere = true;
                    } elseif (!$currentSourceOwns) {
                        $sourceState = $runtime->observeProcessIdentity(
                            (int)$record['source_pid'],
                            (string)$record['source_process_birth'],
                            (string)$record['source_pid_namespace_id'],
                        );
                        if (\in_array($sourceState, [
                            MasterLeaseRuntimeIdentity::OWNER_MISSING,
                            MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
                        ], true)) {
                            // The exact exporter birth has departed, so Windows
                            // has closed its process-local mapping and mutex
                            // handles even if the target still exists.
                            unset($records[$digest]);
                            $reclaimedHere = true;
                        }
                    }
                    if (!$reclaimedHere
                        && !$releaseRequested
                        && !$expired
                        && !$targetExited
                    ) {
                        continue;
                    }
                    if (!$reclaimedHere
                        && $currentSourceOwns
                        && self::releasePendingRecordIfOwned(
                        $records,
                        (string)$digest,
                        $runtime,
                    )) {
                        $reclaimedHere = true;
                    }
                    if (!$reclaimedHere) {
                        // Another live exporter owns the only valid release
                        // context. UNKNOWN evidence is never deletion authority.
                        continue;
                    }
                    $removedPaths[] = $directory . DIRECTORY_SEPARATOR
                        . (string)$record['envelope_leaf'];
                    $reclaimed++;
                }
            },
            $runtime,
            $deadlineMonotonic,
        );
        foreach ($removedPaths as $path) {
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $path,
                    'expired Windows listener handoff envelope',
                );
            } catch (\Throwable) {
            }
        }
        self::releaseExportMutexIfIdle();
        return $reclaimed;
    }

    private static function sweepPendingExportsBestEffort(
        ?string $directory = null,
        ?WindowsListenerHandoffRuntime $runtime = null,
        ?float $deadlineMonotonic = null,
    ): void {
        if ($runtime === null && PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        try {
            $directory ??= self::handoffDirectory();
            $registryPath = $directory . DIRECTORY_SEPARATOR
                . self::PENDING_REGISTRY_FILE;
            $status = @\lstat($registryPath);
            if (!\is_array($status)
                && !\file_exists($registryPath)
                && !\is_link($registryPath)
            ) {
                return;
            }
            self::sweepPendingExports(
                $directory,
                $runtime ?? new WindowsListenerHandoffRuntime(),
                $deadlineMonotonic,
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @template TResult
     * @param \Closure(array<string,array<string,mixed>>&):TResult $callback
     * @return TResult
     */
    private static function withPendingRegistry(
        string $directory,
        \Closure $callback,
        ?WindowsListenerHandoffRuntime $runtime = null,
        ?float $deadlineMonotonic = null,
    ): mixed {
        $runtime ??= new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $canonical = \realpath($directory);
        if (!\is_string($canonical)
            || $canonical === ''
            || \is_link($directory)
            || !\is_dir($canonical)
        ) {
            throw new \RuntimeException(
                'Windows listener handoff pending export directory is unsafe.'
            );
        }
        $registryPath = $canonical . DIRECTORY_SEPARATOR . self::PENDING_REGISTRY_FILE;
        $lockPath = $canonical . DIRECTORY_SEPARATOR . self::PENDING_REGISTRY_LOCK;
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            static function () use (
                $canonical,
                $registryPath,
                $callback,
                $runtime,
                $deadlineMonotonic,
            ): mixed {
                self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $registryPath,
                    self::MAX_PENDING_REGISTRY_BYTES,
                    'Windows listener pending export registry',
                    static function (string $contents) use ($canonical, $registryPath): void {
                        unset($contents);
                        self::readPendingRegistry($canonical, $registryPath);
                    },
                );
                $records = self::readPendingRegistry($canonical, $registryPath);
                $originalRecords = $records;
                $result = null;
                $failure = null;
                try {
                    $result = $callback($records);
                } catch (\Throwable $throwable) {
                    $failure = $throwable;
                }
                self::remainingDeadlineSeconds($deadlineMonotonic, $runtime);
                if ($records !== $originalRecords) {
                    self::writePendingRegistry($registryPath, $records);
                }
                if ($failure instanceof \Throwable) {
                    throw $failure;
                }
                return $result;
            },
            waitTimeoutSeconds: \min(
                0.25,
                self::remainingDeadlineSeconds($deadlineMonotonic, $runtime),
            ),
        );
    }

    /** @return array<string,array<string,mixed>> */
    private static function readPendingRegistry(string $directory, string $path): array
    {
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $path,
            self::MAX_PENDING_REGISTRY_BYTES,
            'Windows listener pending export registry',
        );
        if ($encoded === null) {
            return [];
        }
        try {
            $decoded = \json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Windows listener pending export registry is not valid JSON.',
                0,
                $exception,
            );
        }
        $records = \is_array($decoded) ? ($decoded['records'] ?? null) : null;
        if (!\is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0)
                !== self::PENDING_REGISTRY_SCHEMA_VERSION
            || !\is_array($records)
            || (\array_is_list($records) && $records !== [])
            || \count($records) > self::MAX_PENDING_EXPORTS
        ) {
            throw new \RuntimeException(
                'Windows listener pending export registry is malformed.'
            );
        }
        foreach ($records as $digest => $record) {
            $protocolId = \is_array($record)
                ? \base64_decode((string)($record['protocol_info_b64'] ?? ''), true)
                : false;
            $leaf = \is_array($record) ? (string)($record['envelope_leaf'] ?? '') : '';
            $createdMonotonic = \is_array($record)
                ? (float)($record['created_monotonic'] ?? 0.0)
                : 0.0;
            $expiresMonotonic = \is_array($record)
                ? (float)($record['expires_monotonic'] ?? 0.0)
                : 0.0;
            if (!\is_string($digest)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !\is_array($record)
                || !\is_string($protocolId)
                || $protocolId === ''
                || \strlen($protocolId) > 16_384
                || !\hash_equals($digest, \hash('sha256', $protocolId))
                || !\hash_equals($digest, (string)($record['protocol_digest'] ?? ''))
                || \preg_match('/\A[A-Za-z0-9._-]{1,191}\z/D', $leaf) !== 1
                || !\hash_equals(
                    self::pendingPathDigest($directory . DIRECTORY_SEPARATOR . $leaf),
                    (string)($record['path_digest'] ?? ''),
                )
                || !\is_int($record['source_pid'] ?? null)
                || (int)$record['source_pid'] < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $record['source_process_birth'] ?? ''
                )) !== 1
                || !\is_string($record['source_pid_namespace_id'] ?? null)
                || \strlen((string)$record['source_pid_namespace_id']) > 128
                || !\is_int($record['target_pid'] ?? null)
                || (int)$record['target_pid'] < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $record['target_process_birth'] ?? ''
                )) !== 1
                || !\is_string($record['target_pid_namespace_id'] ?? null)
                || \strlen((string)$record['target_pid_namespace_id']) > 128
                || !\is_int($record['socket_generation'] ?? null)
                || (int)$record['socket_generation'] < 0
                || !\in_array((string)($record['mutex_recovery_state'] ?? ''), [
                    'NORMAL',
                    'ABANDONED',
                ], true)
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $record['host_boot_id'] ?? ''
                )) !== 1
                || !\is_finite($createdMonotonic)
                || $createdMonotonic <= 0.0
                || !\is_finite($expiresMonotonic)
                || $expiresMonotonic <= $createdMonotonic
                || $expiresMonotonic - $createdMonotonic
                    > self::ENVELOPE_LIFETIME_SECONDS + 0.001
                || !\is_int($record['created_timestamp'] ?? null)
                || (int)$record['created_timestamp'] < 1
                || !\is_int($record['expires_timestamp'] ?? null)
                || (int)$record['expires_timestamp']
                    <= (int)$record['created_timestamp']
                || (int)$record['expires_timestamp']
                    - (int)$record['created_timestamp']
                    > self::ENVELOPE_LIFETIME_SECONDS
                || !\is_bool($record['release_requested'] ?? null)
                || !\in_array((string)($record['consumer_state'] ?? ''), [
                    'PENDING',
                    'CONSUMED',
                    'REJECTED',
                    'CANCELLED',
                ], true)
                || (bool)$record['release_requested']
                    !== ((string)$record['consumer_state'] !== 'PENDING')
            ) {
                throw new \RuntimeException(
                    'Windows listener pending export registry record is malformed.'
                );
            }
        }
        return $records;
    }

    /** @param array<string,array<string,mixed>> $records */
    private static function writePendingRegistry(string $path, array $records): void
    {
        \ksort($records, SORT_STRING);
        $encoded = \json_encode([
            'schema_version' => self::PENDING_REGISTRY_SCHEMA_VERSION,
            'records' => $records,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (\strlen($encoded) > self::MAX_PENDING_REGISTRY_BYTES) {
            throw new \RuntimeException(
                'Windows listener pending export registry exceeds its fixed size limit.'
            );
        }
        GatewayProjectStateFilesystem::atomicWrite($path, $encoded, 0600);
    }

    private static function pendingPathDigest(string $path): string
    {
        $directory = \realpath(\dirname($path));
        $leaf = \basename($path);
        if (!\is_string($directory)
            || $directory === ''
            || \preg_match('/\A[A-Za-z0-9._-]{1,191}\z/D', $leaf) !== 1
        ) {
            throw new \RuntimeException(
                'Windows listener handoff pending export path is unsafe.'
            );
        }
        $canonical = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory)
            . DIRECTORY_SEPARATOR . $leaf;
        if (PHP_OS_FAMILY === 'Windows') {
            $canonical = \strtolower($canonical);
        }
        return \hash('sha256', $canonical);
    }

    /** @return array<string,mixed> */
    private static function decodeEnvelope(string $encoded): array
    {
        try {
            $envelope = \json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Windows listener handoff envelope is not valid JSON.',
                0,
                $exception,
            );
        }
        if (!\is_array($envelope)) {
            throw new \RuntimeException('Windows listener handoff envelope is invalid.');
        }
        $reportedDigest = self::hex(
            (string)($envelope['payload_digest'] ?? ''),
            64,
            'handoff payload digest',
        );
        unset($envelope['payload_digest']);
        if (!\hash_equals($reportedDigest, self::digest($envelope))) {
            throw new \RuntimeException('Windows listener handoff envelope digest mismatch.');
        }
        $envelope['payload_digest'] = $reportedDigest;

        return $envelope;
    }

    /** @param array<string,mixed> $envelope @param array<string,mixed> $intent */
    private static function assertEnvelope(
        array $envelope,
        array $intent,
        string $stage,
        int $targetPid,
        string $launchId,
        string $slotId,
        int $generation,
        WindowsListenerHandoffRuntime $runtime,
    ): void {
        $sourcePid = (int)($envelope['source_pid'] ?? 0);
        $sourceBirth = (string)($envelope['source_process_birth'] ?? '');
        $targetBirth = (string)($envelope['target_process_birth'] ?? '');
        if ((int)($envelope['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals(self::TRANSPORT, (string)($envelope['transport'] ?? ''))
            || !\hash_equals($stage, (string)($envelope['stage'] ?? ''))
            || !\hash_equals($intent['handoff_id'], (string)($envelope['handoff_id'] ?? ''))
            || !\hash_equals($intent['intent_digest'], (string)($envelope['intent_digest'] ?? ''))
            || !\hash_equals($intent['instance'], (string)($envelope['instance'] ?? ''))
            || !\hash_equals($intent['wls_instance'], (string)($envelope['wls_instance'] ?? ''))
            || !\hash_equals($intent['lease_id'], (string)($envelope['lease_id'] ?? ''))
            || !\hash_equals($intent['bind_host'], (string)($envelope['bind_host'] ?? ''))
            || $intent['port'] !== (int)($envelope['port'] ?? 0)
            || $targetPid !== (int)($envelope['target_pid'] ?? 0)
            || !\hash_equals($launchId, (string)($envelope['launch_id'] ?? ''))
            || !\hash_equals($slotId, (string)($envelope['slot_id'] ?? ''))
            || $generation !== (int)($envelope['generation'] ?? -1)
            || $sourcePid <= 0
            || !$runtime->processBirthMatches($sourcePid, $sourceBirth)
            || !$runtime->processBirthMatches($targetPid, $targetBirth)
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($envelope['adoption_nonce'] ?? ''),
            ) !== 1
            || (int)($envelope['expires_at'] ?? 0) < $runtime->wallNow()
            || !\is_string($envelope['protocol_info_b64'] ?? null)
            || \strlen((string)$envelope['protocol_info_b64']) > 16_384
        ) {
            throw new \RuntimeException(
                'Windows listener handoff envelope failed its PID/lease/generation fence.'
            );
        }
    }

    /** @param array<string,mixed> $intent */
    private static function installMasterSocket(
        mixed $socket,
        array $intent,
        mixed $stream,
        ?WindowsListenerHandoffRuntime $runtime = null,
        ?float $deadlineMonotonic = null,
    ): void {
        if (!$socket instanceof \Socket) {
            throw new \RuntimeException('Windows Master listener socket is invalid.');
        }
        $runtime ??= new WindowsListenerHandoffRuntime();
        $deadlineMonotonic = self::operationDeadline(
            $deadlineMonotonic,
            $runtime,
        );
        $intentDigest = (string)$intent['intent_digest'];
        if (self::hasMasterSocket($intentDigest, $deadlineMonotonic)) {
            $existing = self::$masterSources[$intentDigest];
            if (\hash_equals(
                (string)($existing['intent']['intent_digest'] ?? ''),
                $intentDigest,
            )) {
                if ($socket !== $existing['socket']) {
                    @\socket_close($socket);
                }
                if (\is_resource($stream) && $stream !== ($existing['stream'] ?? null)) {
                    @\fclose($stream);
                }
                return;
            }
            throw new \RuntimeException(
                'Windows Master cannot replace a live startup listener handoff.'
            );
        }
        self::$masterSources[$intentDigest] = [
            'socket' => $socket,
            'stream' => \is_resource($stream) ? $stream : null,
            'intent' => $intent,
        ];
        if (self::$primaryIntentDigest === '') {
            self::$primaryIntentDigest = $intentDigest;
        }
    }

    private static function assertListeningSocket(
        mixed $socket,
        string $expectedHost,
        int $expectedPort,
    ): void {
        $actualHost = '';
        $actualPort = 0;
        $accepting = $socket instanceof \Socket && \defined('SO_ACCEPTCONN')
            ? @\socket_get_option($socket, SOL_SOCKET, SO_ACCEPTCONN)
            : 1;
        $type = $socket instanceof \Socket && \defined('SO_TYPE')
            ? @\socket_get_option($socket, SOL_SOCKET, SO_TYPE)
            : SOCK_STREAM;
        if (!$socket instanceof \Socket
            || !@\socket_getsockname($socket, $actualHost, $actualPort)
            || $accepting !== 1
            || $type !== SOCK_STREAM
            || !\hash_equals($expectedHost, self::normalizeHost((string)$actualHost))
            || $expectedPort !== (int)$actualPort
        ) {
            throw new \RuntimeException(
                'Windows listener handoff does not reference the expected listening TCP endpoint.'
            );
        }
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private static function normalizeIntent(array $intent): array
    {
        if ((int)($intent['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals(self::TRANSPORT, (string)($intent['transport'] ?? ''))
            || ($intent['continuous_ownership'] ?? false) !== true
        ) {
            throw new \RuntimeException('Windows listener handoff schema is invalid.');
        }
        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'transport' => self::TRANSPORT,
            'continuous_ownership' => true,
            'handoff_id' => self::hex((string)($intent['handoff_id'] ?? ''), 32, 'handoff identity'),
            'lease_id' => self::hex((string)($intent['lease_id'] ?? ''), 32, 'listener lease identity'),
            'instance' => self::boundedInstance((string)($intent['instance'] ?? '')),
            'wls_instance' => self::boundedInstance((string)($intent['wls_instance'] ?? '')),
            'bind_host' => self::normalizeHost((string)($intent['bind_host'] ?? '')),
            'port' => self::port($intent['port'] ?? 0),
            'launch_id' => self::hex((string)($intent['launch_id'] ?? ''), 32, 'Master launch identity'),
            'master_path' => (string)($intent['master_path'] ?? ''),
        ];
        $expectedPath = self::masterPath($normalized['handoff_id']);
        if (!self::samePath($normalized['master_path'], $expectedPath)) {
            throw new \RuntimeException('Windows Master handoff path escaped its instance directory.');
        }
        $reportedDigest = self::hex(
            (string)($intent['intent_digest'] ?? ''),
            64,
            'handoff intent digest',
        );
        if (!\hash_equals($reportedDigest, self::digest($normalized))) {
            throw new \RuntimeException('Windows listener handoff intent digest mismatch.');
        }
        $normalized['intent_digest'] = $reportedDigest;

        return $normalized;
    }

    private static function assertWindowsCapability(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('WSAPROTOCOL listener handoff is Windows-only.');
        }
        foreach ([
            'socket_import_stream',
            'socket_wsaprotocol_info_export',
            'socket_wsaprotocol_info_import',
            'socket_wsaprotocol_info_release',
        ] as $function) {
            if (!\function_exists($function)) {
                throw new \RuntimeException(
                    'Windows listener handoff requires ext-sockets function ' . $function . '().'
                );
            }
        }
    }

    private static function operationDeadline(
        ?float $deadlineMonotonic,
        WindowsListenerHandoffRuntime $runtime,
    ): float {
        $now = $runtime->monotonicNow();
        if ($deadlineMonotonic === null) {
            return $now + self::ENVELOPE_LIFETIME_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic <= $now) {
            throw new \RuntimeException(
                'Windows listener handoff operation deadline was exhausted.',
            );
        }
        return $deadlineMonotonic;
    }

    private static function remainingDeadlineSeconds(
        float $deadlineMonotonic,
        WindowsListenerHandoffRuntime $runtime,
    ): float {
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'Windows listener handoff operation deadline is invalid.',
            );
        }
        $remaining = $deadlineMonotonic - $runtime->monotonicNow();
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'Windows listener handoff operation deadline was exhausted.',
            );
        }
        return $remaining;
    }

    /**
     * Reproduce the schema-6 lease birth fence from immutable OS process
     * facts. No random fallback is accepted for a cross-process handoff.
     */
    public static function processBirthIdentity(int $pid): string
    {
        if ($pid < 1) {
            throw new \RuntimeException('Windows handoff process PID is invalid.');
        }
        $identity = (new MasterLeaseRuntimeIdentity())->captureProcessIdentity($pid);
        return $identity['birth'];
    }

    private static function masterPath(string $handoffId): string
    {
        return self::handoffDirectory()
            . DIRECTORY_SEPARATOR
            . '.wls-listener-handoff-'
            . self::hex($handoffId, 32, 'handoff identity')
            . '-master.json';
    }

    private static function handoffDirectory(): string
    {
        if (!\defined('BP')) {
            throw new \RuntimeException('WLS project root is unavailable.');
        }
        $directory = BP . 'var' . DIRECTORY_SEPARATOR . 'server'
            . DIRECTORY_SEPARATOR . 'instances';
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'WLS instance directory is unavailable for Windows listener handoff.'
            );
        }

        return \rtrim($directory, '/\\');
    }

    /** @param array<string,mixed> $value */
    private static function digest(array $value): string
    {
        return \hash('sha256', \json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private static function samePath(string $left, string $right): bool
    {
        $normalize = static fn (string $path): string => \strtolower(\str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            \rtrim($path, '/\\'),
        ));

        return $left !== '' && \hash_equals($normalize($right), $normalize($left));
    }

    private static function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
        $packed = @\inet_pton($host);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($normalized) || $normalized === '') {
            throw new \RuntimeException(
                'Windows listener handoff requires a literal IPv4 or IPv6 address.'
            );
        }

        return \strtolower($normalized);
    }

    private static function port(mixed $port): int
    {
        $port = \is_int($port) ? $port : (int)$port;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Windows listener handoff port is invalid.');
        }

        return $port;
    }

    private static function hex(string $value, int $length, string $label): string
    {
        $value = \strtolower(\trim($value));
        if (\preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $value) !== 1) {
            throw new \RuntimeException($label . ' is invalid.');
        }

        return $value;
    }

    private static function boundedInstance(string $instance): string
    {
        $instance = \trim($instance);
        if ($instance === ''
            || \strlen($instance) > 128
            || \preg_match('/\A[A-Za-z0-9_.-]+\z/D', $instance) !== 1
        ) {
            throw new \RuntimeException('Windows listener handoff instance identity is invalid.');
        }

        return $instance;
    }

    private static function slotId(string $slotId): string
    {
        $slotId = \trim($slotId);
        if ($slotId === ''
            || \strlen($slotId) > 128
            || \preg_match('/\A[A-Za-z0-9_.:#-]+\z/D', $slotId) !== 1
        ) {
            throw new \RuntimeException('Windows listener handoff slot identity is invalid.');
        }

        return $slotId;
    }
}
