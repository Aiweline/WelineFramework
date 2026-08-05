<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Stores the host-bound project capability outside project configuration.
 *
 * The credential deliberately lives under var/: it is not a project fact
 * source, must not be committed, and becomes unusable when the project moves
 * to a host with a different host id.
 */
final class GatewayCredentialStore
{
    private const MAX_CREDENTIAL_FILES = 64;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly ?string $projectRoot = null,
    ) {
    }

    /**
     * @return array{
     *   schema_version:int,
     *   protocol:string,
     *   host_id:string,
     *   project_uuid:string,
     *   credential_id:string,
     *   credential_generation:int,
     *   secret:string
     * }
     */
    public function load(?string $expectedProjectUuid = null): array
    {
        $hostId = $this->hostId();
        $projectRoot = $this->root();
        $directory = $this->credentialDirectory();
        $directoryStatus = @\lstat($directory);
        if (!\is_array($directoryStatus)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Project gateway credential directory is unsafe.'
                );
            }
            throw new \RuntimeException(
                'This project is not enrolled on the trusted WLS 2.0 host gateway.'
            );
        }
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $this->assertProjectDirectoryChain($directory, $projectRoot);
        $this->assertCredentialDirectory($directory, $projectRoot, $owner, true);
        $file = $directory . DIRECTORY_SEPARATOR . $hostId . '.cred';
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $file,
            16_384,
            'Host-bound WLS Gateway project credential',
        );
        if ($encoded === null) {
            throw new \RuntimeException(
                'This project is not enrolled on the trusted WLS 2.0 host gateway.'
            );
        }
        $decoded = \json_decode($encoded, true);
        if (!\is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 1
            || !\is_string($decoded['protocol'] ?? null)
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($decoded['protocol'] ?? ''))
            || !\is_string($decoded['host_id'] ?? null)
            || !\hash_equals($hostId, (string)($decoded['host_id'] ?? ''))
            || !\is_string($decoded['project_uuid'] ?? null)
            || !\hash_equals(
                (string)$decoded['project_uuid'],
                \strtolower(\trim((string)$decoded['project_uuid'])),
            )
            || !$this->isUuidV4((string)$decoded['project_uuid'])
            || !\is_string($decoded['credential_id'] ?? null)
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($decoded['credential_id'] ?? '')) !== 1
            || (\array_key_exists('credential_generation', $decoded)
                && (!\is_int($decoded['credential_generation'])
                    || (int)$decoded['credential_generation'] < 1))
            || !\is_string($decoded['secret'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($decoded['secret'] ?? '')) !== 1
            || !\is_string($decoded['issued_at'] ?? null)
            || \strlen((string)$decoded['issued_at']) > 128
            || \strtotime((string)$decoded['issued_at']) === false
            || ($expectedProjectUuid !== null
                && !\hash_equals(
                    \strtolower(\trim($expectedProjectUuid)),
                    (string)$decoded['project_uuid'],
                ))
        ) {
            throw new \RuntimeException(
                'The host-bound WLS Gateway project credential is invalid.'
            );
        }
        return [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => (string)$decoded['project_uuid'],
            'credential_id' => (string)$decoded['credential_id'],
            // Credentials written before local generation persistence remain
            // usable; all newly installed Controller responses carry it.
            'credential_generation' => (int)($decoded['credential_generation'] ?? 1),
            'secret' => (string)$decoded['secret'],
            'issued_at' => (string)$decoded['issued_at'],
        ];
    }

    /** @param array<string,mixed> $credential */
    public function install(array $credential, string $expectedProjectUuid): string
    {
        $payload = $this->credentialPayload($credential, $expectedProjectUuid);
        $hostId = (string)$payload['host_id'];

        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $projectOwner = @\lstat($projectRoot);
        if (!\is_array($projectOwner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $file = $directory . DIRECTORY_SEPARATOR . $hostId . '.cred';
        $encoded = \json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $projectOwner,
        );

        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use ($directory, $file, $encoded, $projectOwner, $seal): string {
                $credentials = $this->credentialFiles($directory);
                GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600, $seal);
                $this->assertProjectOwnedRegularFile($file, $projectOwner, \strlen($encoded));
                foreach ($credentials as $candidate) {
                    if (\str_ends_with($candidate, '.cred')
                        && !\hash_equals($file, $candidate)
                    ) {
                        GatewayProjectStateFilesystem::removeRegular(
                            $candidate,
                            'stale host-bound gateway credential',
                        );
                    }
                }
                return $file;
            },
            $seal,
        );
    }

    /** @param array<string,mixed> $credential */
    public function installPending(
        array $credential,
        string $expectedProjectUuid,
        string $rotationId,
    ): string {
        $rotationId = $this->rotationId($rotationId);
        $payload = $this->credentialPayload($credential, $expectedProjectUuid);
        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $file = $this->pendingFile($directory, $rotationId);
        $encoded = \json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use ($directory, $file, $encoded, $owner, $seal): string {
                $this->credentialFiles($directory);
                GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600, $seal);
                $this->assertProjectOwnedRegularFile($file, $owner, \strlen($encoded));
                return $file;
            },
            $seal,
        );
    }

    /** @return array<string,mixed> */
    public function loadPending(string $rotationId, string $expectedProjectUuid): array
    {
        $directory = $this->credentialDirectory();
        $projectRoot = $this->root();
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $this->assertProjectDirectoryChain($directory, $projectRoot);
        $this->assertCredentialDirectory(
            $directory,
            $projectRoot,
            $owner,
            true,
        );
        return $this->readCredentialPayload(
            $this->pendingFile($directory, $this->rotationId($rotationId)),
            $expectedProjectUuid,
        );
    }

    /** @return array<string,mixed> */
    public function commitPending(
        string $rotationId,
        string $expectedProjectUuid,
    ): array {
        $rotationId = $this->rotationId($rotationId);
        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $active = $directory . DIRECTORY_SEPARATOR . $this->hostId() . '.cred';
        $pending = $this->pendingFile($directory, $rotationId);
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use (
                $directory,
                $active,
                $pending,
                $expectedProjectUuid,
                $owner,
                $seal,
            ): array {
                $files = $this->credentialFiles($directory);
                $payload = $this->readCredentialPayload($pending, $expectedProjectUuid);
                $encoded = \json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL;
                GatewayProjectStateFilesystem::atomicWrite($active, $encoded, 0600, $seal);
                $this->assertProjectOwnedRegularFile($active, $owner, \strlen($encoded));
                GatewayProjectStateFilesystem::removeRegular(
                    $pending,
                    'committed pending gateway credential',
                );
                foreach ($files as $candidate) {
                    if (\str_ends_with($candidate, '.cred')
                        && !\hash_equals($active, $candidate)
                    ) {
                        GatewayProjectStateFilesystem::removeRegular(
                            $candidate,
                            'stale host-bound gateway credential',
                        );
                    }
                }
                return $payload;
            },
            $seal,
        );
    }

    public function abortPending(string $rotationId): void
    {
        $directory = $this->credentialDirectory();
        if (!\is_dir($directory) || \is_link($directory)) {
            return;
        }
        $owner = @\lstat($this->root());
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            fn (): bool => GatewayProjectStateFilesystem::removeRegular(
                $this->pendingFile($directory, $this->rotationId($rotationId)),
                'aborted pending gateway credential',
            ),
            $seal,
        );
    }

    public function remove(): bool
    {
        $directory = $this->credentialDirectory();
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException('Project gateway credential directory is unsafe.');
            }
            return true;
        }
        $projectRoot = $this->root();
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $this->assertCredentialDirectory($directory, $projectRoot, $owner, true);
        $this->assertProjectDirectoryChain($directory, $projectRoot);
        $file = $directory . DIRECTORY_SEPARATOR . $this->hostId() . '.cred';
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            static fn (): bool => GatewayProjectStateFilesystem::removeRegular(
                $file,
                'host-bound gateway credential',
            ),
            $seal,
        );
    }

    public function hostId(): string
    {
        $hostId = \strtolower(\trim(GatewayProjectStateFilesystem::read(
            $this->paths->hostIdFile(),
            33,
            'Trusted WLS Gateway host identity',
        )));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1) {
            throw new \RuntimeException('Trusted WLS Gateway host identity is invalid.');
        }
        return $hostId;
    }

    /** @param array<string,mixed> $credential @return array<string,mixed> */
    private function credentialPayload(
        array $credential,
        string $expectedProjectUuid,
    ): array {
        $hostId = $this->hostId();
        $expectedProjectUuid = \strtolower(\trim($expectedProjectUuid));
        $projectUuid = \strtolower(\trim((string)($credential['project_uuid'] ?? '')));
        $credentialId = \strtolower(\trim((string)($credential['credential_id'] ?? '')));
        $credentialGeneration = $credential['credential_generation'] ?? 1;
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        $issuedAt = \trim((string)($credential['issued_at'] ?? \gmdate(DATE_ATOM)));
        if (!\hash_equals($hostId, \strtolower(\trim((string)(
            $credential['host_id'] ?? ''
        ))))
            || !$this->isUuidV4($expectedProjectUuid)
            || !\hash_equals($expectedProjectUuid, $projectUuid)
            || !\hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($credential['protocol'] ?? GatewayPaths::PROTOCOL),
            )
            || \preg_match('/\A[a-f0-9]{32}\z/D', $credentialId) !== 1
            || !\is_int($credentialGeneration)
            || $credentialGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1
            || $issuedAt === ''
            || \strlen($issuedAt) > 128
            || \strtotime($issuedAt) === false
        ) {
            throw new \RuntimeException(
                'Gateway enrollment returned an invalid host-bound credential.'
            );
        }
        return [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'credential_generation' => $credentialGeneration,
            'secret' => $secret,
            'issued_at' => $issuedAt,
        ];
    }

    /** @return array<string,mixed> */
    private function readCredentialPayload(
        string $file,
        string $expectedProjectUuid,
    ): array {
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $file,
            16_384,
            'Pending host-bound WLS Gateway project credential',
        );
        $decoded = $encoded !== null ? \json_decode($encoded, true) : null;
        if (!\is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
            throw new \RuntimeException('Pending WLS Gateway credential is missing or invalid.');
        }
        return $this->credentialPayload($decoded, $expectedProjectUuid);
    }

    private function rotationId(string $rotationId): string
    {
        $rotationId = \strtolower(\trim($rotationId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $rotationId) !== 1) {
            throw new \InvalidArgumentException('Gateway credential rotation identity is invalid.');
        }
        return $rotationId;
    }

    private function pendingFile(string $directory, string $rotationId): string
    {
        return $directory . DIRECTORY_SEPARATOR . $this->hostId()
            . '.rotate-' . $rotationId . '.pending';
    }

    private function credentialDirectory(): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'wls' . DIRECTORY_SEPARATOR . 'gateway';
    }

    private function prepareCredentialDirectory(string $projectRoot): string
    {
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $directory = $projectRoot;
        foreach (['var', 'wls', 'gateway'] as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            $status = @\lstat($directory);
            $created = false;
            if (!\is_array($status)) {
                if (\file_exists($directory)
                    || \is_link($directory)
                    || !@\mkdir($directory, 0700)
                ) {
                    throw new \RuntimeException(
                        'Unable to create the project gateway credential directory.'
                    );
                }
                $created = true;
            }
            $this->assertCredentialDirectory($directory, $projectRoot);
            if ($created) {
                $this->preserveDirectoryOwner($directory, $owner);
            }
        }
        if (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($directory, 0700)) {
            throw new \RuntimeException('Unable to seal the project gateway credential directory.');
        }
        $this->assertCredentialDirectory($directory, $projectRoot, $owner, true);
        return $directory;
    }

    /** @return list<string> */
    private function credentialFiles(string $directory): array
    {
        $files = [];
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the project gateway credential directory.'
            );
        }
        $rawEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$rawEntries > self::MAX_CREDENTIAL_FILES + 3) {
                    throw new \RuntimeException(
                        'Project gateway credential directory exceeds its fixed raw entry limit.'
                    );
                }
                if ($leaf === '.' || $leaf === '..' || $leaf === '.credentials.lock') {
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{32}\.cred\z/D', $leaf) !== 1
                    && \preg_match(
                        '/\A[a-f0-9]{32}\.rotate-[a-f0-9]{32}\.pending\z/D',
                        $leaf,
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        'Project gateway credential directory contains an unsafe entry.'
                    );
                }
                $candidate = $directory . DIRECTORY_SEPARATOR . $leaf;
                GatewayProjectStateFilesystem::size(
                    $candidate,
                    16_384,
                    'host-bound gateway credential',
                );
                $files[] = $candidate;
                if (\count($files) > self::MAX_CREDENTIAL_FILES) {
                    throw new \RuntimeException(
                        'Project gateway credential directory exceeds its fixed entry limit.'
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }
        return $files;
    }

    /** @param array<string|int,mixed>|null $owner */
    private function assertCredentialDirectory(
        string $directory,
        string $projectRoot,
        ?array $owner = null,
        bool $requirePrivate = false,
    ): void {
        $status = @\lstat($directory);
        $real = \realpath($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($real)
            || !$this->pathInside($real, $projectRoot)
            || ($requirePrivate
                && \PHP_OS_FAMILY !== 'Windows'
                && (((int)($status['mode'] ?? 0)) & 0777) !== 0700)
            || ($owner !== null
                && \PHP_OS_FAMILY !== 'Windows'
                && ((int)($status['uid'] ?? -1) !== (int)($owner['uid'] ?? -2)
                    || (int)($status['gid'] ?? -1) !== (int)($owner['gid'] ?? -2)))
        ) {
            throw new \RuntimeException(
                'Project gateway credential directory permissions are unsafe.'
            );
        }
    }

    private function assertProjectDirectoryChain(string $directory, string $projectRoot): void
    {
        $canonicalRoot = \rtrim($projectRoot, '/\\');
        $canonicalDirectory = \realpath($directory);
        if (!\is_string($canonicalDirectory)
            || !$this->pathInside($canonicalDirectory, $canonicalRoot)
        ) {
            throw new \RuntimeException(
                'Project gateway credential directory escapes the project root.'
            );
        }
        $relative = \ltrim(\substr(
            \str_replace('\\', '/', $canonicalDirectory),
            \strlen(\str_replace('\\', '/', $canonicalRoot)),
        ), '/');
        $current = $canonicalRoot;
        foreach ($relative === '' ? [] : \explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Project gateway credential directory contains a linked component.'
                );
            }
        }
    }

    /** @param array<string|int,mixed> $owner */
    private function preserveDirectoryOwner(string $directory, array $owner): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        if (!@\chown($directory, (int)$owner['uid'])
            || !@\chgrp($directory, (int)$owner['gid'])
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on its gateway credential directory.'
            );
        }
    }

    /**
     * @param resource $handle
     * @param array<string|int,mixed> $owner
     */
    private function preserveProjectOwner($handle, string $path, array $owner): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $ownerApplied = \function_exists('fchown')
            && @\fchown($handle, (int)$owner['uid']);
        if (!$ownerApplied) {
            $ownerApplied = @\chown($path, (int)$owner['uid']);
        }
        $groupApplied = \function_exists('fchgrp')
            && @\fchgrp($handle, (int)$owner['gid']);
        if (!$groupApplied) {
            $groupApplied = @\chgrp($path, (int)$owner['gid']);
        }
        if (!$ownerApplied || !$groupApplied || \is_link($path)) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on its gateway credential.'
            );
        }
    }

    /** @param array<string|int,mixed> $owner */
    private function assertProjectOwnedRegularFile(
        string $file,
        array $owner,
        int $expectedSize,
    ): void {
        $status = @\lstat($file);
        if (!\is_array($status)
            || \is_link($file)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['size'] ?? -1) !== $expectedSize
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($status['mode'] ?? 0)) & 0777) !== 0600)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((int)($status['uid'] ?? -1) !== (int)$owner['uid']
                    || (int)($status['gid'] ?? -1) !== (int)$owner['gid']))
        ) {
            throw new \RuntimeException('Published project gateway credential is unsafe.');
        }
    }

    private function root(): string
    {
        $root = $this->projectRoot ?? (\defined('BP') ? (string)BP : '');
        if ($root === '' || \str_contains($root, "\0") || \is_link($root)) {
            throw new \RuntimeException('Unable to resolve a safe project root for gateway credentials.');
        }
        $real = \realpath($root);
        $status = \is_string($real) ? @\lstat($real) : false;
        if (!\is_string($real)
            || $this->isFilesystemRoot($real)
            || !\is_array($status)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || \is_link($real)
        ) {
            throw new \RuntimeException('Unable to resolve a safe project root for gateway credentials.');
        }
        return \rtrim($real, '/\\');
    }

    private function pathInside(string $path, string $root): bool
    {
        if ($this->isFilesystemRoot($root)) {
            return false;
        }
        $normalize = static function (string $value): string {
            $value = \rtrim(\str_replace('\\', '/', $value), '/');
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($value) : $value;
        };
        $path = $normalize($path);
        $root = $normalize($root);
        return $path !== ''
            && $root !== ''
            && ($path === $root || \str_starts_with($path, $root . '/'));
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $normalized) === 1) {
            return true;
        }
        $normalized = \rtrim($normalized, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $normalized) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $normalized) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $normalized) === 1;
    }

    private function isUuidV4(string $uuid): bool
    {
        return \preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            \strtolower(\trim($uuid)),
        ) === 1;
    }
}
