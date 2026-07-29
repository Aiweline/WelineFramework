<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/**
 * Project-owned WLS identity and monotonic desired/certificate generations.
 *
 * The JSON file moves with the project. Host claims and fallback leases are
 * derived state used only to reject a live same-host clone.
 */
final class ProjectIdentityStore
{
    public const SCHEMA_VERSION = 1;

    private readonly string $projectRoot;
    private readonly string $identityFile;
    private readonly string $hostStateRoot;
    private readonly string $legacyDesiredStateFile;

    public function __construct(
        ?string $projectRoot = null,
        ?string $hostStateRoot = null,
        ?string $legacyDesiredStateFile = null,
    ) {
        $root = $projectRoot ?? (string)BP;
        $realRoot = \realpath($root);
        if (!\is_string($realRoot) || $realRoot === '') {
            throw new \RuntimeException('Unable to resolve the WLS project root.');
        }
        $this->projectRoot = \rtrim($realRoot, '/\\');
        $this->identityFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        $this->hostStateRoot = $hostStateRoot === null
            ? $this->defaultHostStateRoot()
            : $this->normalizeAbsolutePath($hostStateRoot, 'WLS edge host state root');
        $this->legacyDesiredStateFile = $legacyDesiredStateFile
            ?? Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'gateway-v2'
                . DIRECTORY_SEPARATOR . 'desired-generation.json';
    }

    public function projectUuid(): string
    {
        $state = $this->ensure();
        $this->claimHostIdentity((string)$state['project_uuid']);
        return (string)$state['project_uuid'];
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(): array
    {
        return $this->withProjectLock(
            static fn (array $state): array => [$state, $state],
        );
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    public function advanceDesiredState(string $digest): array
    {
        return $this->advanceGeneration('desired', $digest);
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    public function advanceCertificateState(string $digest): array
    {
        return $this->advanceGeneration('certificate', $digest);
    }

    /**
     * Explicitly replace a cloned/moved project's identity.
     *
     * @return array{previous_uuid:string,project_uuid:string}
     */
    public function rotate(): array
    {
        $result = $this->withProjectLock(function (array $state): array {
            $previous = (string)$state['project_uuid'];
            $state['project_uuid'] = self::uuidV4();
            $state['desired'] = self::emptyGeneration();
            $state['certificate'] = self::emptyGeneration();
            $state['rotated_from'] = $previous;
            $state['rotated_at'] = \gmdate(DATE_ATOM);
            $state['updated_at'] = $state['rotated_at'];
            return [$state, [
                'previous_uuid' => $previous,
                'project_uuid' => (string)$state['project_uuid'],
            ]];
        });

        $this->releaseHostClaim((string)$result['previous_uuid']);
        $this->claimHostIdentity((string)$result['project_uuid']);
        return $result;
    }

    public function hostStateRoot(): string
    {
        return $this->hostStateRoot;
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    private function advanceGeneration(string $section, string $digest): array
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \InvalidArgumentException('WLS project state digest must be SHA-256 hexadecimal.');
        }

        return $this->withProjectLock(function (array $state) use ($section, $digest): array {
            $current = \is_array($state[$section] ?? null)
                ? $state[$section]
                : self::emptyGeneration();
            $generation = \max(0, (int)($current['generation'] ?? 0));
            if (!\hash_equals((string)($current['digest'] ?? ''), $digest)) {
                $generation++;
            }
            $idempotencyKey = \substr(\hash(
                'sha256',
                (string)$state['project_uuid'] . ':' . $section . ':' . $generation . ':' . $digest,
            ), 0, 40);
            $next = [
                'generation' => $generation,
                'digest' => $digest,
                'idempotency_key' => $idempotencyKey,
            ];
            if ($current !== $next) {
                $state[$section] = $next;
                $state['updated_at'] = \gmdate(DATE_ATOM);
            }
            return [$state, $next];
        });
    }

    /**
     * @template TResult
     * @param callable(array<string,mixed>):array{0:array<string,mixed>,1:TResult} $callback
     * @return TResult
     */
    private function withProjectLock(callable $callback): mixed
    {
        $directory = \dirname($this->identityFile);
        if (\is_link($directory)
            || (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory))
        ) {
            throw new \RuntimeException('WLS project identity directory is unavailable: ' . $directory);
        }
        if (!\is_writable($directory)) {
            throw new \RuntimeException(
                'WLS project identity is missing or not writable: ' . $this->identityFile
            );
        }
        $this->assertSafeFileTarget($this->identityFile);
        $lockFile = $directory . DIRECTORY_SEPARATOR . '.wls-project.lock';
        $this->assertSafeFileTarget($lockFile);
        $lock = @\fopen($lockFile, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock WLS project identity.');
        }
        @\chmod($lockFile, 0600);
        $this->preserveProjectIdentityOwnership($lockFile, $lock);
        if (\is_file($this->identityFile)) {
            $this->preserveProjectIdentityOwnership($this->identityFile);
        }
        try {
            $exists = \is_file($this->identityFile);
            $state = $exists ? $this->readStateFile($this->identityFile) : $this->newState();
            [$next, $result] = $callback($state);
            $this->validateState($next);
            if (!$exists || $next !== $state) {
                $this->publishJson($this->identityFile, $next);
            }
            return $result;
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function newState(): array
    {
        $now = \gmdate(DATE_ATOM);
        $state = [
            'schema_version' => self::SCHEMA_VERSION,
            'project_uuid' => self::uuidV4(),
            'desired' => self::emptyGeneration(),
            'certificate' => self::emptyGeneration(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $legacy = $this->readLegacyDesiredState();
        if ($legacy !== null) {
            $state['desired'] = $legacy;
            $state['migrated_from'] = 'var/server/gateway-v2/desired-generation.json';
        }
        return $state;
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}|null
     */
    private function readLegacyDesiredState(): ?array
    {
        if (!\is_file($this->legacyDesiredStateFile) || \is_link($this->legacyDesiredStateFile)) {
            return null;
        }
        $legacy = $this->readStateFile($this->legacyDesiredStateFile, false);
        $generation = \max(0, (int)($legacy['generation'] ?? 0));
        $digest = \strtolower(\trim((string)($legacy['digest'] ?? '')));
        if ($generation < 1 || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return null;
        }
        return [
            'generation' => $generation,
            'digest' => $digest,
            'idempotency_key' => \trim((string)($legacy['idempotency_key'] ?? '')),
        ];
    }

    private function claimHostIdentity(string $projectUuid): void
    {
        $claims = $this->hostStateRoot . DIRECTORY_SEPARATOR . 'project-identities';
        if (!\is_dir($claims) && !@\mkdir($claims, 0700, true) && !\is_dir($claims)) {
            throw new \RuntimeException('Unable to create WLS host identity claims directory.');
        }
        @\chmod($claims, 0700);
        $claim = $claims . DIRECTORY_SEPARATOR . $projectUuid . '.json';
        $lockFile = $claim . '.lock';
        $this->assertSafeFileTarget($claim);
        $this->assertSafeFileTarget($lockFile);
        $lock = @\fopen($lockFile, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock WLS host identity claim.');
        }
        @\chmod($lockFile, 0600);
        try {
            $existing = \is_file($claim) ? $this->readStateFile($claim, false) : [];
            $claimedRoot = \trim((string)($existing['project_root'] ?? ''));
            if ($claimedRoot !== '' && $claimedRoot !== $this->projectRoot && \is_dir($claimedRoot)) {
                throw new \RuntimeException(
                    'WLS project UUID ' . $projectUuid . ' is already active at ' . $claimedRoot
                    . '; this copy must use explicit project identity rotation before starting.'
                );
            }
            $now = \gmdate(DATE_ATOM);
            $this->publishJson($claim, [
                'schema_version' => self::SCHEMA_VERSION,
                'project_uuid' => $projectUuid,
                'project_root' => $this->projectRoot,
                'claimed_at' => (string)($existing['claimed_at'] ?? $now),
                'last_seen_at' => $now,
            ]);
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    private function releaseHostClaim(string $projectUuid): void
    {
        $claim = $this->hostStateRoot . DIRECTORY_SEPARATOR . 'project-identities'
            . DIRECTORY_SEPARATOR . $projectUuid . '.json';
        if (!\is_file($claim) || \is_link($claim)) {
            return;
        }
        $lockFile = $claim . '.lock';
        $this->assertSafeFileTarget($lockFile);
        $lock = @\fopen($lockFile, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock WLS host identity claim for release.');
        }
        try {
            if (!\is_file($claim) || \is_link($claim)) {
                return;
            }
            $existing = $this->readStateFile($claim, false);
            if ((string)($existing['project_root'] ?? '') === $this->projectRoot
                && !@\unlink($claim)
                && \is_file($claim)
            ) {
                throw new \RuntimeException('Unable to release WLS host identity claim.');
            }
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readStateFile(string $file, bool $requireObject = true): array
    {
        $this->assertSafeFileTarget($file);
        if (!\is_readable($file)) {
            throw new \RuntimeException('WLS project state is not readable: ' . $file);
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw)) {
            throw new \RuntimeException('Unable to read WLS project state: ' . $file);
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            if (!$requireObject) {
                return [];
            }
            throw new \RuntimeException('WLS project state contains invalid JSON: ' . $file);
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function validateState(array $state): void
    {
        if ((int)($state['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || \preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                \strtolower((string)($state['project_uuid'] ?? '')),
            ) !== 1
        ) {
            throw new \RuntimeException('WLS project identity schema or UUID is invalid.');
        }
        foreach (['desired', 'certificate'] as $section) {
            $generation = \is_array($state[$section] ?? null) ? $state[$section] : [];
            $number = $generation['generation'] ?? null;
            $digest = \strtolower(\trim((string)($generation['digest'] ?? '')));
            if (!\is_int($number) || $number < 0
                || ($digest !== '' && \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1)
            ) {
                throw new \RuntimeException('WLS project generation state is invalid: ' . $section);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function publishJson(string $file, array $data): void
    {
        $encoded = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $payload = \is_string($encoded) ? $encoded . PHP_EOL : '';
        $temporary = $file . '.tmp.' . \bin2hex(\random_bytes(6));
        $temporaryHandle = $payload === '' ? false : @\fopen($temporary, 'xb');
        if (!\is_resource($temporaryHandle)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to stage WLS project state: ' . $file);
        }
        try {
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($temporaryHandle, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to stage WLS project state: ' . $file);
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($temporaryHandle)
                || (\function_exists('fsync') && !@\fsync($temporaryHandle))
            ) {
                throw new \RuntimeException('Unable to synchronize WLS project state: ' . $file);
            }
            if (\hash_equals($this->identityFile, $file)) {
                $this->preserveProjectIdentityOwnership($temporary, $temporaryHandle);
            }
            if (\function_exists('fchmod')) {
                @\fchmod($temporaryHandle, 0600);
            } else {
                @\chmod($temporary, 0600);
            }
        } catch (\Throwable $throwable) {
            @\fclose($temporaryHandle);
            @\unlink($temporary);
            throw $throwable;
        }
        @\fclose($temporaryHandle);
        if (@\rename($temporary, $file)) {
            @\chmod($file, 0600);
            return;
        }

        // Windows cannot always atomically replace an existing target. Every
        // caller already holds the corresponding project/claim lock, so use a
        // complete locked overwrite instead of unlinking the live file.
        $target = @\fopen($file, 'c+b');
        if (!\is_resource($target) || !@\flock($target, LOCK_EX)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish WLS project state: ' . $file);
        }
        $published = false;
        try {
            if (!@\ftruncate($target, 0) || !@\rewind($target)) {
                throw new \RuntimeException('Unable to replace WLS project state: ' . $file);
            }
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($target, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write WLS project state: ' . $file);
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($target)) {
                throw new \RuntimeException('Unable to flush WLS project state: ' . $file);
            }
            @\chmod($file, 0600);
            $published = true;
        } finally {
            @\flock($target, LOCK_UN);
            @\fclose($target);
            @\unlink($temporary);
        }
        if (!$published) {
            throw new \RuntimeException('Unable to publish WLS project state: ' . $file);
        }
    }

    /**
     * Project facts must remain usable by the project owner even when an
     * administrator performs enrollment or legacy promotion. Restrict root
     * ownership repair to the identity file and its private lock/candidate.
     *
     * @param resource|null $handle
     */
    private function preserveProjectIdentityOwnership(string $file, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $lockFile = \dirname($this->identityFile) . DIRECTORY_SEPARATOR
            . '.wls-project.lock';
        if (!\hash_equals($this->identityFile, $file)
            && !\hash_equals($lockFile, $file)
            && !\str_starts_with($file, $this->identityFile . '.tmp.')
        ) {
            throw new \RuntimeException(
                'Refusing to apply project ownership outside WLS project facts.'
            );
        }
        $owner = @\lstat($this->projectRoot);
        if (!\is_array($owner)
            || \is_link($this->projectRoot)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
            || \is_link($file)
            || !\is_file($file)
        ) {
            throw new \RuntimeException(
                'Unable to establish safe WLS project fact ownership.'
            );
        }
        $uid = (int)$owner['uid'];
        $gid = (int)$owner['gid'];
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $uid);
        if (!$ownerApplied) {
            $ownerApplied = \function_exists('lchown')
                ? @\lchown($file, $uid)
                : @\chown($file, $uid);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $gid);
        if (!$groupApplied) {
            $groupApplied = \function_exists('lchgrp')
                ? @\lchgrp($file, $gid)
                : @\chgrp($file, $gid);
        }
        $actual = @\lstat($file);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || (int)($actual['uid'] ?? -1) !== $uid
            || (int)($actual['gid'] ?? -1) !== $gid
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on WLS project facts.'
            );
        }
    }

    private function assertSafeFileTarget(string $file): void
    {
        if (\is_link($file) || (\file_exists($file) && !\is_file($file))) {
            throw new \RuntimeException('WLS project state target must be a regular non-symlink file: ' . $file);
        }
    }

    private function defaultHostStateRoot(): string
    {
        $override = \getenv('WLS_EDGE_STATE_HOME');
        if ($override !== false && \trim((string)$override) !== '') {
            return $this->normalizeAbsolutePath((string)$override, 'WLS_EDGE_STATE_HOME');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $base = (string)(\getenv('LOCALAPPDATA') ?: \getenv('PROGRAMDATA') ?: '');
            if (\trim($base) === '') {
                throw new \RuntimeException('WLS edge state requires LOCALAPPDATA or PROGRAMDATA.');
            }
            return \rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'WlsEdge' . DIRECTORY_SEPARATOR . 'v2';
        }
        $stateHome = (string)(\getenv('XDG_STATE_HOME') ?: '');
        if (\trim($stateHome) === '') {
            $userHome = (string)(\getenv('HOME') ?: '');
            if (\trim($userHome) === '') {
                throw new \RuntimeException('WLS edge state requires HOME or XDG_STATE_HOME.');
            }
            $stateHome = \rtrim($userHome, '/\\') . DIRECTORY_SEPARATOR . '.local'
                . DIRECTORY_SEPARATOR . 'state';
        }
        return \rtrim($stateHome, '/\\') . DIRECTORY_SEPARATOR . 'weline'
            . DIRECTORY_SEPARATOR . 'wls-edge' . DIRECTORY_SEPARATOR . 'v2';
    }

    private function normalizeAbsolutePath(string $path, string $label): string
    {
        $path = \trim(\str_replace("\0", '', $path));
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if (!$absolute || \in_array('..', \preg_split('#[\\\\/]+#', $path) ?: [], true)) {
            throw new \RuntimeException($label . ' must be absolute and must not contain traversal.');
        }
        return \rtrim($path, '/\\');
    }

    /**
     * @return array{generation:int,digest:string,idempotency_key:string}
     */
    private static function emptyGeneration(): array
    {
        return ['generation' => 0, 'digest' => '', 'idempotency_key' => ''];
    }

    private static function uuidV4(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);
        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-'
            . \substr($hex, 12, 4) . '-' . \substr($hex, 16, 4) . '-'
            . \substr($hex, 20, 12);
    }
}
