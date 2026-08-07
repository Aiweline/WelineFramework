<?php

declare(strict_types=1);

namespace Weline\Server\Service\Security;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

/**
 * Transactional state authority for mutable WLS attack rules and permanent bans.
 *
 * Both documents share one stable lock inode. Rules carry their reload signal
 * in the committed document, so a second flag publication is never required.
 */
final class SecurityPolicyStateStore
{
    private const RULES_FILE = 'security-rules.json';

    private const LEGACY_FLAG_FILE = 'security-rules-update.flag';

    private const BANS_FILE = 'permanent-banned-ips.json';

    private const LOCK_FILE = '.security-policy.lock';

    private const RULES_SCHEMA = 'wls-security-rules/1';

    private const BANS_SCHEMA = 'wls-security-permanent-bans/1';

    private const RULES_METADATA_KEY = '__wls_state';

    private const MAX_RULES_BYTES = 4_194_304;

    private const MAX_BANS_BYTES = 4_194_304;

    private const MAX_FLAG_BYTES = 256;

    private const MAX_DIRECTORY_ENTRIES = 16_384;

    private const MAX_ARTIFACTS_PER_KIND = 8;

    private const MAX_JSON_DEPTH = 32;

    private const MAX_JSON_NODES = 100_000;

    private const MAX_JSON_STRING_BYTES = 1_048_576;

    private const MAX_JSON_KEY_BYTES = 1_024;

    private const MAX_PERMANENT_BANS = 65_536;

    private const POSIX_FILE_MODE = 0600;

    private string $baseDirectory;

    public function __construct(
        ?string $baseDirectory = null,
        private readonly float $lockTimeoutSeconds = 0.25,
    ) {
        if (!\is_finite($this->lockTimeoutSeconds)
            || $this->lockTimeoutSeconds <= 0.0
            || $this->lockTimeoutSeconds > 300.0
        ) {
            throw new \InvalidArgumentException(
                'Security policy state lock timeout must be within (0, 300] seconds.'
            );
        }
        $baseDirectory ??= self::defaultBaseDirectory();
        $baseDirectory = \rtrim($baseDirectory, '/\\');
        if ($baseDirectory === '' || \str_contains($baseDirectory, "\0")) {
            throw new \InvalidArgumentException('Security policy state directory is invalid.');
        }
        $this->baseDirectory = $baseDirectory;
    }

    public static function defaultBaseDirectory(): string
    {
        if (!\defined('BP')) {
            throw new \RuntimeException('WLS project root is unavailable for security policy state.');
        }

        return (string)\constant('BP') . 'var' . DIRECTORY_SEPARATOR . 'server';
    }

    public static function defaultRulesPath(): string
    {
        return self::defaultBaseDirectory() . DIRECTORY_SEPARATOR . self::RULES_FILE;
    }

    public static function defaultLegacyFlagPath(): string
    {
        return self::defaultBaseDirectory() . DIRECTORY_SEPARATOR . self::LEGACY_FLAG_FILE;
    }

    public static function defaultPermanentBansPath(): string
    {
        return self::defaultBaseDirectory() . DIRECTORY_SEPARATOR . self::BANS_FILE;
    }

    public function rulesPath(): string
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . self::RULES_FILE;
    }

    public function legacyFlagPath(): string
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . self::LEGACY_FLAG_FILE;
    }

    public function permanentBansPath(): string
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . self::BANS_FILE;
    }

    /**
     * @return array{rules:array<string,mixed>,generation:int,digest:string,signal:string}|null
     */
    public function readRulesState(): ?array
    {
        return $this->withLock(function (): ?array {
            return $this->readRulesStateUnlocked(true);
        });
    }

    /**
     * @param array<string,mixed> $rules
     * @param array{generation:int,digest:string}|null $expectedReceipt
     * @return array{rules:array<string,mixed>,generation:int,digest:string,signal:string}
     */
    public function writeRules(array $rules, ?array $expectedReceipt = null): array
    {
        if (\array_key_exists(self::RULES_METADATA_KEY, $rules)) {
            throw new \InvalidArgumentException(
                'Security rules must not define the reserved WLS state metadata key.'
            );
        }
        $rules = $this->normalizeRules($rules);
        if ($expectedReceipt !== null) {
            $expectedGeneration = $expectedReceipt['generation'] ?? null;
            $expectedDigest = $expectedReceipt['digest'] ?? null;
            if (!\is_int($expectedGeneration)
                || $expectedGeneration < 0
                || !\is_string($expectedDigest)
                || ($expectedGeneration === 0 && $expectedDigest !== '')
                || ($expectedGeneration > 0
                    && \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1)
            ) {
                throw new \InvalidArgumentException('Security rules expected receipt is invalid.');
            }
        }
        $rulesDigest = \hash('sha256', $this->canonicalJson($rules));

        return $this->withLock(function () use ($rules, $rulesDigest, $expectedReceipt): array {
            $current = $this->readRulesStateUnlocked(true);
            if ($current !== null && \hash_equals($current['digest'], $rulesDigest)) {
                return $current;
            }
            if ($current === null) {
                if ($expectedReceipt !== null
                    && ((int)$expectedReceipt['generation'] !== 0
                        || (string)$expectedReceipt['digest'] !== '')
                ) {
                    throw new \RuntimeException(
                        'Security rules update conflict: the expected generation does not match empty state.'
                    );
                }
            } elseif ($expectedReceipt === null
                || (int)$expectedReceipt['generation'] !== $current['generation']
                || !\hash_equals((string)$expectedReceipt['digest'], $current['digest'])
            ) {
                throw new \RuntimeException(
                    'Security rules update conflict: the committed generation changed before publication.'
                );
            }
            $generation = ($current['generation'] ?? 0) + 1;
            $encoded = $this->encodeRulesEnvelope($rules, $generation);
            GatewayProjectStateFilesystem::atomicWrite(
                $this->rulesPath(),
                $encoded,
                self::POSIX_FILE_MODE,
            );
            $published = $this->decodeRulesDocument(
                GatewayProjectStateFilesystem::read(
                    $this->rulesPath(),
                    self::MAX_RULES_BYTES,
                    'security rules state',
                ),
            );
            if ($published['legacy']
                || $published['generation'] !== $generation
                || !\hash_equals($published['digest'], $rulesDigest)
            ) {
                throw new \RuntimeException('Published security rules state failed its generation receipt.');
            }

            return $this->publicRulesState($published);
        });
    }

    /**
     * @return array{rules:?array{rules:array<string,mixed>,generation:int,digest:string,signal:string},bans:array{ips:list<string>,generation:int,digest:string,signal:string},signal:string}
     */
    public function snapshot(): array
    {
        return $this->withLock(function (): array {
            $rules = $this->readRulesStateUnlocked(true);
            $bans = $this->readPermanentBansStateUnlocked(true);
            $legacySignal = $this->readLegacyFlagSignalUnlocked();
            $rulesSignal = $rules['signal'] ?? 'rules:none';

            return [
                'rules' => $rules,
                'bans' => $bans,
                'signal' => $rulesSignal . '|' . $bans['signal'] . '|' . $legacySignal,
            ];
        });
    }

    /**
     * Read only the two atomically published targets used by Worker request
     * paths. This path never acquires the writer/recovery lock, scans the
     * recovery namespace, upgrades legacy state, or performs a write. A
     * malformed/missing transient is reported to the caller, which retains its
     * already verified in-memory LKG; explicit reload/compile paths continue to
     * use snapshot() and perform full recovery under the stable lock.
     *
     * @return array{rules:?array{rules:array<string,mixed>,generation:int,digest:string,signal:string},bans:array{ips:list<string>,generation:int,digest:string,signal:string},signal:string}
     */
    public function snapshotForRuntime(): array
    {
        $rulesRaw = GatewayProjectStateFilesystem::readOptional(
            $this->rulesPath(),
            self::MAX_RULES_BYTES,
            'runtime security rules state',
        );
        $rules = $rulesRaw === null
            ? null
            : $this->publicRulesState($this->decodeRulesDocument($rulesRaw));

        $bansRaw = GatewayProjectStateFilesystem::readOptional(
            $this->permanentBansPath(),
            self::MAX_BANS_BYTES,
            'runtime permanent ban state',
        );
        $bans = $bansRaw === null
            ? $this->emptyBansState()
            : $this->publicBansState($this->decodeBansDocument($bansRaw));
        $legacySignal = $this->readLegacyFlagSignalOptimistic();
        $rulesSignal = $rules['signal'] ?? 'rules:none';

        return [
            'rules' => $rules,
            'bans' => $bans,
            'signal' => $rulesSignal . '|' . $bans['signal'] . '|' . $legacySignal,
        ];
    }

    public function signal(): string
    {
        return $this->snapshot()['signal'];
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    public function readPermanentBansState(): array
    {
        return $this->withLock(function (): array {
            return $this->readPermanentBansStateUnlocked(true);
        });
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    public function addPermanentBan(string $ip): array
    {
        $ip = $this->normalizeIp($ip);

        return $this->mutatePermanentBans(static function (array $ips) use ($ip): array {
            $ips[$ip] = true;
            return $ips;
        });
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    public function removePermanentBan(string $ip): array
    {
        $ip = $this->normalizeIp($ip);

        return $this->mutatePermanentBans(static function (array $ips) use ($ip): array {
            unset($ips[$ip]);
            return $ips;
        });
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    public function clearPermanentBans(): array
    {
        return $this->mutatePermanentBans(static fn (array $ips): array => []);
    }

    /**
     * @param \Closure(array<string,true>):array<string,true> $mutation
     * @return array{ips:list<string>,generation:int,digest:string,signal:string}
     */
    private function mutatePermanentBans(\Closure $mutation): array
    {
        return $this->withLock(function () use ($mutation): array {
            $current = $this->readPermanentBansStateUnlocked(true);
            $set = \array_fill_keys($current['ips'], true);
            $nextSet = $mutation($set);
            $next = [];
            foreach (\array_keys($nextSet) as $ip) {
                $next[] = $this->normalizeIp((string)$ip);
            }
            $next = \array_values(\array_unique($next));
            \sort($next, SORT_STRING);
            if (\count($next) > self::MAX_PERMANENT_BANS) {
                throw new \RuntimeException('Permanent ban state exceeds its fixed IP quota.');
            }
            if ($next === $current['ips']) {
                return $current;
            }
            $generation = $current['generation'] + 1;
            $expectedDigest = \hash('sha256', $this->canonicalJson($next));
            $encoded = $this->encodeBansEnvelope($next, $generation);
            GatewayProjectStateFilesystem::atomicWrite(
                $this->permanentBansPath(),
                $encoded,
                self::POSIX_FILE_MODE,
            );
            $published = $this->decodeBansDocument(
                GatewayProjectStateFilesystem::read(
                    $this->permanentBansPath(),
                    self::MAX_BANS_BYTES,
                    'permanent ban state',
                ),
            );
            if ($published['legacy']
                || $published['generation'] !== $generation
                || !\hash_equals($expectedDigest, $published['digest'])
                || $published['ips'] !== $next
            ) {
                throw new \RuntimeException('Published permanent ban state failed its generation receipt.');
            }

            return $this->publicBansState($published);
        });
    }

    /**
     * @return array{rules:array<string,mixed>,generation:int,digest:string,signal:string}|null
     */
    private function readRulesStateUnlocked(bool $upgradeLegacy): ?array
    {
        $document = $this->recoverAndReadDocument(
            $this->rulesPath(),
            self::MAX_RULES_BYTES,
            'security rules state',
            fn (string $raw): array => $this->decodeRulesDocument($raw),
        );
        if ($document === null) {
            return null;
        }
        if ($document['legacy'] && $upgradeLegacy) {
            $encoded = $this->encodeRulesEnvelope($document['rules'], 1);
            GatewayProjectStateFilesystem::atomicWrite(
                $this->rulesPath(),
                $encoded,
                self::POSIX_FILE_MODE,
            );
            $document = $this->decodeRulesDocument(
                GatewayProjectStateFilesystem::read(
                    $this->rulesPath(),
                    self::MAX_RULES_BYTES,
                    'security rules state',
                ),
            );
        }

        return $this->publicRulesState($document);
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    private function readPermanentBansStateUnlocked(bool $upgradeLegacy): array
    {
        $document = $this->recoverAndReadDocument(
            $this->permanentBansPath(),
            self::MAX_BANS_BYTES,
            'permanent ban state',
            fn (string $raw): array => $this->decodeBansDocument($raw),
        );
        if ($document === null) {
            return $this->emptyBansState();
        }
        if ($document['legacy'] && $upgradeLegacy) {
            $encoded = $this->encodeBansEnvelope($document['ips'], 1);
            GatewayProjectStateFilesystem::atomicWrite(
                $this->permanentBansPath(),
                $encoded,
                self::POSIX_FILE_MODE,
            );
            $document = $this->decodeBansDocument(
                GatewayProjectStateFilesystem::read(
                    $this->permanentBansPath(),
                    self::MAX_BANS_BYTES,
                    'permanent ban state',
                ),
            );
        }

        return $this->publicBansState($document);
    }

    /**
     * @param \Closure(string):array<string,mixed> $decoder
     * @return array<string,mixed>|null
     */
    private function recoverAndReadDocument(
        string $target,
        int $maximumBytes,
        string $label,
        \Closure $decoder,
    ): ?array {
        $namespace = $this->inspectTargetNamespace($target, $maximumBytes, $label);
        $targetIdentity = @\lstat($target);
        $raw = null;
        $document = null;
        $corruption = null;

        if (\is_array($targetIdentity)) {
            try {
                $raw = GatewayProjectStateFilesystem::read($target, $maximumBytes, $label);
            } catch (\Throwable $throwable) {
                // Unsafe filesystem identities are never recoverable through a
                // sibling artifact because the target namespace is compromised.
                throw $throwable;
            }
            try {
                $document = $decoder($raw);
            } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
                $corruption = $exception;
            }
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException($label . ' path is indeterminate or unsafe.');
        }

        if (\is_array($document)) {
            $this->collectArtifactsForValidTarget(
                $target,
                $maximumBytes,
                $label,
                $decoder,
                $namespace,
                $targetIdentity,
            );
            return $document;
        }

        $backups = \array_values(\array_filter(
            $namespace['artifacts'],
            static fn (array $artifact): bool => $artifact['kind'] === 'backup',
        ));
        if ($backups === []) {
            if ($targetIdentity === false && $namespace['artifacts'] === []) {
                return null;
            }
            throw new \RuntimeException(
                $label . ' is missing or corrupt and has no unique committed backup.',
                0,
                $corruption,
            );
        }
        if (\count($backups) !== 1) {
            throw new \RuntimeException(
                $label . ' recovery is ambiguous because multiple backups exist.',
                0,
                $corruption,
            );
        }
        $backup = $backups[0];
        $backupRaw = GatewayProjectStateFilesystem::read(
            $backup['path'],
            $maximumBytes,
            $label . ' recovery backup',
        );
        try {
            $decoder($backupRaw);
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            throw new \RuntimeException(
                $label . ' unique recovery backup is corrupt.',
                0,
                $exception,
            );
        }
        GatewayProjectStateFilesystem::restoreVerifiedAtomicBackup(
            $backup['path'],
            $target,
            $backup['identity'],
            \is_array($targetIdentity) ? $targetIdentity : null,
            \hash('sha256', $backupRaw),
            \strlen($backupRaw),
            self::POSIX_FILE_MODE,
        );

        $restoredRaw = GatewayProjectStateFilesystem::read($target, $maximumBytes, $label);
        $restored = $decoder($restoredRaw);
        $restoredIdentity = @\lstat($target);
        if (!\is_array($restoredIdentity)) {
            throw new \RuntimeException($label . ' restored target identity is missing.');
        }
        $this->collectArtifactsForValidTarget(
            $target,
            $maximumBytes,
            $label,
            $decoder,
            $this->inspectTargetNamespace($target, $maximumBytes, $label),
            $restoredIdentity,
        );

        return $restored;
    }

    /**
     * @param \Closure(string):array<string,mixed> $decoder
     * @param array{artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>} $namespace
     * @param array<string|int,mixed> $targetIdentity
     */
    private function collectArtifactsForValidTarget(
        string $target,
        int $maximumBytes,
        string $label,
        \Closure $decoder,
        array $namespace,
        array $targetIdentity,
    ): void {
        $native = \array_filter(
            $namespace['artifacts'],
            static fn (array $artifact): bool => $artifact['kind'] !== 'legacy staging file',
        );
        if ($native !== []) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                $maximumBytes,
                $label,
                static function (string $raw) use ($decoder): void {
                    $decoder($raw);
                },
            );
        }

        $rechecked = $this->inspectTargetNamespace($target, $maximumBytes, $label);
        $legacy = \array_filter(
            $rechecked['artifacts'],
            static fn (array $artifact): bool => $artifact['kind'] === 'legacy staging file',
        );
        if ($legacy === []) {
            return;
        }
        $currentTarget = @\lstat($target);
        if (!\is_array($currentTarget) || !$this->sameFileState($targetIdentity, $currentTarget)) {
            throw new \RuntimeException($label . ' changed before legacy artifact cleanup.');
        }
        $second = $this->inspectTargetNamespace($target, $maximumBytes, $label);
        foreach ($legacy as $path => $artifact) {
            $current = $second['artifacts'][$path] ?? null;
            if (!\is_array($current)
                || $current['kind'] !== 'legacy staging file'
                || !$this->sameFileState($artifact['identity'], $current['identity'])
            ) {
                throw new \RuntimeException($label . ' legacy artifact changed before cleanup.');
            }
        }
        foreach ($legacy as $artifact) {
            $currentTarget = @\lstat($target);
            if (!\is_array($currentTarget) || !$this->sameFileState($targetIdentity, $currentTarget)) {
                throw new \RuntimeException($label . ' changed during legacy artifact cleanup.');
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                $label . ' legacy staging file',
                $artifact['identity'],
            )) {
                throw new \RuntimeException('Unable to collect ' . $label . ' legacy staging file.');
            }
        }
    }

    /**
     * @return array{artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>}
     */
    private function inspectTargetNamespace(string $target, int $maximumBytes, string $label): array
    {
        $this->ensureBaseDirectory();
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        $foldedTarget = \strtolower($targetLeaf);
        $prefixes = [
            'staging file' => $targetLeaf . '.tmp-',
            'backup' => $targetLeaf . '.wls-backup-',
            'legacy staging file' => $targetLeaf . '.tmp.',
        ];
        $patterns = [
            'staging file' => '/\A' . \preg_quote($targetLeaf, '/') . '\.tmp-[a-f0-9]{24}\z/D',
            'backup' => '/\A' . \preg_quote($targetLeaf, '/') . '\.wls-backup-[a-f0-9]{16}\z/D',
            'legacy staging file' => '/\A' . \preg_quote($targetLeaf, '/')
                . '\.tmp\.[1-9][0-9]{0,18}\.[a-f0-9]{8}\z/D',
        ];
        $handle = @\opendir($this->baseDirectory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate the security policy state directory.');
        }
        $visited = 0;
        $counts = [];
        $artifacts = [];
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::MAX_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Security policy state directory exceeds its fixed raw entry quota.'
                    );
                }
                $folded = \strtolower($leaf);
                if ($folded === $foldedTarget && $leaf !== $targetLeaf) {
                    throw new \RuntimeException($label . ' contains a non-canonical case alias.');
                }
                $kind = null;
                foreach ($prefixes as $candidateKind => $prefix) {
                    if (\str_starts_with($folded, \strtolower($prefix))) {
                        $kind = $candidateKind;
                        break;
                    }
                }
                if ($kind === null) {
                    continue;
                }
                if (\preg_match($patterns[$kind], $leaf) !== 1) {
                    throw new \RuntimeException(
                        $leaf !== \strtolower($leaf)
                            ? $label . ' recovery contains a non-canonical case alias.'
                            : $label . ' recovery contains a malformed reserved leaf.'
                    );
                }
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                if ($counts[$kind] > self::MAX_ARTIFACTS_PER_KIND) {
                    throw new \RuntimeException($label . ' recovery artifact quota is exhausted.');
                }
                $path = $this->baseDirectory . DIRECTORY_SEPARATOR . $leaf;
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException($label . ' recovery artifact is indeterminate.');
                }
                GatewayProjectStateFilesystem::size(
                    $path,
                    $maximumBytes,
                    $label . ' recovery artifact',
                );
                $after = @\lstat($path);
                if (!\is_array($after) || !$this->sameFileState($before, $after)) {
                    throw new \RuntimeException($label . ' recovery artifact changed during inspection.');
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $after,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);

        return ['artifacts' => $artifacts];
    }

    /**
     * @return array{rules:array<string,mixed>,generation:int,digest:string,legacy:bool}
     */
    private function decodeRulesDocument(string $raw): array
    {
        $decoded = $this->decodeJsonObject($raw, 'Security rules state');
        $hasMetadata = \array_key_exists(self::RULES_METADATA_KEY, $decoded);
        $metadata = $decoded[self::RULES_METADATA_KEY] ?? null;
        unset($decoded[self::RULES_METADATA_KEY]);
        foreach (\array_keys($decoded) as $key) {
            if (\str_starts_with((string)$key, '__wls_')) {
                throw new \UnexpectedValueException('Security rules contain an unknown reserved key.');
            }
        }
        $rules = $this->normalizeRules($decoded);
        $digest = \hash('sha256', $this->canonicalJson($rules));
        if (!$hasMetadata) {
            return [
                'rules' => $rules,
                'generation' => 0,
                'digest' => $digest,
                'legacy' => true,
            ];
        }
        if (!\is_array($metadata)
            || \array_is_list($metadata)
            || \array_keys($metadata) !== ['schema', 'generation', 'digest', 'updated_at']
            || ($metadata['schema'] ?? null) !== self::RULES_SCHEMA
            || !\is_int($metadata['generation'] ?? null)
            || $metadata['generation'] < 1
            || !\is_string($metadata['digest'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $metadata['digest']) !== 1
            || !\is_int($metadata['updated_at'] ?? null)
            || $metadata['updated_at'] < 0
            || !\hash_equals($digest, $metadata['digest'])
        ) {
            throw new \UnexpectedValueException('Security rules state metadata is invalid.');
        }

        return [
            'rules' => $rules,
            'generation' => $metadata['generation'],
            'digest' => $digest,
            'legacy' => false,
        ];
    }

    /** @return array{ips:list<string>,generation:int,digest:string,legacy:bool} */
    private function decodeBansDocument(string $raw): array
    {
        $decoded = $this->decodeJsonObject($raw, 'Permanent ban state');
        $legacy = !\array_key_exists('schema', $decoded);
        if ($legacy) {
            if (\array_keys($decoded) !== ['ips']) {
                throw new \UnexpectedValueException('Legacy permanent ban state schema is invalid.');
            }
            $generation = 0;
            $declaredDigest = null;
        } else {
            if (\array_keys($decoded) !== ['schema', 'generation', 'digest', 'updated_at', 'ips']
                || ($decoded['schema'] ?? null) !== self::BANS_SCHEMA
                || !\is_int($decoded['generation'] ?? null)
                || $decoded['generation'] < 1
                || !\is_string($decoded['digest'] ?? null)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $decoded['digest']) !== 1
                || !\is_int($decoded['updated_at'] ?? null)
                || $decoded['updated_at'] < 0
            ) {
                throw new \UnexpectedValueException('Permanent ban state metadata is invalid.');
            }
            $generation = $decoded['generation'];
            $declaredDigest = $decoded['digest'];
        }
        if (!\is_array($decoded['ips'] ?? null) || !\array_is_list($decoded['ips'])) {
            throw new \UnexpectedValueException('Permanent ban state IP list is invalid.');
        }
        if (\count($decoded['ips']) > self::MAX_PERMANENT_BANS) {
            throw new \UnexpectedValueException('Permanent ban state exceeds its fixed IP quota.');
        }
        $ips = [];
        foreach ($decoded['ips'] as $ip) {
            if (!\is_string($ip)) {
                throw new \UnexpectedValueException('Permanent ban state contains a non-string IP.');
            }
            try {
                $ips[] = $this->normalizeIp($ip);
            } catch (\InvalidArgumentException $exception) {
                throw new \UnexpectedValueException($exception->getMessage(), 0, $exception);
            }
        }
        if (\count($ips) !== \count(\array_unique($ips))) {
            throw new \UnexpectedValueException('Permanent ban state contains duplicate IPs.');
        }
        $sorted = $ips;
        \sort($sorted, SORT_STRING);
        if (!$legacy && $sorted !== $ips) {
            throw new \UnexpectedValueException('Permanent ban state IPs are not canonical.');
        }
        $ips = $sorted;
        $digest = \hash('sha256', $this->canonicalJson($ips));
        if ($declaredDigest !== null && !\hash_equals($digest, $declaredDigest)) {
            throw new \UnexpectedValueException('Permanent ban state digest is invalid.');
        }

        return [
            'ips' => $ips,
            'generation' => $generation,
            'digest' => $digest,
            'legacy' => $legacy,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(string $raw, string $label): array
    {
        if ($raw === '' || \strlen($raw) > self::MAX_RULES_BYTES) {
            throw new \UnexpectedValueException($label . ' has an invalid size.');
        }
        try {
            $object = \json_decode($raw, false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
            $decoded = \json_decode($raw, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException($label . ' contains invalid JSON.', 0, $exception);
        }
        if (!$object instanceof \stdClass || !\is_array($decoded)) {
            throw new \UnexpectedValueException($label . ' must contain one JSON object.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $rules @return array<string,mixed> */
    private function normalizeRules(array $rules): array
    {
        if (\array_is_list($rules) && $rules !== []) {
            throw new \InvalidArgumentException('Security rules must be a JSON object.');
        }
        $nodes = 0;
        $this->assertJsonValue($rules, 0, $nodes);

        /** @var array<string,mixed> $normalized */
        $normalized = $this->canonicalize($rules);
        if (\strlen($this->canonicalJson($normalized)) > self::MAX_RULES_BYTES) {
            throw new \InvalidArgumentException('Security rules exceed their fixed size limit.');
        }

        return $normalized;
    }

    private function assertJsonValue(mixed $value, int $depth, int &$nodes): void
    {
        if (++$nodes > self::MAX_JSON_NODES || $depth > self::MAX_JSON_DEPTH) {
            throw new \InvalidArgumentException('Security rules exceed their fixed JSON complexity limit.');
        }
        if (\is_string($value)) {
            if (\strlen($value) > self::MAX_JSON_STRING_BYTES || !\mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('Security rules contain an invalid or oversized string.');
            }
            return;
        }
        if (\is_int($value) || \is_bool($value) || $value === null) {
            return;
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('Security rules contain a non-finite number.');
            }
            return;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('Security rules contain a non-JSON value.');
        }
        if (!\array_is_list($value)) {
            foreach (\array_keys($value) as $key) {
                if (!\is_string($key)
                    || $key === ''
                    || \strlen($key) > self::MAX_JSON_KEY_BYTES
                    || !\mb_check_encoding($key, 'UTF-8')
                ) {
                    throw new \InvalidArgumentException('Security rules contain an invalid JSON object key.');
                }
            }
        }
        foreach ($value as $child) {
            $this->assertJsonValue($child, $depth + 1, $nodes);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\array_is_list($value)) {
            return \array_map(fn (mixed $child): mixed => $this->canonicalize($child), $value);
        }
        \ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return \json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Security policy state is not JSON encodable.', 0, $exception);
        }
    }

    /** @param array<string,mixed> $rules */
    private function encodeRulesEnvelope(array $rules, int $generation): string
    {
        $digest = \hash('sha256', $this->canonicalJson($rules));
        $payload = [
            self::RULES_METADATA_KEY => [
                'schema' => self::RULES_SCHEMA,
                'generation' => $generation,
                'digest' => $digest,
                'updated_at' => \time(),
            ],
        ] + $rules;
        $encoded = \json_encode(
            $payload,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        if (\strlen($encoded) > self::MAX_RULES_BYTES) {
            throw new \RuntimeException('Security rules publication exceeds its fixed size limit.');
        }

        return $encoded;
    }

    /** @param list<string> $ips */
    private function encodeBansEnvelope(array $ips, int $generation): string
    {
        $payload = [
            'schema' => self::BANS_SCHEMA,
            'generation' => $generation,
            'digest' => \hash('sha256', $this->canonicalJson($ips)),
            'updated_at' => \time(),
            'ips' => $ips,
        ];
        $encoded = \json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        if (\strlen($encoded) > self::MAX_BANS_BYTES) {
            throw new \RuntimeException('Permanent ban publication exceeds its fixed size limit.');
        }

        return $encoded;
    }

    /**
     * @param array{rules:array<string,mixed>,generation:int,digest:string,legacy:bool} $document
     * @return array{rules:array<string,mixed>,generation:int,digest:string,signal:string}
     */
    private function publicRulesState(array $document): array
    {
        return [
            'rules' => $document['rules'],
            'generation' => $document['generation'],
            'digest' => $document['digest'],
            'signal' => 'rules:' . $document['generation'] . ':' . $document['digest'],
        ];
    }

    /**
     * @param array{ips:list<string>,generation:int,digest:string,legacy:bool} $document
     * @return array{ips:list<string>,generation:int,digest:string,signal:string}
     */
    private function publicBansState(array $document): array
    {
        return [
            'ips' => $document['ips'],
            'generation' => $document['generation'],
            'digest' => $document['digest'],
            'signal' => 'bans:' . $document['generation'] . ':' . $document['digest'],
        ];
    }

    /** @return array{ips:list<string>,generation:int,digest:string,signal:string} */
    private function emptyBansState(): array
    {
        $digest = \hash('sha256', $this->canonicalJson([]));
        return [
            'ips' => [],
            'generation' => 0,
            'digest' => $digest,
            'signal' => 'bans:0:' . $digest,
        ];
    }

    private function readLegacyFlagSignalUnlocked(): string
    {
        $this->assertNoCaseAlias(self::LEGACY_FLAG_FILE, 'security rules legacy update flag');
        $raw = GatewayProjectStateFilesystem::readOptional(
            $this->legacyFlagPath(),
            self::MAX_FLAG_BYTES,
            'security rules legacy update flag',
        );
        if ($raw === null) {
            return 'legacy-flag:none';
        }
        $value = \trim($raw);
        if (\preg_match('/\A[0-9]{1,20}\z/D', $value) !== 1) {
            return 'legacy-flag:invalid:' . \hash('sha256', $raw);
        }

        return 'legacy-flag:' . \hash('sha256', $value);
    }

    private function readLegacyFlagSignalOptimistic(): string
    {
        $raw = GatewayProjectStateFilesystem::readOptional(
            $this->legacyFlagPath(),
            self::MAX_FLAG_BYTES,
            'runtime security rules legacy update flag',
        );
        if ($raw === null) {
            return 'legacy-flag:none';
        }
        $value = \trim($raw);
        if (\preg_match('/\A[0-9]{1,20}\z/D', $value) !== 1) {
            return 'legacy-flag:invalid:' . \hash('sha256', $raw);
        }

        return 'legacy-flag:' . \hash('sha256', $value);
    }

    private function assertNoCaseAlias(string $expectedLeaf, string $label): void
    {
        $handle = @\opendir($this->baseDirectory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate the security policy state directory.');
        }
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::MAX_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Security policy state directory exceeds its fixed raw entry quota.'
                    );
                }
                if (\strtolower($leaf) === \strtolower($expectedLeaf) && $leaf !== $expectedLeaf) {
                    throw new \RuntimeException($label . ' contains a non-canonical case alias.');
                }
            }
        } finally {
            @\closedir($handle);
        }
    }

    private function normalizeIp(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === ''
            || \strlen($ip) > 64
            || \str_contains($ip, '%')
            || \filter_var($ip, FILTER_VALIDATE_IP) === false
        ) {
            throw new \InvalidArgumentException('Permanent ban state contains an invalid IP address.');
        }
        $packed = @\inet_pton($ip);
        $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($canonical) || $canonical === '') {
            throw new \InvalidArgumentException('Permanent ban state contains an invalid IP address.');
        }

        return \strtolower($canonical);
    }

    /**
     * @template TResult
     * @param \Closure(): TResult $operation
     * @return TResult
     */
    private function withLock(\Closure $operation): mixed
    {
        $this->ensureBaseDirectory();
        $this->assertNoCaseAlias(self::LOCK_FILE, 'security policy state lock');
        $lockPath = $this->baseDirectory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $pid = (int)\getmypid();
        $lock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            $this->lockTimeoutSeconds,
            static fn (): array => [
                'pid' => $pid,
                'purpose' => 'security-policy-state',
                'started_at' => \date('Y-m-d H:i:s'),
            ],
        );
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Unable to acquire the verified security policy state lock within '
                . \number_format($this->lockTimeoutSeconds, 3, '.', '')
                . ' seconds.'
            );
        }
        try {
            $this->ensureBaseDirectory();
            $this->assertNoCaseAlias(self::LOCK_FILE, 'security policy state lock');
            return $operation();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    private function ensureBaseDirectory(): void
    {
        if (!\is_dir($this->baseDirectory)
            && !@\mkdir($this->baseDirectory, 0755, true)
            && !\is_dir($this->baseDirectory)
        ) {
            throw new \RuntimeException('Unable to create the security policy state directory.');
        }
        $status = @\lstat($this->baseDirectory);
        if (!\is_array($status)
            || \is_link($this->baseDirectory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Security policy state directory is unsafe.');
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }

        return true;
    }
}
