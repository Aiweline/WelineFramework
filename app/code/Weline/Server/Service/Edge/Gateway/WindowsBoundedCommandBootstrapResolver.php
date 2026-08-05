<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Resolves the project-level Windows process-tree helper without consulting
 * the host gateway. This is the pure-WLS and first-install bootstrap trust
 * path; release engineering ships the helper beside the locked project PHP
 * runtime with a detached Ed25519 signature.
 */
final class WindowsBoundedCommandBootstrapResolver
{
    public const SCHEMA = 'wls-bounded-command-bootstrap/1';
    public const HELPER_LEAF = 'wls-bounded-command.exe';
    public const MANIFEST_LEAF = 'wls-bounded-command.manifest.json';
    public const SIGNATURE_LEAF = 'wls-bounded-command.manifest.sig';

    private const MAX_HELPER_BYTES = 16_777_216;
    private const MAX_MANIFEST_BYTES = 65_536;
    private const MAX_SIGNATURE_BYTES = 16_384;
    private const MAX_KEYS_BYTES = 65_536;

    /** @return array{path:string,size:int,sha256:string,source:string}|null */
    public function resolve(): ?array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $directories = [];
        $phpBinary = @\realpath((string)\PHP_BINARY);
        if (\is_string($phpBinary) && $phpBinary !== '') {
            $directories[] = \dirname($phpBinary);
        }
        if (\defined('BP')) {
            $directories[] = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
                . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
        }
        $seen = [];
        $lastFailure = null;
        foreach ($directories as $directory) {
            $identity = \strtolower(\str_replace('\\', '/', \rtrim($directory, '/\\')));
            if ($identity === '' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $manifest = $directory . DIRECTORY_SEPARATOR . self::MANIFEST_LEAF;
            $signature = $directory . DIRECTORY_SEPARATOR . self::SIGNATURE_LEAF;
            $helper = $directory . DIRECTORY_SEPARATOR . self::HELPER_LEAF;
            $present = \file_exists($manifest)
                || \is_link($manifest)
                || \file_exists($signature)
                || \is_link($signature)
                || \file_exists($helper)
                || \is_link($helper);
            if (!$present) {
                continue;
            }
            try {
                return $this->verify($directory, $manifest, $signature, $helper);
            } catch (\Throwable $throwable) {
                $lastFailure = $throwable;
            }
        }
        if ($lastFailure instanceof \Throwable) {
            throw new \RuntimeException(
                'The WLS Windows bounded-command bootstrap bundle is present but invalid.',
                0,
                $lastFailure,
            );
        }

        return null;
    }

    /** @return array{path:string,size:int,sha256:string,source:string} */
    private function verify(
        string $directory,
        string $manifestPath,
        string $signaturePath,
        string $helperPath,
    ): array {
        $canonicalDirectory = @\realpath($directory);
        if (!\is_string($canonicalDirectory)
            || $canonicalDirectory === ''
            || \is_link($directory)
            || !\hash_equals(
                \strtolower(\str_replace('\\', '/', \rtrim($directory, '/\\'))),
                \strtolower(\str_replace('\\', '/', \rtrim($canonicalDirectory, '/\\'))),
            )
        ) {
            throw new \RuntimeException('Bootstrap helper directory is aliased or unsafe.');
        }
        $manifestBytes = self::readStableRegular(
            $manifestPath,
            self::MAX_MANIFEST_BYTES,
            'bounded-command bootstrap manifest',
        );
        $signatureBytes = self::readStableRegular(
            $signaturePath,
            self::MAX_SIGNATURE_BYTES,
            'bounded-command bootstrap signature',
        );
        $manifest = \json_decode($manifestBytes, true, 16, \JSON_THROW_ON_ERROR);
        $expectedKeys = [
            'arch',
            'component',
            'platform',
            'schema',
            'signing_key_id',
            'version',
        ];
        $actualKeys = \is_array($manifest) ? \array_keys($manifest) : [];
        \sort($actualKeys, \SORT_STRING);
        if (!\is_array($manifest)
            || $actualKeys !== $expectedKeys
            || !\hash_equals(self::SCHEMA, (string)($manifest['schema'] ?? ''))
            || !\hash_equals('Windows', (string)($manifest['platform'] ?? ''))
            || !\hash_equals(self::nativeArchitecture(), self::normalizeArchitecture(
                (string)($manifest['arch'] ?? ''),
            ))
            || \trim((string)($manifest['version'] ?? '')) === ''
            || !\is_array($manifest['component'] ?? null)
        ) {
            throw new \RuntimeException('Bootstrap helper manifest contract is invalid.');
        }
        $component = $manifest['component'];
        $componentKeys = \array_keys($component);
        \sort($componentKeys, \SORT_STRING);
        $expectedSize = (int)($component['size'] ?? -1);
        $expectedDigest = \strtolower(\trim((string)($component['sha256'] ?? '')));
        if ($componentKeys !== ['path', 'sha256', 'size']
            || !\hash_equals(self::HELPER_LEAF, (string)($component['path'] ?? ''))
            || $expectedSize < 1
            || $expectedSize > self::MAX_HELPER_BYTES
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
        ) {
            throw new \RuntimeException('Bootstrap helper component contract is invalid.');
        }
        $this->verifySignature(
            $manifestBytes,
            $signatureBytes,
            (string)($manifest['signing_key_id'] ?? ''),
        );
        $helperBytes = self::readStableRegular(
            $helperPath,
            self::MAX_HELPER_BYTES,
            'bounded-command bootstrap executable',
        );
        $canonicalHelper = @\realpath($helperPath);
        if (!\is_string($canonicalHelper)
            || !\hash_equals(
                \strtolower(\str_replace('\\', '/', $helperPath)),
                \strtolower(\str_replace('\\', '/', $canonicalHelper)),
            )
            || \strlen($helperBytes) !== $expectedSize
            || !\hash_equals($expectedDigest, \hash('sha256', $helperBytes))
        ) {
            throw new \RuntimeException('Bootstrap helper bytes do not match the signed manifest.');
        }

        return [
            'path' => $canonicalHelper,
            'size' => $expectedSize,
            'sha256' => $expectedDigest,
            'source' => 'project-runtime-bootstrap',
        ];
    }

    private function verifySignature(string $manifest, string $encoded, string $keyId): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')
            || \preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $keyId) !== 1
        ) {
            throw new \RuntimeException('Bootstrap helper signature runtime is unavailable.');
        }
        $keysPath = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'env'
            . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR
            . 'trusted-release-keys.json';
        $keysBytes = self::readStableRegular(
            $keysPath,
            self::MAX_KEYS_BYTES,
            'WLS trusted release keys',
        );
        $keys = \json_decode($keysBytes, true, 16, \JSON_THROW_ON_ERROR);
        $publicKey = null;
        if (\is_array($keys)
            && ($keys['schema_version'] ?? null) === 1
            && \is_array($keys['keys'] ?? null)
            && \count($keys['keys']) <= 64
        ) {
            foreach ($keys['keys'] as $candidate) {
                if (\is_array($candidate)
                    && ($candidate['enabled'] ?? false) === true
                    && \hash_equals($keyId, (string)($candidate['id'] ?? ''))
                    && \hash_equals('ed25519', (string)($candidate['algorithm'] ?? ''))
                ) {
                    $publicKey = \base64_decode(
                        (string)($candidate['public_key_base64'] ?? ''),
                        true,
                    );
                    break;
                }
            }
        }
        $signature = \base64_decode(\trim($encoded), true);
        if (!\is_string($publicKey)
            || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !\is_string($signature)
            || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached($signature, $manifest, $publicKey)
        ) {
            throw new \RuntimeException('Bootstrap helper signature is not trusted.');
        }
    }

    private static function nativeArchitecture(): string
    {
        $processorIdentifier = \strtolower(\trim((string)\getenv('PROCESSOR_IDENTIFIER')));
        if (\str_contains($processorIdentifier, 'arm')) {
            return 'arm64';
        }
        $value = (string)(\getenv('PROCESSOR_ARCHITEW6432')
            ?: \getenv('PROCESSOR_ARCHITECTURE')
            ?: \php_uname('m'));

        return self::normalizeArchitecture($value);
    }

    private static function normalizeArchitecture(string $architecture): string
    {
        return match (\strtolower(\trim($architecture))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower(\trim($architecture)),
        };
    }

    private static function readStableRegular(
        string $path,
        int $maximumBytes,
        string $label,
    ): string {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or outside bounds.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $bytes = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($opened)
                || !\is_string($bytes)
                || !\is_array($after)
                || !\is_array($pathAfter)
                || \strlen($bytes) !== (int)($opened['size'] ?? -1)
                || !self::sameFileState($before, $opened)
                || !self::sameFileState($opened, $after)
                || !self::sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $bytes;
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFileState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }

        return true;
    }
}
