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
     *   secret:string
     * }
     */
    public function load(?string $expectedProjectUuid = null): array
    {
        $files = [];
        try {
            $hostId = $this->hostId();
            $files[] = $this->credentialFile($hostId);
        } catch (\Throwable) {
            $directory = $this->credentialDirectory();
            if (\is_dir($directory) && !\is_link($directory)) {
                $files = \glob($directory . DIRECTORY_SEPARATOR . '*.cred') ?: [];
            }
        }
        $valid = [];
        $candidateSeen = false;
        foreach ($files as $file) {
            if (!\is_file($file) || \is_link($file)) {
                continue;
            }
            $candidateSeen = true;
            $hostId = \strtolower(\basename($file, '.cred'));
            $decoded = \json_decode((string)@\file_get_contents($file), true);
            if (!\is_array($decoded)
                || (int)($decoded['schema_version'] ?? 0) !== 1
                || !\hash_equals(GatewayPaths::PROTOCOL, (string)($decoded['protocol'] ?? ''))
                || \preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1
                || !\hash_equals($hostId, (string)($decoded['host_id'] ?? ''))
                || \preg_match('/\A[a-f0-9-]{36}\z/D', (string)($decoded['project_uuid'] ?? '')) !== 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($decoded['credential_id'] ?? '')) !== 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($decoded['secret'] ?? '')) !== 1
                || ($expectedProjectUuid !== null
                    && !\hash_equals(
                        \strtolower(\trim($expectedProjectUuid)),
                        (string)$decoded['project_uuid'],
                    ))
            ) {
                continue;
            }
            $valid[] = $decoded;
        }
        if (\count($valid) !== 1) {
            if (!$candidateSeen) {
                throw new \RuntimeException(
                    'This project is not enrolled on the trusted WLS 2.0 host gateway.'
                );
            }
            throw new \RuntimeException('The host-bound WLS Gateway project credential is invalid.');
        }
        $decoded = $valid[0];
        return [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => (string)$decoded['project_uuid'],
            'credential_id' => (string)$decoded['credential_id'],
            'secret' => (string)$decoded['secret'],
        ];
    }

    /**
     * @param array<string,mixed> $credential
     */
    public function install(array $credential, string $expectedProjectUuid): string
    {
        $hostId = $this->hostId();
        $projectUuid = \strtolower(\trim((string)($credential['project_uuid'] ?? '')));
        $credentialId = \strtolower(\trim((string)($credential['credential_id'] ?? '')));
        $secret = \strtolower(\trim((string)($credential['secret'] ?? '')));
        if (!\hash_equals($hostId, (string)($credential['host_id'] ?? ''))
            || !\hash_equals(\strtolower(\trim($expectedProjectUuid)), $projectUuid)
            || \preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $credentialId) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1
        ) {
            throw new \RuntimeException('Gateway enrollment returned an invalid host-bound credential.');
        }
        $projectRoot = $this->root();
        $directory = $this->prepareCredentialDirectory($projectRoot);
        $projectOwner = @\lstat($projectRoot);
        $file = $this->credentialFile($hostId);
        if (\is_link($file)) {
            throw new \RuntimeException('Project gateway credential path cannot be a symbolic link.');
        }
        $payload = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
            'issued_at' => (string)($credential['issued_at'] ?? \gmdate(DATE_ATOM)),
        ];
        $encoded = \json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $temporary = $file . '.candidate.' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage the project gateway credential.');
        }
        try {
            if (@\fwrite($handle, $encoded) !== \strlen($encoded)
                || !@\fflush($handle)
            ) {
                throw new \RuntimeException('Unable to persist the project gateway credential.');
            }
            if (\function_exists('fsync') && !@\fsync($handle)) {
                throw new \RuntimeException('Unable to synchronize the project gateway credential.');
            }
            if (\PHP_OS_FAMILY !== 'Windows'
                && \is_array($projectOwner)
                && \function_exists('posix_geteuid')
                && \posix_geteuid() === 0
            ) {
                $ownerApplied = \function_exists('fchown')
                    && @\fchown($handle, (int)$projectOwner['uid']);
                if (!$ownerApplied && \function_exists('chown')) {
                    $ownerApplied = @\chown($temporary, (int)$projectOwner['uid']);
                }
                $groupApplied = \function_exists('fchgrp')
                    && @\fchgrp($handle, (int)$projectOwner['gid']);
                if (!$groupApplied && \function_exists('chgrp')) {
                    $groupApplied = @\chgrp($temporary, (int)$projectOwner['gid']);
                }
                if (!$ownerApplied || !$groupApplied) {
                    throw new \RuntimeException(
                        'Unable to preserve the project owner on its gateway credential.'
                    );
                }
            }
        } catch (\Throwable $throwable) {
            @\fclose($handle);
            @\unlink($temporary);
            throw $throwable;
        }
        @\fclose($handle);
        @\chmod($temporary, 0600);
        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to activate the project gateway credential.');
        }
        @\chmod($file, 0600);
        foreach (\glob($directory . DIRECTORY_SEPARATOR . '*.cred') ?: [] as $candidate) {
            if (!\hash_equals($file, $candidate)
                && \preg_match('/\A[a-f0-9]{32}\.cred\z/D', \basename($candidate)) === 1
                && \is_file($candidate)
                && !\is_link($candidate)
            ) {
                @\unlink($candidate);
            }
        }
        return $file;
    }

    public function remove(): bool
    {
        try {
            $credential = $this->load();
            $file = $this->credentialFile((string)$credential['host_id']);
        } catch (\Throwable) {
            return true;
        }
        if (!\file_exists($file) && !\is_link($file)) {
            return true;
        }
        if (\is_link($file)) {
            throw new \RuntimeException('Refusing to remove a linked gateway credential path.');
        }
        return @\unlink($file);
    }

    public function hostId(): string
    {
        $file = $this->paths->hostIdFile();
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException('Trusted WLS Gateway host identity is unavailable.');
        }
        $hostId = \strtolower(\trim((string)@\file_get_contents($file)));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1) {
            throw new \RuntimeException('Trusted WLS Gateway host identity is invalid.');
        }
        return $hostId;
    }

    private function credentialDirectory(): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'wls' . DIRECTORY_SEPARATOR . 'gateway';
    }

    private function credentialFile(string $hostId): string
    {
        return $this->credentialDirectory() . DIRECTORY_SEPARATOR . $hostId . '.cred';
    }

    private function prepareCredentialDirectory(string $projectRoot): string
    {
        $owner = @\lstat($projectRoot);
        $directory = $projectRoot;
        foreach (['var', 'wls', 'gateway'] as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            $created = false;
            if (!\is_dir($directory)) {
                if (!@\mkdir($directory, 0700) || !\is_dir($directory)) {
                    throw new \RuntimeException(
                        'Unable to create the project gateway credential directory.'
                    );
                }
                $created = true;
            }
            if (\is_link($directory)) {
                throw new \RuntimeException(
                    'Project gateway credential directory cannot be a symbolic link.'
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows'
                && \is_array($owner)
                && \function_exists('posix_geteuid')
                && \posix_geteuid() === 0
                && ($created || $segment === 'gateway')
                && (!@\chown($directory, (int)$owner['uid'])
                    || !@\chgrp($directory, (int)$owner['gid']))
            ) {
                throw new \RuntimeException(
                    'Unable to preserve the project owner on its gateway credential directory.'
                );
            }
        }
        @\chmod($directory, 0700);
        return $directory;
    }

    private function root(): string
    {
        $root = $this->projectRoot ?? (\defined('BP') ? (string)BP : '');
        $real = \realpath($root);
        if (!\is_string($real) || !\is_dir($real) || \is_link($root)) {
            throw new \RuntimeException('Unable to resolve a safe project root for gateway credentials.');
        }
        return $real;
    }
}
