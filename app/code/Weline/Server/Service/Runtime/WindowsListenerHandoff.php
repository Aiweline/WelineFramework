<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

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

    /** @var array<string,array{socket:\Socket,stream:mixed,intent:array<string,mixed>}> */
    private static array $masterSources = [];
    private static string $primaryIntentDigest = '';

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
        if ((int)($lease['schema_version'] ?? 0) !== 5
            || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Windows startup listener requires one RESERVED schema-5 lease.'
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
     * one schema-5 startup lease.
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
            if ((int)($lease['schema_version'] ?? 0) === 5
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
    ): void {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
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
        );
    }

    /** @param resource $stream @param array<string,mixed> $intent */
    public static function installCurrentProcessSource(
        mixed $stream,
        array $intent,
    ): void {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
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
        self::installMasterSocket($socket, $intent, $stream);
    }

    /** @param array<string,mixed> $intent */
    public static function awaitInstallForMaster(array $intent): void
    {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $imported = self::awaitEnvelope(
            $intent['master_path'],
            $intent,
            'start_to_master',
            (int)\getmypid(),
            $intent['launch_id'],
            'master',
            0,
        );
        self::installMasterSocket($imported['socket'], $intent, null);
    }

    public static function hasMasterSocket(?string $intentDigest = null): bool
    {
        $intentDigest = $intentDigest !== null
            ? \strtolower(\trim($intentDigest))
            : self::$primaryIntentDigest;
        return $intentDigest !== ''
            && (self::$masterSources[$intentDigest]['socket'] ?? null) instanceof \Socket;
    }

    /** @return array<string,mixed> */
    public static function masterIntent(?string $intentDigest = null): array
    {
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
    ): array {
        self::assertWindowsCapability();
        $intent = self::normalizeIntent($intent);
        $intentDigest = (string)$intent['intent_digest'];
        if (!self::hasMasterSocket($intentDigest)) {
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
        );
    }

    /**
     * @param array{
     *   handoff_id:string,intent_digest:string,wls_instance:string,lease_id:string,
     *   bind_host:string,port:int,launch_id:string,slot_id:string,generation:int
     * } $expected
     * @return array{socket:\Socket,proof:array<string,mixed>}
     */
    public static function awaitChildSocket(string $path, array $expected): array
    {
        self::assertWindowsCapability();
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

    public static function closeMasterSocket(): void
    {
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
    ): array {
        $deadline = (\hrtime(true) / 1_000_000_000) + self::HANDOFF_TIMEOUT_SECONDS;
        $encoded = null;
        while ((\hrtime(true) / 1_000_000_000) < $deadline) {
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

        $socket = false;
        $protocolId = null;
        try {
            $envelope = self::decodeEnvelope($encoded);
            self::assertEnvelope(
                $envelope,
                $intent,
                $stage,
                $targetPid,
                $launchId,
                $slotId,
                $generation,
            );
            $protocolId = \base64_decode(
                (string)$envelope['protocol_info_b64'],
                true,
            );
            if (!\is_string($protocolId) || $protocolId === '') {
                throw new \RuntimeException('Windows WSAPROTOCOL identifier is invalid.');
            }
            $socket = @\socket_wsaprotocol_info_import($protocolId);
            $released = @\socket_wsaprotocol_info_release($protocolId);
            $protocolId = null;
            if (!$socket instanceof \Socket || $released !== true) {
                if ($socket instanceof \Socket) {
                    @\socket_close($socket);
                }
                throw new \RuntimeException(
                    'Windows target process could not consume and release its WSAPROTOCOL handoff.'
                );
            }
            self::assertListeningSocket($socket, $intent['bind_host'], $intent['port']);
        } finally {
            if (\is_string($protocolId) && $protocolId !== '') {
                @\socket_wsaprotocol_info_release($protocolId);
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
    ): array {
        if (!$socket instanceof \Socket || $targetPid <= 0) {
            throw new \RuntimeException('Windows listener export target is invalid.');
        }
        $protocolId = @\socket_wsaprotocol_info_export($socket, $targetPid);
        if (!\is_string($protocolId) || $protocolId === '') {
            throw new \RuntimeException(
                'Winsock refused to export the listener for the requested target PID.'
            );
        }
        $targetProcessBirth = self::processBirthIdentity($targetPid);
        $sourceProcessBirth = self::processBirthIdentity((int)\getmypid());
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
            'source_pid' => (int)\getmypid(),
            'source_process_birth' => $sourceProcessBirth,
            'target_pid' => $targetPid,
            'target_process_birth' => $targetProcessBirth,
            'adoption_nonce' => \bin2hex(\random_bytes(16)),
            'launch_id' => $launchId,
            'slot_id' => $slotId,
            'generation' => $generation,
            'expires_at' => \time() + self::ENVELOPE_LIFETIME_SECONDS,
            'protocol_info_b64' => \base64_encode($protocolId),
        ];
        $envelope['payload_digest'] = self::digest($envelope);
        try {
            $encoded = \json_encode(
                $envelope,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if (\strlen($encoded) > self::MAX_ENVELOPE_BYTES) {
                throw new \RuntimeException('Windows listener handoff envelope is too large.');
            }
            GatewayProjectStateFilesystem::atomicWrite($path, $encoded, 0600);
        } catch (\Throwable $throwable) {
            @\socket_wsaprotocol_info_release($protocolId);
            throw $throwable;
        }
        return [
            'handoff_id' => $intent['handoff_id'],
            'intent_digest' => $intent['intent_digest'],
            'lease_id' => $intent['lease_id'],
            'bind_host' => $intent['bind_host'],
            'port' => $intent['port'],
            'source_pid' => (int)\getmypid(),
            'source_process_birth' => $sourceProcessBirth,
            'target_pid' => $targetPid,
            'target_process_birth' => $targetProcessBirth,
            'adoption_nonce' => $envelope['adoption_nonce'],
            'envelope_digest' => $envelope['payload_digest'],
            'master_launch_id' => $intent['launch_id'],
            'launch_id' => $launchId,
            'slot_id' => $slotId,
            'generation' => $generation,
        ];
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
    ): void {
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
            || (int)($envelope['source_pid'] ?? 0) <= 0
            || !\hash_equals(
                self::processBirthIdentity((int)($envelope['source_pid'] ?? 0)),
                (string)($envelope['source_process_birth'] ?? ''),
            )
            || !\hash_equals(
                self::processBirthIdentity($targetPid),
                (string)($envelope['target_process_birth'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($envelope['adoption_nonce'] ?? ''),
            ) !== 1
            || (int)($envelope['expires_at'] ?? 0) < \time()
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
    ): void {
        if (!$socket instanceof \Socket) {
            throw new \RuntimeException('Windows Master listener socket is invalid.');
        }
        $intentDigest = (string)$intent['intent_digest'];
        if (self::hasMasterSocket($intentDigest)) {
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

    /**
     * Reproduce the schema-5 lease birth fence from immutable OS process
     * facts. No random fallback is accepted for a cross-process handoff.
     */
    public static function processBirthIdentity(int $pid): string
    {
        if ($pid < 1) {
            throw new \RuntimeException('Windows handoff process PID is invalid.');
        }
        $info = Processer::getProcessInfo($pid);
        $startedAt = \trim((string)($info['start_time'] ?? ''));
        $name = \trim((string)($info['name'] ?? ''));
        if (($info['exists'] ?? false) !== true || $startedAt === '') {
            throw new \RuntimeException(
                'Windows handoff process birth identity is unavailable.'
            );
        }
        return \hash(
            'sha256',
            $pid . "\0" . $startedAt . "\0" . $name . "\0"
                . \hash('sha256', (string)($info['command'] ?? '')),
        );
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
