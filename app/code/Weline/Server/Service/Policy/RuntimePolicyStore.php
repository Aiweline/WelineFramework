<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;

final class RuntimePolicyStore
{
    private const POSIX_DIRECTORY_MODE = 0700;

    private const POSIX_FILE_MODE = 0600;

    public function __construct(
        private readonly ?string $baseDirectory = null,
    ) {
    }

    public function save(string $instance, RuntimePolicyBundle $bundle): string
    {
        return $this->withLock($instance, function () use ($instance, $bundle): string {
            return $this->saveUnlocked($instance, $bundle);
        });
    }

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

    public function activate(string $instance, string $digest): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $this->loadUnlocked($instance, $digest);
            $state = $this->readStateUnlocked($instance);
            $current = (string)($state['active_digest'] ?? '');
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

    public function rollback(string $instance, ?string $digest = null): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $state = $this->readStateUnlocked($instance);
            $target = $digest !== null && $digest !== ''
                ? $digest
                : (string)($state['previous_digest'] ?? '');
            if ($target === '') {
                throw new \RuntimeException('No previous runtime policy bundle is available for rollback.');
            }
            $this->loadUnlocked($instance, $target);
            $current = (string)($state['active_digest'] ?? '');
            $state['active_digest'] = $target;
            $state['staged_digest'] = '';
            $state['previous_digest'] = $current !== $target ? $current : '';
            $state['updated_at'] = \time();
            $this->writeStateUnlocked($instance, $state);
            return $state;
        });
    }

    public function prepareRollback(string $instance, ?string $digest = null): array
    {
        return $this->withLock($instance, function () use ($instance, $digest): array {
            $state = $this->readStateUnlocked($instance);
            $target = $digest !== null && $digest !== ''
                ? $digest
                : (string)($state['previous_digest'] ?? '');
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
        $digest = (string)($this->state($instance)['active_digest'] ?? '');
        return $digest !== '' ? $this->load($instance, $digest) : null;
    }

    public function staged(string $instance): ?RuntimePolicyBundle
    {
        $digest = (string)($this->state($instance)['staged_digest'] ?? '');
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
        $this->writePhpArrayAtomically($target, $bundle->toArray(), false);
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

    private function writeStateUnlocked(string $instance, array $state): void
    {
        $target = $this->ensureInstanceDirectory($instance) . DS . 'state.php';
        $this->writePhpArrayAtomically($target, [
            'active_digest' => (string)($state['active_digest'] ?? ''),
            'staged_digest' => (string)($state['staged_digest'] ?? ''),
            'previous_digest' => (string)($state['previous_digest'] ?? ''),
            'updated_at' => (int)($state['updated_at'] ?? \time()),
        ], true);
    }

    private function writePhpArrayAtomically(string $target, array $data, bool $replace): void
    {
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . \var_export($data, true) . ";\n";
        $temporary = $target . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        $bytes = \file_put_contents($temporary, $payload, \LOCK_EX);
        if ($bytes === false || $bytes !== \strlen($payload)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to write runtime policy file: ' . $target);
        }
        try {
            $this->secureFilePermissions($temporary);
        } catch (\Throwable $throwable) {
            @\unlink($temporary);
            throw $throwable;
        }
        if ($replace && \PHP_OS_FAMILY === 'Windows' && \is_file($target)) {
            @\unlink($target);
        }
        if (!@\rename($temporary, $target)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to atomically publish runtime policy file: ' . $target);
        }
        // rename(2) preserves the private temporary-file mode on POSIX. Apply
        // and verify it once more after publication so an unexpected platform
        // or filesystem cannot leave an active bundle/state broadly readable.
        $this->secureFilePermissions($target);
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($target, true);
        }
        \clearstatcache(true, $target);
    }

    private function instanceDirectory(string $instance): string
    {
        return \rtrim($this->baseDirectory ?? (BP . 'var' . DS . 'server' . DS . 'policy'), '/\\')
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
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }

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
            if (\is_link($path) || !\is_file($path)) {
                throw new \RuntimeException('Runtime policy artifact must be a regular file: ' . $path);
            }
            $this->secureFilePermissions($path);
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
        $lock = @\fopen($lockPath, 'c+b');
        if (!\is_resource($lock)) {
            throw new \RuntimeException('Unable to open runtime policy store lock.');
        }
        try {
            $this->secureFilePermissions($lockPath);
            if (!\flock($lock, \LOCK_EX)) {
                throw new \RuntimeException('Unable to lock runtime policy store.');
            }
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
