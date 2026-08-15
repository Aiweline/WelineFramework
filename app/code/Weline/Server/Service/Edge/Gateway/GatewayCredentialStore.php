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
    private const MAX_RECOVERY_ARTIFACTS = 256;
    // One explicitly recoverable semantic overflow, every bounded recovery
    // artifact, plus '.', '..' and the credential lock itself.
    private const MAX_RAW_DIRECTORY_ENTRIES = self::MAX_CREDENTIAL_FILES + 1
        + self::MAX_RECOVERY_ARTIFACTS + 3;

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
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );

        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use ($directory, $owner, $expectedProjectUuid): array {
                $files = $this->credentialFiles($directory);
                return $this->activeCredential(
                    $directory,
                    $files,
                    $owner,
                    $expectedProjectUuid,
                )['payload'];
            },
            $seal,
        );
    }

    /** @param array<string,mixed> $credential */
    public function install(
        array $credential,
        string $expectedProjectUuid,
        ?float $deadlineMonotonic = null,
    ): string {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
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
            function () use (
                $directory,
                $file,
                $encoded,
                $projectOwner,
                $seal,
                $deadlineMonotonic,
            ): string {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $credentials = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $overflowAfterImage = $this->assertRecoverableOverflowAfterImage(
                    $credentials,
                    $file,
                    $encoded,
                    'active credential install',
                );
                if ($overflowAfterImage) {
                    $this->assertProjectOwnedRegularFile(
                        $file,
                        $projectOwner,
                        \strlen($encoded),
                    );
                    $this->cleanupPublishedCredentialFiles($credentials, $file);
                    return $file;
                }
                $credentials = $this->makeRoomForActivePath(
                    $directory,
                    $credentials,
                    $file,
                    'active credential install',
                    $deadlineMonotonic,
                );
                if ($this->credentialFileMatches($file, $encoded)) {
                    $this->assertProjectOwnedRegularFile(
                        $file,
                        $projectOwner,
                        \strlen($encoded),
                    );
                    $this->cleanupPublishedCredentialFiles($credentials, $file);
                    return $file;
                }
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600, $seal);
                $this->assertProjectOwnedRegularFile($file, $projectOwner, \strlen($encoded));
                // The active payload is now durable. Cleanup is maintenance,
                // not part of the commit acknowledgement: a race or platform
                // unlink failure must not report this exact credential as
                // uncommitted to the caller.
                $this->cleanupPublishedCredentialFiles($credentials, $file);
                return $file;
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    /** @param array<string,mixed> $credential */
    public function installPending(
        array $credential,
        string $expectedProjectUuid,
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): string {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
        $rotationId = $this->rotationId($rotationId);
        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use (
                $credential,
                $expectedProjectUuid,
                $rotationId,
                $directory,
                $owner,
                $seal,
                $deadlineMonotonic,
            ): string {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $credentials = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $active = $this->activeCredential(
                    $directory,
                    $credentials,
                    $owner,
                );
                $payload = $this->credentialPayload(
                    $credential,
                    $expectedProjectUuid,
                    (string)$active['host_id'],
                );
                $file = $this->pendingFile(
                    $directory,
                    $rotationId,
                    (string)$active['host_id'],
                );
                $encoded = \json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL;
                $overflowAfterImage = $this->assertRecoverableOverflowAfterImage(
                    $credentials,
                    $file,
                    $encoded,
                    'pending credential install',
                );
                if ($overflowAfterImage || $this->credentialFileMatches($file, $encoded)) {
                    $this->assertProjectOwnedRegularFile(
                        $file,
                        $owner,
                        \strlen($encoded),
                    );
                    return $file;
                }
                $this->assertCapacityForNewPath(
                    $credentials,
                    $file,
                    'pending credential install',
                );
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600, $seal);
                $this->assertProjectOwnedRegularFile($file, $owner, \strlen($encoded));
                return $file;
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
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
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use (
                $directory,
                $owner,
                $rotationId,
                $expectedProjectUuid,
            ): array {
                $files = $this->credentialFiles($directory);
                $active = $this->activeCredential($directory, $files, $owner);
                $hostId = (string)$active['host_id'];
                return $this->readCredentialPayload(
                    $this->pendingFile(
                        $directory,
                        $this->rotationId($rotationId),
                        $hostId,
                    ),
                    $expectedProjectUuid,
                    $hostId,
                );
            },
            $seal,
        );
    }

    /** @return array<string,mixed> */
    public function commitPending(
        string $rotationId,
        string $expectedProjectUuid,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
        $rotationId = $this->rotationId($rotationId);
        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException('Unable to inspect the project credential owner.');
        }
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use (
                $directory,
                $rotationId,
                $expectedProjectUuid,
                $owner,
                $seal,
                $deadlineMonotonic,
            ): array {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $files = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $selected = $this->activeCredential($directory, $files, $owner);
                $active = (string)$selected['file'];
                $hostId = (string)$selected['host_id'];
                $pending = $this->pendingFile($directory, $rotationId, $hostId);
                $payload = $this->readCredentialPayload(
                    $pending,
                    $expectedProjectUuid,
                    $hostId,
                );
                $encoded = \json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL;
                $this->assertProjectOwnedRegularFile(
                    $pending,
                    $owner,
                    \strlen($encoded),
                );
                $overflowAfterImage = $this->assertRecoverableOverflowAfterImage(
                    $files,
                    $pending,
                    $encoded,
                    'pending credential commit',
                    $active,
                );
                if (!$overflowAfterImage) {
                    $files = $this->makeRoomForActivePath(
                        $directory,
                        $files,
                        $active,
                        'pending credential commit',
                        $deadlineMonotonic,
                    );
                }
                if (!$this->credentialFileMatches($active, $encoded)) {
                    $this->credentialDeadlineRemaining($deadlineMonotonic);
                    GatewayProjectStateFilesystem::atomicWrite(
                        $active,
                        $encoded,
                        0600,
                        $seal,
                    );
                }
                $this->assertProjectOwnedRegularFile($active, $owner, \strlen($encoded));
                $this->cleanupPublishedCredentialFiles($files, $active, $pending);
                return $payload;
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    public function abortPending(
        string $rotationId,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
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
            function () use (
                $directory,
                $owner,
                $rotationId,
                $deadlineMonotonic,
            ): bool {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $files = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $active = $this->activeCredential($directory, $files, $owner);
                return GatewayProjectStateFilesystem::removeRegular(
                    $this->pendingFile(
                        $directory,
                        $this->rotationId($rotationId),
                        (string)$active['host_id'],
                    ),
                    'aborted pending gateway credential',
                );
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    public function remove(?float $deadlineMonotonic = null): bool
    {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
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
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use ($directory, $owner, $deadlineMonotonic): bool {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $files = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $active = $this->activeCredential($directory, $files, $owner);
                return GatewayProjectStateFilesystem::removeRegular(
                    (string)$active['file'],
                    'host-bound gateway credential',
                );
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    /**
     * Remove every host-bound capability copied into this project directory
     * before assigning a live same-host clone its fresh UUID. This is broader
     * than remove(): pending rotations and credentials from another host are
     * equally foreign capabilities, but the operation is still strictly
     * confined to the current project's validated var/wls/gateway directory.
     */
    public function purgeForFreshCloneIdentity(
        ?string $preserveProjectUuid = null,
        ?float $deadlineMonotonic = null,
    ): int
    {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
        if ($preserveProjectUuid !== null) {
            $preserveProjectUuid = \strtolower(\trim($preserveProjectUuid));
            if (!$this->isUuidV4($preserveProjectUuid)) {
                throw new \InvalidArgumentException(
                    'Fresh-clone credential preservation identity is invalid.',
                );
            }
        }
        $directory = $this->credentialDirectory();
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Project gateway credential directory is unsafe.',
                );
            }
            return 0;
        }
        $projectRoot = $this->root();
        $owner = @\lstat($projectRoot);
        if (!\is_array($owner)) {
            throw new \RuntimeException(
                'Unable to inspect the project credential owner.',
            );
        }
        $this->assertCredentialDirectory($directory, $projectRoot, $owner, true);
        $this->assertProjectDirectoryChain($directory, $projectRoot);
        $seal = fn ($handle, string $path): mixed => $this->preserveProjectOwner(
            $handle,
            $path,
            $owner,
        );
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . '.credentials.lock',
            function () use (
                $directory,
                $preserveProjectUuid,
                $deadlineMonotonic,
            ): int {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                $files = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                $preserved = $preserveProjectUuid === null
                    ? ''
                    : $directory . DIRECTORY_SEPARATOR . $this->hostId() . '.cred';
                $removed = 0;
                foreach ($files as $file) {
                    $this->credentialDeadlineRemaining($deadlineMonotonic);
                    if ($preserved !== '' && \hash_equals($preserved, $file)) {
                        $raw = GatewayProjectStateFilesystem::read(
                            $file,
                            16_384,
                            'Fresh-enrollment host-bound WLS Gateway credential',
                        );
                        $decoded = \json_decode($raw, true);
                        $storedProjectUuid = \is_array($decoded)
                            ? (string)($decoded['project_uuid'] ?? '')
                            : '';
                        if (\hash_equals($preserveProjectUuid, $storedProjectUuid)) {
                            // A crash may occur after the fresh host credential
                            // is durable but before the identity marker is
                            // finalized. Preserve only that exact, fully valid
                            // current-host after-image; every copied old or
                            // pending capability is still retired below.
                            $this->credentialPayload($decoded, $preserveProjectUuid);
                            continue;
                        }
                    }
                    $selectedIdentity = @\lstat($file);
                    if (!\is_array($selectedIdentity)) {
                        throw new \RuntimeException(
                            'Unable to fence a copied fresh-clone gateway credential.',
                        );
                    }
                    if (!GatewayProjectStateFilesystem::removeRegular(
                        $file,
                        'copied fresh-clone gateway credential',
                        $selectedIdentity,
                    )) {
                        throw new \RuntimeException(
                            'Unable to retire a copied fresh-clone gateway credential.',
                        );
                    }
                    $removed++;
                }
                $remaining = $this->credentialFiles(
                    $directory,
                    $deadlineMonotonic,
                );
                if (($preserved === '' && $remaining !== [])
                    || ($preserved !== ''
                        && ($remaining !== [] && $remaining !== [$preserved]))
                ) {
                    throw new \RuntimeException(
                        'Copied fresh-clone gateway credentials remain after retirement.',
                    );
                }
                if ($remaining === [$preserved]) {
                    $this->readCredentialPayload(
                        $preserved,
                        (string)$preserveProjectUuid,
                    );
                }
                return $removed;
            },
            $seal,
            waitTimeoutSeconds: $this->credentialLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    private function credentialDeadlineRemaining(
        ?float $deadlineMonotonic,
    ): float {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'WLS gateway credential deadline is invalid.',
            );
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'WLS gateway credential deadline was exhausted.',
            );
        }

        return $remaining;
    }

    private function credentialLockWaitTimeout(
        ?float $deadlineMonotonic,
    ): float {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }

        return \min(
            0.25,
            $this->credentialDeadlineRemaining($deadlineMonotonic),
        );
    }

    /**
     * Select the only committed active credential from project-owned state.
     * The host trust tree is intentionally not project-readable; the leaf name
     * and stored payload provide the same host binding for project requests.
     *
     * @param list<string> $files
     * @param array<string|int,mixed> $owner
     * @return array{file:string,host_id:string,payload:array<string,mixed>}
     */
    private function activeCredential(
        string $directory,
        array $files,
        array $owner,
        ?string $expectedProjectUuid = null,
    ): array {
        $active = [];
        foreach ($files as $file) {
            $matches = [];
            if (\preg_match(
                '/\A([a-f0-9]{32})\.cred\z/D',
                \basename($file),
                $matches,
            ) === 1) {
                $active[] = [
                    'file' => $file,
                    'host_id' => (string)$matches[1],
                ];
            }
        }
        if (\count($active) !== 1) {
            throw new \RuntimeException(
                'Project gateway active credential is missing or ambiguous.'
            );
        }
        $file = (string)$active[0]['file'];
        $hostId = (string)$active[0]['host_id'];
        $encoded = GatewayProjectStateFilesystem::read(
            $file,
            16_384,
            'Host-bound WLS Gateway project credential',
        );
        $this->assertProjectOwnedRegularFile($file, $owner, \strlen($encoded));
        $decoded = \json_decode($encoded, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(
                'The host-bound WLS Gateway project credential is invalid.'
            );
        }
        return [
            'file' => $file,
            'host_id' => $hostId,
            'payload' => $this->storedCredentialPayload(
                $decoded,
                $hostId,
                $expectedProjectUuid,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function storedCredentialPayload(
        array $decoded,
        string $hostId,
        ?string $expectedProjectUuid = null,
    ): array {
        $hostId = \strtolower(\trim($hostId));
        $projectUuid = (string)($decoded['project_uuid'] ?? '');
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1
            || ($decoded['schema_version'] ?? null) !== 1
            || !\is_string($decoded['protocol'] ?? null)
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)$decoded['protocol'])
            || !\is_string($decoded['host_id'] ?? null)
            || !\hash_equals($hostId, (string)$decoded['host_id'])
            || !\is_string($decoded['project_uuid'] ?? null)
            || !\hash_equals($projectUuid, \strtolower(\trim($projectUuid)))
            || !$this->isUuidV4($projectUuid)
            || !\is_string($decoded['credential_id'] ?? null)
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$decoded['credential_id']) !== 1
            || (\array_key_exists('credential_generation', $decoded)
                && (!\is_int($decoded['credential_generation'])
                    || (int)$decoded['credential_generation'] < 1))
            || !\is_string($decoded['secret'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$decoded['secret']) !== 1
            || !\is_string($decoded['issued_at'] ?? null)
            || \strlen((string)$decoded['issued_at']) > 128
            || \strtotime((string)$decoded['issued_at']) === false
            || ($expectedProjectUuid !== null
                && !\hash_equals(
                    \strtolower(\trim($expectedProjectUuid)),
                    $projectUuid,
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
            'project_uuid' => $projectUuid,
            'credential_id' => (string)$decoded['credential_id'],
            'credential_generation' => (int)($decoded['credential_generation'] ?? 1),
            'secret' => (string)$decoded['secret'],
            'issued_at' => (string)$decoded['issued_at'],
        ];
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
        ?string $expectedHostId = null,
    ): array {
        $hostId = $expectedHostId === null
            ? $this->hostId()
            : \strtolower(\trim($expectedHostId));
        $expectedProjectUuid = \strtolower(\trim($expectedProjectUuid));
        $projectUuid = \strtolower(\trim((string)($credential['project_uuid'] ?? '')));
        $credentialId = \strtolower(\trim((string)($credential['credential_id'] ?? '')));
        $credentialGeneration = $credential['credential_generation'] ?? 1;
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        $issuedAt = \trim((string)($credential['issued_at'] ?? \gmdate(DATE_ATOM)));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1
            || !\hash_equals($hostId, \strtolower(\trim((string)(
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
        ?string $expectedHostId = null,
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
        return $this->storedCredentialPayload(
            $decoded,
            $expectedHostId ?? $this->hostId(),
            $expectedProjectUuid,
        );
    }

    private function rotationId(string $rotationId): string
    {
        $rotationId = \strtolower(\trim($rotationId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $rotationId) !== 1) {
            throw new \InvalidArgumentException('Gateway credential rotation identity is invalid.');
        }
        return $rotationId;
    }

    private function pendingFile(
        string $directory,
        string $rotationId,
        ?string $expectedHostId = null,
    ): string {
        $hostId = $expectedHostId ?? $this->hostId();
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1) {
            throw new \RuntimeException(
                'Project gateway credential host binding is invalid.'
            );
        }
        return $directory . DIRECTORY_SEPARATOR . $hostId
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
    private function credentialFiles(
        string $directory,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
        $files = [];
        $staging = [];
        $backups = [];
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the project gateway credential directory.'
            );
        }
        $rawEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                $this->credentialDeadlineRemaining($deadlineMonotonic);
                // Count every raw leaf, including dot/lock/recovery entries,
                // before interpreting it. Recovery must remain bounded even
                // when an attacker fills the project-owned directory.
                if (++$rawEntries > self::MAX_RAW_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Project gateway credential directory exceeds its fixed raw entry limit.'
                    );
                }
                if ($leaf === '.' || $leaf === '..' || $leaf === '.credentials.lock') {
                    continue;
                }
                $activePattern = '[a-f0-9]{32}\.cred';
                $pendingPattern = '[a-f0-9]{32}\.rotate-[a-f0-9]{32}\.pending';
                $semantic = \preg_match('/\A(?:' . $activePattern . '|'
                    . $pendingPattern . ')\z/D', $leaf) === 1;
                $stagingMatch = [];
                $backupMatch = [];
                $isStaging = \preg_match(
                    '/\A(' . $activePattern . '|' . $pendingPattern
                        . ')\.tmp-[a-f0-9]{24}\z/D',
                    $leaf,
                    $stagingMatch,
                ) === 1;
                $isBackup = \preg_match(
                    '/\A(' . $activePattern . '|' . $pendingPattern
                        . ')\.wls-backup-[a-f0-9]{16}\z/D',
                    $leaf,
                    $backupMatch,
                ) === 1;
                if (!$semantic && !$isStaging && !$isBackup) {
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
                if ($isStaging || $isBackup) {
                    $identity = @\lstat($candidate);
                    if (!\is_array($identity)) {
                        throw new \RuntimeException(
                            'Project gateway credential recovery artifact is indeterminate.',
                        );
                    }
                    $entry = [
                        'path' => $candidate,
                        'identity' => $identity,
                        'target_leaf' => (string)(
                            $isStaging ? $stagingMatch[1] : $backupMatch[1]
                        ),
                    ];
                    if ($isStaging) {
                        $staging[] = $entry;
                    } else {
                        $backups[] = $entry;
                    }
                    if (\count($staging) + \count($backups)
                        > self::MAX_RECOVERY_ARTIFACTS
                    ) {
                        throw new \RuntimeException(
                            'Project gateway credential recovery artifact quota is exhausted.',
                        );
                    }
                    continue;
                }
                $files[] = $candidate;
                if (\count($files) > self::MAX_CREDENTIAL_FILES + 1) {
                    throw new \RuntimeException(
                        'Project gateway credential directory exceeds its bounded recovery entry limit.'
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }

        // A Windows backup is the prior committed target. Delete it only when
        // the paired target still exists, parses as an exact credential, and
        // is authorized by durable project identity/rotation facts. Validate
        // every backup first so a damaged target retains all recovery evidence.
        foreach ($backups as $index => $backup) {
            $this->credentialDeadlineRemaining($deadlineMonotonic);
            $target = $directory . DIRECTORY_SEPARATOR . $backup['target_leaf'];
            if (!\in_array($target, $files, true)) {
                throw new \RuntimeException(
                    'Credential recovery backup paired credential target is missing.',
                );
            }
            try {
                $backups[$index]['target_identity']
                    = $this->validateCredentialRecoveryTarget(
                    $target,
                    (string)$backup['target_leaf'],
                    $deadlineMonotonic,
                );
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Credential recovery backup paired credential target is invalid.',
                    0,
                    $throwable,
                );
            }
        }
        foreach ([...$staging, ...$backups] as $artifact) {
            $this->credentialDeadlineRemaining($deadlineMonotonic);
            if (\is_array($artifact['target_identity'] ?? null)) {
                $target = $directory . DIRECTORY_SEPARATOR
                    . (string)$artifact['target_leaf'];
                $currentTargetIdentity = @\lstat($target);
                if (!\is_array($currentTargetIdentity)
                    || !$this->sameRecoveryFileState(
                        $artifact['target_identity'],
                        $currentTargetIdentity,
                    )
                ) {
                    throw new \RuntimeException(
                        'Credential recovery backup paired target changed before cleanup.',
                    );
                }
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                (string)$artifact['path'],
                'orphan WLS Gateway credential recovery artifact',
                \is_array($artifact['identity'] ?? null)
                    ? $artifact['identity']
                    : null,
            )) {
                throw new \RuntimeException(
                    'Unable to collect an orphan WLS Gateway credential recovery artifact.',
                );
            }
        }
        return $files;
    }

    /** @return array<string|int,mixed> */
    private function validateCredentialRecoveryTarget(
        string $target,
        string $targetLeaf,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->credentialDeadlineRemaining($deadlineMonotonic);
        $active = [];
        $pending = [];
        $isActive = \preg_match(
            '/\A([a-f0-9]{32})\.cred\z/D',
            $targetLeaf,
            $active,
        ) === 1;
        $isPending = \preg_match(
            '/\A([a-f0-9]{32})\.rotate-([a-f0-9]{32})\.pending\z/D',
            $targetLeaf,
            $pending,
        ) === 1;
        if (!$isActive && !$isPending) {
            throw new \RuntimeException(
                'Credential recovery target leaf is not semantic.',
            );
        }
        $identityBeforeRead = @\lstat($target);
        if (!\is_array($identityBeforeRead)) {
            throw new \RuntimeException(
                'Credential recovery paired target identity is unavailable.',
            );
        }
        $raw = GatewayProjectStateFilesystem::read(
            $target,
            16_384,
            'Credential recovery paired target',
        );
        $projectOwner = @\lstat($this->root());
        if (!\is_array($projectOwner)) {
            throw new \RuntimeException(
                'Credential recovery project owner is unavailable.',
            );
        }
        $this->assertProjectOwnedRegularFile(
            $target,
            $projectOwner,
            \strlen($raw),
        );
        $decoded = \json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $expectedFields = [
            'schema_version',
            'protocol',
            'host_id',
            'project_uuid',
            'credential_id',
            'credential_generation',
            'secret',
            'issued_at',
        ];
        $actualFields = \is_array($decoded) ? \array_keys($decoded) : [];
        \sort($expectedFields, SORT_STRING);
        \sort($actualFields, SORT_STRING);
        $hostId = (string)($isActive ? $active[1] : $pending[1]);
        $projectUuid = \is_array($decoded)
            ? (string)($decoded['project_uuid'] ?? '')
            : '';
        if (!\is_array($decoded)
            || $actualFields !== $expectedFields
            || ($decoded['schema_version'] ?? null) !== 1
            || !\hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($decoded['protocol'] ?? ''),
            )
            || !\hash_equals($hostId, (string)($decoded['host_id'] ?? ''))
            || !$this->isUuidV4($projectUuid)
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $decoded['credential_id'] ?? ''
            )) !== 1
            || !\is_int($decoded['credential_generation'] ?? null)
            || (int)$decoded['credential_generation'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $decoded['secret'] ?? ''
            )) !== 1
            || !\is_string($decoded['issued_at'] ?? null)
            || \strlen((string)$decoded['issued_at']) > 128
            || \strtotime((string)$decoded['issued_at']) === false
        ) {
            throw new \RuntimeException(
                'Credential recovery paired target payload is malformed.',
            );
        }
        $rotationId = $isPending ? (string)$pending[2] : null;
        if (!(new ProjectIdentityStore($this->root()))->authorizesCredentialRecovery(
            $projectUuid,
            $rotationId,
            $deadlineMonotonic,
        )) {
            throw new \RuntimeException(
                'Credential recovery paired target is not authorized by project facts.',
            );
        }
        $identityAfterRead = @\lstat($target);
        if (!\is_array($identityAfterRead)
            || !$this->sameRecoveryFileState(
                $identityBeforeRead,
                $identityAfterRead,
            )
        ) {
            throw new \RuntimeException(
                'Credential recovery paired target changed during validation.',
            );
        }
        return $identityAfterRead;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameRecoveryFileState(array $before, array $after): bool
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

    private function credentialFileMatches(string $file, string $encoded): bool
    {
        $current = GatewayProjectStateFilesystem::readOptional(
            $file,
            16_384,
            'Host-bound WLS Gateway credential after-image',
        );
        return \is_string($current) && \hash_equals($encoded, $current);
    }

    /**
     * Exactly one overflow file is recoverable only when the operation's
     * target already contains the byte-exact payload that this caller is
     * authorized to persist. Commit recovery additionally requires an active
     * path that can be replaced without allocating another semantic entry.
     *
     * @param list<string> $files
     */
    private function assertRecoverableOverflowAfterImage(
        array $files,
        string $target,
        string $encoded,
        string $operation,
        ?string $requiredCompanion = null,
    ): bool {
        $count = \count($files);
        if ($count <= self::MAX_CREDENTIAL_FILES) {
            return false;
        }
        $targetPresent = \in_array($target, $files, true);
        $companionPresent = $requiredCompanion === null
            || \in_array($requiredCompanion, $files, true);
        if ($count !== self::MAX_CREDENTIAL_FILES + 1
            || !$targetPresent
            || !$companionPresent
            || !$this->credentialFileMatches($target, $encoded)
        ) {
            throw new \RuntimeException(
                'Project gateway credential capacity overflow does not match the '
                    . 'exact recoverable after-image for ' . $operation . '.'
            );
        }
        return true;
    }

    /** @param list<string> $files */
    private function assertCapacityForNewPath(
        array $files,
        string $target,
        string $operation,
    ): void {
        if (!\in_array($target, $files, true)
            && \count($files) >= self::MAX_CREDENTIAL_FILES
        ) {
            throw new \RuntimeException(
                'Project gateway credential capacity is exhausted before '
                    . $operation . '; no new credential file was published.'
            );
        }
    }

    /**
     * A stale active file is host-inapplicable and is already part of the
     * install/commit cleanup contract. It may be retired before publication
     * to reserve a slot while the pending credential remains durable. Pending
     * rotations are never selected as capacity victims.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function makeRoomForActivePath(
        string $directory,
        array $files,
        string $active,
        string $operation,
        ?float $deadlineMonotonic = null,
    ): array {
        if (\in_array($active, $files, true)
            || \count($files) < self::MAX_CREDENTIAL_FILES
        ) {
            return $files;
        }
        foreach ($files as $candidate) {
            $this->credentialDeadlineRemaining($deadlineMonotonic);
            if (!\str_ends_with($candidate, '.cred')
                || \hash_equals($active, $candidate)
            ) {
                continue;
            }
            try {
                GatewayProjectStateFilesystem::removeRegular(
                    $candidate,
                    'stale host-bound gateway credential capacity reservation',
                );
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Project gateway credential capacity could not retire a stale '
                        . 'active path before ' . $operation . '.',
                    0,
                    $throwable,
                );
            }
            $files = $this->credentialFiles(
                $directory,
                $deadlineMonotonic,
            );
            break;
        }
        $this->assertCapacityForNewPath($files, $active, $operation);
        return $files;
    }

    /**
     * Once the active file has passed its identity/owner/size verification,
     * stale-file removal is best-effort. Throwing here would tell the caller
     * to retry an operation whose secret is already authoritative on disk.
     * A retry safely re-enters the exact after-image path and attempts cleanup
     * again.
     *
     * @param list<string> $files
     */
    private function cleanupPublishedCredentialFiles(
        array $files,
        string $active,
        ?string $committedPending = null,
    ): void {
        $cleanup = [];
        if ($committedPending !== null && !\hash_equals($active, $committedPending)) {
            $cleanup[$committedPending] = 'committed pending gateway credential';
        }
        foreach ($files as $candidate) {
            if (\str_ends_with($candidate, '.cred')
                && !\hash_equals($active, $candidate)
            ) {
                $cleanup[$candidate] = 'stale host-bound gateway credential';
            }
        }
        foreach ($cleanup as $candidate => $label) {
            try {
                GatewayProjectStateFilesystem::removeRegular($candidate, $label);
            } catch (\Throwable) {
                // The verified active credential is already committed. Leave
                // the exact stale path for a bounded retry instead of emitting
                // a false pre-commit failure (and never include its contents).
            }
        }
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
