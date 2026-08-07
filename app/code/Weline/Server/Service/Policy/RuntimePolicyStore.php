<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

/** @phpstan-type PolicyState array{active_digest:string,staged_digest:string,previous_digest:string,updated_at:int} */
final class RuntimePolicyStore
{
    private const POSIX_DIRECTORY_MODE = 0700;

    private const POSIX_FILE_MODE = 0600;

    private const MAX_BUNDLE_BYTES = 4_194_304;

    private const MAX_STATE_BYTES = 65_536;

    private const MAX_RECOVERY_ARTIFACTS_PER_KIND = 8;

    private const MAX_DIRECTORY_ENTRIES = 16_384;

    public function __construct(
        private readonly ?string $baseDirectory = null,
        private readonly float $lockTimeoutSeconds = 3.0,
    ) {
        if (!\is_finite($this->lockTimeoutSeconds)
            || $this->lockTimeoutSeconds <= 0.0
            || $this->lockTimeoutSeconds > 300.0
        ) {
            throw new \InvalidArgumentException(
                'Runtime policy store lock timeout must be within (0, 300] seconds.'
            );
        }
    }

    public function save(string $instance, RuntimePolicyBundle $bundle): string
    {
        return $this->withLock($instance, function () use ($instance, $bundle): string {
            return $this->saveUnlocked($instance, $bundle);
        });
    }

    /** @return PolicyState */
    public function stage(string $instance, RuntimePolicyBundle $bundle): array
    {
        return $this->withLock($instance, function () use ($instance, $bundle): array {
            $this->saveUnlocked($instance, $bundle);
            $state = $this->readStateUnlocked($instance);
            $state['staged_digest'] = $bundle->digest;
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    /** @return PolicyState */
    public function stageDigest(string $instance, string $digest): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $this->loadUnlocked($instance, $digest);
            $state = $this->readStateUnlocked($instance);
            $state['staged_digest'] = $digest;
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    /** @return PolicyState */
    public function activate(string $instance, string $digest): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $this->loadUnlocked($instance, $digest);
            $state = $this->readStateUnlocked($instance);
            $current = $state['active_digest'];
            if ($current !== '' && $current !== $digest) {
                $state['previous_digest'] = $current;
            }
            $state['active_digest'] = $digest;
            $state['staged_digest'] = '';
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    /** @return PolicyState */
    public function rollback(string $instance, ?string $digest = null): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $state = $this->readStateUnlocked($instance);
            $target = $digest !== null && $digest !== ''
                ? $digest
                : $state['previous_digest'];
            if ($target === '') {
                throw new \RuntimeException('No previous runtime policy bundle is available for rollback.');
            }
            $this->loadUnlocked($instance, $target);
            $current = $state['active_digest'];
            $state['active_digest'] = $target;
            $state['staged_digest'] = '';
            $state['previous_digest'] = $current !== $target ? $current : '';
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    /** @return PolicyState */
    public function prepareRollback(string $instance, ?string $digest = null): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $state = $this->readStateUnlocked($instance);
            $target = $digest !== null && $digest !== ''
                ? $digest
                : $state['previous_digest'];
            if ($target === '') {
                throw new \RuntimeException('No previous runtime policy bundle is available for rollback.');
            }
            $this->loadUnlocked($instance, $target);
            $state['staged_digest'] = $target;
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    public function load(string $instance, string $digest): RuntimePolicyBundle
    {
        return $this->loadUnlocked($instance, $digest);
    }

    public function active(string $instance): ?RuntimePolicyBundle
    {
        $digest = $this->state($instance)['active_digest'];
        return $digest !== '' ? $this->load($instance, $digest) : null;
    }

    public function staged(string $instance): ?RuntimePolicyBundle
    {
        $digest = $this->state($instance)['staged_digest'];
        return $digest !== '' ? $this->load($instance, $digest) : null;
    }

    /**
     * @return array{active_digest:string,staged_digest:string,previous_digest:string,updated_at:int}
     */
    public function state(string $instance): array
    {
        return $this->readStateUnlocked($instance);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $instance): array
    {
        $state = $this->state($instance);
        $bundle = $state['active_digest'] !== '' ? $this->load($instance, $state['active_digest']) : null;
        return $state + [
            'instance' => $this->normalizeInstance($instance),
            'policy_state' => $state['staged_digest'] !== '' ? 'staged' : ($bundle !== null ? 'active' : 'empty'),
            'active_bundle' => $bundle?->toArray(),
        ];
    }

    private function loadUnlocked(string $instance, string $digest): RuntimePolicyBundle
    {
        $digest = $this->normalizeDigest($digest);
        $path = $this->ensureInstanceDirectory($instance) . DS . $digest . '.php';
        if (!\is_file($path)) {
            throw new \RuntimeException('Runtime policy bundle does not exist: ' . $digest);
        }
        $this->secureFilePermissions($path);
        $data = require $path;
        if (!\is_array($data)) {
            throw new \RuntimeException('Runtime policy bundle file is invalid: ' . $path);
        }
        $bundle = RuntimePolicyBundle::fromArray($data);
        if (!\hash_equals($digest, $bundle->digest)) {
            throw new \RuntimeException('Runtime policy bundle filename does not match its digest.');
        }
        return $bundle;
    }

    private function saveUnlocked(string $instance, RuntimePolicyBundle $bundle): string
    {
        $directory = $this->ensureInstanceDirectory($instance);
        $target = $directory . DS . $bundle->digest . '.php';
        if (\is_file($target)) {
            $this->secureFilePermissions($target);
            $existing = $this->loadUnlocked($instance, $bundle->digest);
            if (!\hash_equals($existing->digest, $bundle->digest)) {
                throw new \RuntimeException('Existing runtime policy bundle failed integrity validation.');
            }
            return $target;
        }
        $this->writePhpArrayAtomically($target, $bundle->toArray());
        return $target;
    }

    /**
     * @return array{active_digest:string,staged_digest:string,previous_digest:string,updated_at:int}
     */
    private function readStateUnlocked(string $instance): array
    {
        $path = $this->ensureInstanceDirectory($instance) . DS . 'state.php';
        if (\is_file($path)) {
            $this->secureFilePermissions($path);
        }
        $data = \is_file($path) ? require $path : [];
        if (!\is_array($data)) {
            throw new \RuntimeException('Runtime policy state file is invalid: ' . $path);
        }
        $state = [
            'active_digest' => (string)($data['active_digest'] ?? ''),
            'staged_digest' => (string)($data['staged_digest'] ?? ''),
            'previous_digest' => (string)($data['previous_digest'] ?? ''),
            'updated_at' => (int)($data['updated_at'] ?? 0),
        ];
        foreach (['active_digest', 'staged_digest', 'previous_digest'] as $key) {
            if ($state[$key] !== '') {
                $state[$key] = $this->normalizeDigest($state[$key]);
            }
        }
        return $state;
    }

    /** @param PolicyState $state */
    private function writeStateUnlocked(string $instance, array $state): void
    {
        $target = $this->ensureInstanceDirectory($instance) . DS . 'state.php';
        $this->writePhpArrayAtomically($target, [
            'active_digest' => $state['active_digest'],
            'staged_digest' => $state['staged_digest'],
            'previous_digest' => $state['previous_digest'],
            'updated_at' => $state['updated_at'],
        ]);
    }

    /** @param array<string,mixed> $data */
    private function writePhpArrayAtomically(string $target, array $data): void
    {
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . \var_export($data, true) . ";\n";
        $maximumBytes = \basename($target) === 'state.php'
            ? self::MAX_STATE_BYTES
            : self::MAX_BUNDLE_BYTES;
        if (\strlen($payload) > $maximumBytes) {
            throw new \RuntimeException('Runtime policy publication exceeds its fixed size limit.');
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $target,
            $payload,
            self::POSIX_FILE_MODE,
        );
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($target, true);
        }
        \clearstatcache(true, $target);
    }

    private function recoverInterruptedPublications(string $directory): void
    {
        $artifacts = $this->publicationRecoveryArtifacts($directory);
        if ($artifacts === []) {
            return;
        }
        $targets = [];
        foreach ($artifacts as $artifact) {
            $target = $artifact['target'];
            if (!isset($targets[$target])) {
                $targets[$target] = $this->validateRecoveryTarget(
                    $target,
                    $artifact['target_leaf'],
                );
            }
        }

        // No evidence is removed until every reserved leaf and every paired
        // target has passed one complete, bounded preflight.
        $rechecked = $this->publicationRecoveryArtifacts($directory);
        if (\array_keys($artifacts) !== \array_keys($rechecked)) {
            throw new \RuntimeException(
                'Runtime policy recovery artifact set changed before cleanup.'
            );
        }
        foreach ($artifacts as $path => $artifact) {
            $current = $rechecked[$path] ?? null;
            if (!\is_array($current)
                || !$this->sameFileState($artifact['identity'], $current['identity'])
                || !\hash_equals($artifact['kind'], $current['kind'])
                || !\hash_equals($artifact['target'], $current['target'])
            ) {
                throw new \RuntimeException(
                    'Runtime policy recovery artifact changed before cleanup.'
                );
            }
        }
        foreach ($targets as $target => $identity) {
            $validated = $this->validateRecoveryTarget(
                $target,
                \basename($target),
            );
            if (!$this->sameFileState($identity, $validated)) {
                throw new \RuntimeException(
                    'Runtime policy recovery target changed before cleanup.'
                );
            }
        }

        foreach ($artifacts as $artifact) {
            $targetStatus = @\lstat($artifact['target']);
            if (!\is_array($targetStatus)
                || !$this->sameFileState($targets[$artifact['target']], $targetStatus)
            ) {
                throw new \RuntimeException(
                    'Runtime policy recovery target changed during cleanup.'
                );
            }
            GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                'runtime policy ' . $artifact['kind'],
                $artifact['identity'],
            );
        }
    }

    /**
     * @return array<string,array{path:string,target:string,target_leaf:string,kind:string,identity:array<string|int,mixed>}>
     */
    private function publicationRecoveryArtifacts(string $directory): array
    {
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate runtime policy recovery artifacts.');
        }
        $artifacts = [];
        $counts = [];
        $visited = 0;
        $reservedPrefix = '/\A(?:state\.php|[a-f0-9]{64}\.php)'
            . '(?:\.tmp-|\.wls-backup-|\.tmp\.)/D';
        $exact = '/\A(?<target>state\.php|[a-f0-9]{64}\.php)'
            . '(?:(?<staging>\.tmp-[a-f0-9]{24})'
            . '|(?<backup>\.wls-backup-[a-f0-9]{16})'
            . '|(?<legacy>\.tmp\.[1-9][0-9]{0,18}\.[a-f0-9]{8}))\z/D';
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::MAX_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Runtime policy recovery directory exceeds its raw entry quota.'
                    );
                }
                if (\preg_match($reservedPrefix, \strtolower($leaf)) !== 1) {
                    continue;
                }
                if (\preg_match($exact, $leaf, $match) !== 1) {
                    throw new \RuntimeException(
                        $leaf !== \strtolower($leaf)
                            ? 'Runtime policy recovery contains a non-canonical case alias.'
                            : 'Runtime policy recovery contains a malformed reserved leaf.'
                    );
                }
                $kind = ($match['staging'] ?? '') !== ''
                    ? 'staging file'
                    : ((($match['backup'] ?? '') !== '') ? 'backup' : 'legacy staging file');
                $targetLeaf = (string)$match['target'];
                $countKey = $targetLeaf . ':' . $kind;
                $counts[$countKey] = ($counts[$countKey] ?? 0) + 1;
                if ($counts[$countKey] > self::MAX_RECOVERY_ARTIFACTS_PER_KIND) {
                    throw new \RuntimeException(
                        'Runtime policy recovery artifact quota is exhausted.'
                    );
                }
                $path = $directory . DS . $leaf;
                $maximumBytes = $targetLeaf === 'state.php'
                    ? self::MAX_STATE_BYTES
                    : self::MAX_BUNDLE_BYTES;
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException(
                        'Runtime policy recovery artifact is indeterminate.'
                    );
                }
                GatewayProjectStateFilesystem::size(
                    $path,
                    $maximumBytes,
                    'runtime policy recovery artifact',
                );
                $after = @\lstat($path);
                if (!\is_array($after) || !$this->sameFileState($before, $after)) {
                    throw new \RuntimeException(
                        'Runtime policy recovery artifact changed during inspection.'
                    );
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'target' => $directory . DS . $targetLeaf,
                    'target_leaf' => $targetLeaf,
                    'kind' => $kind,
                    'identity' => $after,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);

        return $artifacts;
    }

    /** @return array<string|int,mixed> */
    private function validateRecoveryTarget(string $target, string $targetLeaf): array
    {
        $maximumBytes = $targetLeaf === 'state.php'
            ? self::MAX_STATE_BYTES
            : self::MAX_BUNDLE_BYTES;
        $before = @\lstat($target);
        if (!\is_array($before)) {
            throw new \RuntimeException(
                'Runtime policy recovery paired target is missing or unsafe.'
            );
        }
        $contents = GatewayProjectStateFilesystem::read(
            $target,
            $maximumBytes,
            'runtime policy recovery paired target',
        );
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($target, true);
        }
        $data = (static fn (string $path): mixed => require $path)($target);
        $after = @\lstat($target);
        if (!\is_array($data)
            || !\is_array($after)
            || !$this->sameFileState($before, $after)
            || (int)$after['size'] !== \strlen($contents)
        ) {
            throw new \RuntimeException(
                'Runtime policy recovery paired target is corrupt or changed.'
            );
        }
        if ($targetLeaf === 'state.php') {
            foreach (['active_digest', 'staged_digest', 'previous_digest'] as $key) {
                if (!\array_key_exists($key, $data) || !\is_string($data[$key])) {
                    throw new \RuntimeException('Runtime policy recovery state target is corrupt.');
                }
                if ($data[$key] !== '') {
                    $this->normalizeDigest($data[$key]);
                }
            }
            if (!\array_key_exists('updated_at', $data)
                || !\is_int($data['updated_at'])
                || $data['updated_at'] < 0
            ) {
                throw new \RuntimeException('Runtime policy recovery state target is corrupt.');
            }
        } else {
            $digest = \substr($targetLeaf, 0, 64);
            $bundle = RuntimePolicyBundle::fromArray($data);
            if (!\hash_equals($digest, $bundle->digest)) {
                throw new \RuntimeException(
                    'Runtime policy recovery bundle target digest is corrupt.'
                );
            }
        }

        return $after;
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

    private function instanceDirectory(string $instance): string
    {
        $baseDirectory = $this->baseDirectory;
        if ($baseDirectory === null) {
            if (!\defined('BP')) {
                throw new \RuntimeException('Runtime policy store project root is unavailable.');
            }
            $baseDirectory = (string)\constant('BP')
                . 'var' . DS . 'server' . DS . 'policy';
        }

        return \rtrim($baseDirectory, '/\\')
            . DS . $this->normalizeInstance($instance);
    }

    private function ensureInstanceDirectory(string $instance): string
    {
        $directory = $this->instanceDirectory($instance);
        if (!\is_dir($directory)
            && !@\mkdir($directory, self::POSIX_DIRECTORY_MODE, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create runtime policy directory: ' . $directory);
        }
        $this->secureDirectoryPermissions($directory);
        $this->secureExistingPolicyFiles($directory);
        return $directory;
    }

    /**
     * Tighten bundles created by older WLS versions before any current
     * operation reads or publishes state. Runtime policy directories contain
     * only immutable PHP bundles, the atomic state file and the store lock;
     * anything with one of those reserved names must be a private regular
     * file, otherwise the store fails closed.
     */
    private function secureExistingPolicyFiles(string $directory): void
    {
        $entries = @\scandir($directory);
        if (!\is_array($entries)) {
            throw new \RuntimeException('Unable to inspect runtime policy directory: ' . $directory);
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry !== '.lock' && !\str_ends_with($entry, '.php')) {
                continue;
            }

            $path = $directory . DS . $entry;
            $status = @\lstat($path);
            if (!\is_array($status)
                || \is_link($path)
                || ((((int)$status['mode']) & 0170000) !== 0100000)
                || (int)$status['nlink'] !== 1
            ) {
                throw new \RuntimeException(
                    $entry === '.lock'
                        ? 'Runtime policy store lock must be a single-link regular file: ' . $path
                        : 'Runtime policy artifact must be a single-link regular file: ' . $path
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                $this->secureFilePermissions($path);
            }
        }
    }

    private function normalizeInstance(string $instance): string
    {
        $instance = \trim($instance);
        if ($instance === '' || \strlen($instance) > 64 || \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $instance) !== 1) {
            throw new \InvalidArgumentException('Invalid WLS instance name for runtime policy store.');
        }
        return $instance;
    }

    private function normalizeDigest(string $digest): string
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new \InvalidArgumentException('Invalid runtime policy digest.');
        }
        return $digest;
    }

    private function withLock(string $instance, callable $operation): mixed
    {
        $directory = $this->ensureInstanceDirectory($instance);
        $lockPath = $directory . DS . '.lock';
        $pid = (int)\getmypid();
        $lock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            $this->lockTimeoutSeconds,
            static fn (): array => [
                'pid' => $pid,
                'instance' => $instance,
                'purpose' => 'runtime-policy-publish',
                'started_at' => \date('Y-m-d H:i:s'),
            ],
        );
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Unable to acquire the verified runtime policy store lock within '
                . \number_format($this->lockTimeoutSeconds, 3, '.', '')
                . ' seconds.'
            );
        }
        try {
            $this->recoverInterruptedPublications($directory);
            return $operation();
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    private function secureDirectoryPermissions(string $path): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        if (\is_link($path)) {
            throw new \RuntimeException('Runtime policy directory must not be a symbolic link: ' . $path);
        }
        if (!@\chmod($path, self::POSIX_DIRECTORY_MODE)) {
            throw new \RuntimeException('Unable to secure runtime policy directory: ' . $path);
        }
        \clearstatcache(true, $path);
        $mode = @\fileperms($path);
        if ($mode === false || ($mode & 0777) !== self::POSIX_DIRECTORY_MODE) {
            throw new \RuntimeException('Runtime policy directory permissions are not private: ' . $path);
        }
    }

    private function secureFilePermissions(string $path): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        if (\is_link($path)) {
            throw new \RuntimeException('Runtime policy file must not be a symbolic link: ' . $path);
        }
        if (!@\chmod($path, self::POSIX_FILE_MODE)) {
            throw new \RuntimeException('Unable to secure runtime policy file: ' . $path);
        }
        \clearstatcache(true, $path);
        $mode = @\fileperms($path);
        if ($mode === false || ($mode & 0777) !== self::POSIX_FILE_MODE) {
            throw new \RuntimeException('Runtime policy file permissions are not private: ' . $path);
        }
    }
}
