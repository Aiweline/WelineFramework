<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker;

/**
 * Installs and verifies one immutable Nginx runtime directory.
 *
 * A slot is assembled in a sibling candidate directory, every component is
 * copied and re-hashed, then the complete directory is renamed into place.
 */
final class NginxRuntimeArtifact
{
    public const SCHEMA_VERSION = 2;
    public const MAX_COMPONENTS = 4096;
    public const MAX_DIRECTORIES = 8192;
    public const MAX_PATH_DEPTH = 64;
    public const MAX_TREE_ENTRIES = 12_289;
    // A verified 512 MiB gateway package is itself installed as components;
    // reserve a further bounded 16 MiB for the artifact's own manifest.
    public const MAX_TOTAL_BYTES = 553_648_128;
    public const MAX_MANIFEST_BYTES = 16_777_216;

    /**
     * @param array<string,array{
     *   source?:string,
     *   contents?:string,
     *   mode?:int,
     *   sha256?:string,
     *   size?:int
     * }> $components
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function install(
        string $slotDirectory,
        string $role,
        array $components,
        array $metadata = [],
    ): array {
        $role = \strtolower(\trim($role));
        if (\preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/D', $role) !== 1 || $components === []) {
            throw new \InvalidArgumentException('Nginx runtime artifact contract is invalid.');
        }
        $this->assertInstallComponentEnvelope($components);
        if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
            throw new \RuntimeException('Immutable Nginx runtime slot already exists.');
        }
        $parent = \dirname($slotDirectory);
        $parentStatus = @\lstat($parent);
        if (!\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($parentStatus['mode'] ?? 0)) & 0022) !== 0)
        ) {
            throw new \RuntimeException('Nginx runtime slot parent is unsafe.');
        }
        $candidate = $parent . DIRECTORY_SEPARATOR . \basename($slotDirectory)
            . '.candidate.' . \bin2hex(\random_bytes(8));
        if (!@\mkdir($candidate, 0700)) {
            throw new \RuntimeException('Unable to create the Nginx runtime candidate.');
        }

        $published = false;
        try {
            $componentManifest = [];
            $targetIdentities = [];
            $totalBytes = 0;
            foreach ($components as $relative => $definition) {
                $relative = $this->validateRelativePath((string)$relative);
                if (!\is_array($definition)) {
                    throw new \InvalidArgumentException('Nginx runtime component definition is invalid.');
                }
                $hasSource = \array_key_exists('source', $definition);
                $hasContents = \array_key_exists('contents', $definition);
                $mode = (int)($definition['mode'] ?? 0600);
                $hasExpectedDigest = \array_key_exists('sha256', $definition);
                $hasExpectedSize = \array_key_exists('size', $definition);
                $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
                $expectedSize = (int)($definition['size'] ?? -1);
                $targetIdentity = \PHP_OS_FAMILY === 'Windows'
                    ? \strtolower($relative)
                    : $relative;
                if ($hasSource === $hasContents
                    || ($hasSource && !\is_string($definition['source']))
                    || ($hasContents && !\is_string($definition['contents']))
                    || \hash_equals('manifest.json', $targetIdentity)
                    || isset($targetIdentities[$targetIdentity])
                    || $hasExpectedDigest !== $hasExpectedSize
                    || ($hasExpectedDigest
                        && (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                            || $expectedSize < 0))
                    || $mode < 0400
                    || $mode > 0777
                ) {
                    throw new \RuntimeException('Nginx runtime source component contract is unsafe.');
                }
                $targetIdentities[$targetIdentity] = true;
                $target = $candidate . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $targetDirectory = \dirname($target);
                $this->ensureCandidateDirectory($targetDirectory, $candidate);
                $remainingBytes = self::MAX_TOTAL_BYTES - $totalBytes;
                if ($remainingBytes < 0
                    || ($hasExpectedSize && $expectedSize > $remainingBytes)
                ) {
                    throw new \RuntimeException(
                        'Nginx runtime components exceed the fixed total-byte limit.'
                    );
                }
                $installed = $hasSource
                    ? $this->copyStableSource(
                        (string)$definition['source'],
                        $target,
                        $mode,
                        $hasExpectedDigest ? $expectedDigest : null,
                        $hasExpectedSize ? $expectedSize : null,
                        $remainingBytes,
                    )
                    : $this->writeInlineComponent(
                        (string)$definition['contents'],
                        $target,
                        $mode,
                        $hasExpectedDigest ? $expectedDigest : null,
                        $hasExpectedSize ? $expectedSize : null,
                        $remainingBytes,
                    );
                $totalBytes += $installed['size'];
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    throw new \RuntimeException(
                        'Nginx runtime components exceed the fixed total-byte limit.'
                    );
                }
                $componentManifest[$relative] = [
                    'sha256' => $installed['sha256'],
                    'size' => $installed['size'],
                    'mode' => $mode,
                ];
            }
            \ksort($componentManifest, SORT_STRING);
            unset($metadata['components'], $metadata['runtime_generation'], $metadata['schema_version']);
            $manifest = $metadata + [
                'schema_version' => self::SCHEMA_VERSION,
                'role' => $role,
                'components' => $componentManifest,
                'installed_at' => \gmdate(DATE_ATOM),
            ];
            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'role' => $role,
            ] + $manifest;
            $canonical = $this->canonicalize($manifest);
            $manifest['runtime_generation'] = \hash(
                'sha256',
                \json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
            $payload = \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            if (\strlen($payload) > self::MAX_MANIFEST_BYTES
                || \strlen($payload) > self::MAX_TOTAL_BYTES - $totalBytes
            ) {
                throw new \RuntimeException(
                    'Nginx runtime manifest or complete artifact exceeds its fixed byte limit.'
                );
            }
            $manifestFile = $candidate . DIRECTORY_SEPARATOR . 'manifest.json';
            $this->writeInlineComponent(
                $payload,
                $manifestFile,
                0600,
                null,
                null,
                self::MAX_TOTAL_BYTES - $totalBytes,
            );
            if (!$this->syncTreeDirectories($candidate)) {
                throw new \RuntimeException(
                    'Unable to durably assemble the Nginx runtime candidate.'
                );
            }
            $verified = $this->verify($candidate, $role);
            if (!($verified['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Nginx runtime candidate verification failed: '
                    . (string)($verified['reason'] ?? 'unknown')
                );
            }
            $candidateIdentity = @\lstat($candidate);
            if (!\is_array($candidateIdentity)
                || \is_link($candidate)
                || ((((int)($candidateIdentity['mode'] ?? 0)) & 0170000) !== 0040000)
                || !@\rename($candidate, $slotDirectory)
            ) {
                throw new \RuntimeException('Unable to activate the immutable Nginx runtime slot.');
            }
            $published = true;
            $slotIdentity = @\lstat($slotDirectory);
            if (!\is_array($slotIdentity)
                || \is_link($slotDirectory)
                || !$this->sameObjectIdentity($candidateIdentity, $slotIdentity)
            ) {
                throw new \RuntimeException(
                    'Activated Nginx runtime slot identity changed during publication.'
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows'
                && (!@\chmod($slotDirectory, 0700)
                    || !$this->syncDirectory($slotDirectory)
                    || !$this->syncDirectory($parent))
            ) {
                throw new \RuntimeException(
                    'Unable to durably activate the immutable Nginx runtime slot.'
                );
            }
            return $manifest;
        } catch (\Throwable $throwable) {
            $cleanup = $published ? $slotDirectory : $candidate;
            $this->removeTree($cleanup);
            if (\file_exists($cleanup) || \is_link($cleanup)) {
                throw new \RuntimeException(
                    'Unable to remove a failed immutable Nginx runtime publication.',
                    0,
                    $throwable,
                );
            }
            if ($published && !$this->syncDirectory($parent)) {
                throw new \RuntimeException(
                    'Unable to durably remove a failed immutable Nginx runtime publication.',
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /** @param array<string,array<string,mixed>> $components */
    private function assertInstallComponentEnvelope(array $components): void
    {
        if (\count($components) > self::MAX_COMPONENTS) {
            throw new \InvalidArgumentException(
                'Nginx runtime artifact exceeds its fixed component limit.'
            );
        }
        $identities = [];
        $directories = [];
        $totalBytes = 0;
        foreach ($components as $relative => $definition) {
            if (!\is_string($relative) || !\is_array($definition)) {
                throw new \InvalidArgumentException(
                    'Nginx runtime component envelope is invalid.'
                );
            }
            $canonical = $this->validateRelativePath($relative);
            $identity = \PHP_OS_FAMILY === 'Windows'
                ? \strtolower($canonical)
                : $canonical;
            $hasSource = \array_key_exists('source', $definition);
            $hasContents = \array_key_exists('contents', $definition);
            $hasExpectedDigest = \array_key_exists('sha256', $definition);
            $hasExpectedSize = \array_key_exists('size', $definition);
            $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
            $expectedSize = (int)($definition['size'] ?? -1);
            $mode = (int)($definition['mode'] ?? 0600);
            if ($hasSource === $hasContents
                || ($hasSource && !\is_string($definition['source']))
                || ($hasContents && !\is_string($definition['contents']))
                || \hash_equals('manifest.json', $identity)
                || \str_starts_with($identity, 'manifest.json/')
                || isset($identities[$identity])
                || isset($directories[$identity])
                || $hasExpectedDigest !== $hasExpectedSize
                || ($hasExpectedDigest
                    && (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                        || $expectedSize < 0))
                || $mode < 0400
                || $mode > 0777
            ) {
                throw new \RuntimeException(
                    'Nginx runtime source component contract is unsafe.'
                );
            }
            $identities[$identity] = true;
            $segments = \explode('/', $identity);
            \array_pop($segments);
            while ($segments !== []) {
                $directory = \implode('/', $segments);
                if (isset($identities[$directory])) {
                    throw new \RuntimeException(
                        'Nginx runtime component path collides with a component directory.'
                    );
                }
                $directories[$directory] = true;
                if (\count($directories) > self::MAX_DIRECTORIES) {
                    throw new \InvalidArgumentException(
                        'Nginx runtime artifact exceeds its fixed directory limit.'
                    );
                }
                \array_pop($segments);
            }
            if ($hasContents) {
                $size = \strlen((string)$definition['contents']);
            } else {
                $source = (string)$definition['source'];
                $status = @\lstat($source);
                if (!\is_array($status)
                    || !$this->isRegularFileStatus($status)
                    || (int)($status['nlink'] ?? 0) !== 1
                    || (int)($status['size'] ?? -1) < 0
                    || \is_link($source)
                ) {
                    throw new \RuntimeException(
                        'Nginx runtime source component is missing, linked, or special.'
                    );
                }
                $size = (int)$status['size'];
            }
            if (($hasExpectedSize && $expectedSize !== $size)
                || $size > self::MAX_TOTAL_BYTES
                || $totalBytes > self::MAX_TOTAL_BYTES - $size
            ) {
                throw new \RuntimeException(
                    'Nginx runtime components exceed their signed or fixed byte limit.'
                );
            }
            $totalBytes += $size;
        }
    }

    private function ensureCandidateDirectory(string $directory, string $candidate): void
    {
        $candidate = \rtrim($candidate, '/\\');
        if (!$this->pathInside($directory, $candidate)) {
            throw new \RuntimeException(
                'Nginx runtime component directory escapes its candidate.'
            );
        }
        $relative = \ltrim(\substr($directory, \strlen($candidate)), '/\\');
        $current = $candidate;
        foreach (\preg_split('#[\\\\/]#', $relative, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \RuntimeException('Nginx runtime directory segment is unsafe.');
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (!\is_dir($current) && (!@\mkdir($current, 0700) || !\is_dir($current))) {
                throw new \RuntimeException(
                    'Unable to create an Nginx runtime component directory.'
                );
            }
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException('Nginx runtime component directory is unsafe.');
            }
        }
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    /** @return array{ok:bool,reason:string,role:string,runtime_generation:string,components:int} */
    public function verify(string $slotDirectory, string $expectedRole): array
    {
        $failure = static fn (string $reason): array => [
            'ok' => false,
            'reason' => $reason,
            'role' => '',
            'runtime_generation' => '',
            'components' => 0,
        ];
        $slotStatus = @\lstat($slotDirectory);
        if (!\is_array($slotStatus)
            || \is_link($slotDirectory)
            || ((((int)($slotStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            return $failure('Nginx runtime slot is missing or unsafe.');
        }
        $manifestFile = $slotDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        try {
            $manifestArtifact = $this->consumeStableRegularFile(
                $manifestFile,
                self::MAX_MANIFEST_BYTES,
                true,
            );
        } catch (\Throwable) {
            return $failure('Nginx runtime manifest is missing or unsafe.');
        }
        $manifest = \json_decode($manifestArtifact['bytes'], true);
        $schemaVersion = (int)($manifest['schema_version'] ?? 0);
        if (!\is_array($manifest)
            || !\in_array($schemaVersion, [1, self::SCHEMA_VERSION], true)
            || !\hash_equals(\strtolower(\trim($expectedRole)), (string)($manifest['role'] ?? ''))
            || !\is_array($manifest['components'] ?? null)
            || $manifest['components'] === []
            || \count($manifest['components']) > self::MAX_COMPONENTS
        ) {
            return $failure('Nginx runtime manifest contract is invalid.');
        }
        $generation = \strtolower(\trim((string)($manifest['runtime_generation'] ?? '')));
        $generationSource = $manifest;
        unset($generationSource['runtime_generation']);
        $expectedGeneration = \hash(
            'sha256',
            \json_encode(
                $this->canonicalize($generationSource),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $generation) !== 1
            || !\hash_equals($expectedGeneration, $generation)
        ) {
            return $failure('Nginx runtime generation digest is invalid.');
        }
        $declaredFiles = ['manifest.json' => true];
        $declaredDirectories = ['' => true];
        $totalBytes = $manifestArtifact['size'];
        foreach ($manifest['components'] as $relative => $expected) {
            if (!\is_string($relative)) {
                return $failure('Nginx runtime component path is invalid.');
            }
            try {
                $relative = $this->validateRelativePath($relative);
            } catch (\Throwable) {
                return $failure('Nginx runtime component path is invalid.');
            }
            if (!\is_array($expected)) {
                return $failure('Nginx runtime component manifest is invalid.');
            }
            $expectedDigest = \strtolower(\trim((string)($expected['sha256'] ?? '')));
            $expectedSize = (int)($expected['size'] ?? -1);
            $expectedMode = (int)($expected['mode'] ?? -1);
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                || $expectedSize < 0
                || $expectedMode < 0400
                || $expectedMode > 0777
            ) {
                return $failure('Nginx runtime component manifest is invalid.');
            }
            if ($expectedSize > self::MAX_TOTAL_BYTES
                || $totalBytes > self::MAX_TOTAL_BYTES - $expectedSize
            ) {
                return $failure('Nginx runtime artifact exceeds its fixed total-byte limit.');
            }
            $totalBytes += $expectedSize;
            $file = $slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            try {
                $component = $this->consumeStableRegularFile(
                    $file,
                    $expectedSize,
                    false,
                );
            } catch (\Throwable) {
                return $failure('Nginx runtime component digest or size mismatch: ' . $relative);
            }
            if (!\hash_equals($expectedDigest, $component['sha256'])
                || $expectedSize !== $component['size']
                || (\PHP_OS_FAMILY !== 'Windows'
                    && !$this->componentModeMatches(
                        $schemaVersion,
                        $expectedMode,
                        $component['mode'] & 0777,
                    ))
            ) {
                return $failure('Nginx runtime component digest, size, or mode mismatch: ' . $relative);
            }
            $identity = \PHP_OS_FAMILY === 'Windows'
                ? \strtolower($relative)
                : $relative;
            if (isset($declaredFiles[$identity])
                || isset($declaredDirectories[$identity])
            ) {
                return $failure('Nginx runtime component path is duplicated: ' . $relative);
            }
            $declaredFiles[$identity] = true;
            $segments = \explode('/', $identity);
            \array_pop($segments);
            while ($segments !== []) {
                $directory = \implode('/', $segments);
                if (isset($declaredFiles[$directory])) {
                    return $failure(
                        'Nginx runtime component path collides with a component directory.'
                    );
                }
                $declaredDirectories[$directory] = true;
                if (\count($declaredDirectories) - 1 > self::MAX_DIRECTORIES) {
                    return $failure(
                        'Nginx runtime manifest exceeds its fixed directory limit.'
                    );
                }
                \array_pop($segments);
            }
        }
        if (!$this->treeMatchesManifest(
            $slotDirectory,
            $declaredFiles,
            $declaredDirectories,
        )) {
            return $failure('Nginx runtime slot contains an undeclared or unsafe entry.');
        }
        return [
            'ok' => true,
            'reason' => 'Immutable Nginx runtime artifact verified.',
            'role' => (string)$manifest['role'],
            'runtime_generation' => $generation,
            'components' => \count($manifest['components']),
        ];
    }

    private function validateRelativePath(string $relative): string
    {
        $canonical = \str_replace('\\', '/', \trim($relative));
        if (!\hash_equals($relative, $canonical)
            || \strlen($canonical) > 1024
        ) {
            throw new \InvalidArgumentException(
                'Nginx runtime component path must already be canonical.'
            );
        }
        $relative = $canonical;
        $segments = \explode('/', $relative);
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:/', $relative) === 1
            || \preg_match('/[\x00-\x1f\x7f:*?"<>|]/', $relative) === 1
            || \count($segments) > self::MAX_PATH_DEPTH
            || \array_filter(
                $segments,
                static function (string $segment): bool {
                    $device = \strtoupper((string)\strtok($segment, '.'));
                    return $segment === ''
                        || \strlen($segment) > 255
                        || $segment === '.'
                        || $segment === '..'
                        || \str_ends_with($segment, '.')
                        || \str_ends_with($segment, ' ')
                        || \in_array(
                            $device,
                            [
                                'CON', 'PRN', 'AUX', 'NUL',
                                'COM1', 'COM2', 'COM3', 'COM4', 'COM5',
                                'COM6', 'COM7', 'COM8', 'COM9',
                                'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5',
                                'LPT6', 'LPT7', 'LPT8', 'LPT9',
                            ],
                            true,
                        );
                },
            ) !== []
        ) {
            throw new \InvalidArgumentException('Nginx runtime component path must be relative and contained.');
        }
        return $relative;
    }

    private function componentModeMatches(
        int $schemaVersion,
        int $expectedMode,
        int $actualMode,
    ): bool {
        if ($actualMode === $expectedMode) {
            return true;
        }
        // Schema 1 recorded package transport modes before the production
        // platform sealer converted host slots to root/group read-only modes.
        // Keep those already-installed A/B slots readable for rollback, while
        // schema 2 records and enforces the final mode exactly.
        return $schemaVersion === 1
            && $actualMode === (($expectedMode & 0111) !== 0 ? 0550 : 0440);
    }

    /**
     * Copy from one already-opened regular file so a pathname replacement
     * after signed-package verification cannot change the bytes installed.
     *
     * @return array{sha256:string,size:int}
     */
    private function copyStableSource(
        string $source,
        string $target,
        int $mode,
        ?string $expectedDigest,
        ?int $expectedSize,
        int $maximumBytes,
    ): array {
        $pathStatus = @\lstat($source);
        if ($maximumBytes < 0
            || !\is_array($pathStatus)
            || !$this->isRegularFileStatus($pathStatus)
            || (int)($pathStatus['nlink'] ?? 0) !== 1
            || (int)($pathStatus['size'] ?? -1) < 0
            || (int)($pathStatus['size'] ?? -1) > $maximumBytes
            || \is_link($source)
        ) {
            throw new \RuntimeException(
                'Nginx runtime source component is missing, linked, or special.'
            );
        }
        $sourceHandle = @\fopen($source, 'rb');
        if (!\is_resource($sourceHandle)) {
            throw new \RuntimeException('Unable to open an Nginx runtime source component.');
        }
        $targetHandle = null;
        try {
            $openedStatus = @\fstat($sourceHandle);
            if (!\is_array($openedStatus)
                || !$this->sameFileState($pathStatus, $openedStatus)
                || !$this->isRegularFileStatus($openedStatus)
                || (int)($openedStatus['nlink'] ?? 0) !== 1
                || ($expectedSize !== null
                    && (int)($openedStatus['size'] ?? -1) !== $expectedSize)
            ) {
                throw new \RuntimeException(
                    'Nginx runtime source component changed before copying.'
                );
            }
            $targetHandle = @\fopen($target, 'xb');
            if (!\is_resource($targetHandle)) {
                throw new \RuntimeException('Unable to create an Nginx runtime component.');
            }
            $hash = \hash_init('sha256');
            $size = 0;
            while (!\feof($sourceHandle)) {
                $chunk = @\fread($sourceHandle, 1_048_576);
                if (!\is_string($chunk) || ($chunk === '' && !\feof($sourceHandle))) {
                    throw new \RuntimeException('Unable to read an Nginx runtime source component.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > $maximumBytes
                    || ($expectedSize !== null && $size > $expectedSize)
                ) {
                    throw new \RuntimeException('Nginx runtime source component exceeds its signed size.');
                }
                \hash_update($hash, $chunk);
                $this->writeAll($targetHandle, $chunk);
            }
            $afterStatus = @\fstat($sourceHandle);
            if (!\is_array($afterStatus)
                || !$this->sameFileState($openedStatus, $afterStatus)
                || (int)($afterStatus['size'] ?? -1) !== $size
            ) {
                throw new \RuntimeException('Nginx runtime source component changed while copying.');
            }
            $digest = \hash_final($hash);
            if (($expectedDigest !== null && !\hash_equals($expectedDigest, $digest))
                || ($expectedSize !== null && $expectedSize !== $size)
            ) {
                throw new \RuntimeException(
                    'Nginx runtime component does not match its signed digest or size.'
                );
            }
            $this->sealTargetHandle($targetHandle, $target, $mode, $size);
            return ['sha256' => $digest, 'size' => $size];
        } catch (\Throwable $throwable) {
            if (\is_resource($targetHandle)) {
                @\fclose($targetHandle);
                $targetHandle = null;
            }
            @\unlink($target);
            throw $throwable;
        } finally {
            \is_resource($targetHandle) && @\fclose($targetHandle);
            @\fclose($sourceHandle);
        }
    }

    /** @return array{sha256:string,size:int} */
    private function writeInlineComponent(
        string $contents,
        string $target,
        int $mode,
        ?string $expectedDigest,
        ?int $expectedSize,
        int $maximumBytes,
    ): array {
        $digest = \hash('sha256', $contents);
        $size = \strlen($contents);
        if ($maximumBytes < 0
            || $size > $maximumBytes
            || ($expectedDigest !== null && !\hash_equals($expectedDigest, $digest))
            || ($expectedSize !== null && $expectedSize !== $size)
        ) {
            throw new \RuntimeException(
                'Inline Nginx runtime component does not match its verified bytes.'
            );
        }
        $handle = @\fopen($target, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create an inline Nginx runtime component.');
        }
        try {
            $this->writeAll($handle, $contents);
            $this->sealTargetHandle($handle, $target, $mode, $size);
        } catch (\Throwable $throwable) {
            @\fclose($handle);
            @\unlink($target);
            throw $throwable;
        }
        @\fclose($handle);
        return ['sha256' => $digest, 'size' => $size];
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $length = \strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Unable to write an Nginx runtime component.');
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    private function sealTargetHandle($handle, string $target, int $mode, int $size): void
    {
        $modeApplied = \function_exists('fchmod')
            ? @\fchmod($handle, $mode)
            : @\chmod($target, $mode);
        if (\PHP_OS_FAMILY !== 'Windows' && !$modeApplied) {
            throw new \RuntimeException('Unable to seal an Nginx runtime component mode.');
        }
        if (!@\fflush($handle)
            || (\function_exists('fsync') && !@\fsync($handle))
        ) {
            throw new \RuntimeException('Unable to durably write an Nginx runtime component.');
        }
        $status = @\fstat($handle);
        if (!\is_array($status)
            || !$this->isRegularFileStatus($status)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['size'] ?? -1) !== $size
        ) {
            throw new \RuntimeException('Nginx runtime target component is unsafe.');
        }
    }

    /** @param array<string|int,mixed> $status */
    private function isRegularFileStatus(array $status): bool
    {
        return (((int)($status['mode'] ?? 0)) & 0170000) === 0100000;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameObjectIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{bytes:string,sha256:string,size:int,mode:int}
     */
    private function consumeStableRegularFile(
        string $path,
        int $maximumBytes,
        bool $captureBytes,
    ): array {
        $pathStatus = @\lstat($path);
        if ($maximumBytes < 0
            || !\is_array($pathStatus)
            || !$this->isRegularFileStatus($pathStatus)
            || (int)($pathStatus['nlink'] ?? 0) !== 1
            || (int)($pathStatus['size'] ?? -1) < 0
            || (int)($pathStatus['size'] ?? -1) > $maximumBytes
            || \is_link($path)
        ) {
            throw new \RuntimeException('Nginx runtime file is missing, linked, or special.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open an Nginx runtime file.');
        }
        try {
            $openedStatus = @\fstat($handle);
            if (!\is_array($openedStatus)
                || !$this->sameFileState($pathStatus, $openedStatus)
                || !$this->isRegularFileStatus($openedStatus)
                || (int)($openedStatus['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException('Nginx runtime file changed before verification.');
            }
            $hash = \hash_init('sha256');
            $bytes = '';
            $size = 0;
            while (!\feof($handle)) {
                $chunk = @\fread($handle, 1_048_576);
                if (!\is_string($chunk) || ($chunk === '' && !\feof($handle))) {
                    throw new \RuntimeException('Unable to read an Nginx runtime file.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > $maximumBytes) {
                    throw new \RuntimeException('Nginx runtime file exceeds its declared size.');
                }
                \hash_update($hash, $chunk);
                if ($captureBytes) {
                    $bytes .= $chunk;
                }
            }
            $afterStatus = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($afterStatus)
                || !\is_array($pathAfter)
                || !$this->sameFileState($openedStatus, $afterStatus)
                || !$this->sameFileState($afterStatus, $pathAfter)
                || (int)($afterStatus['size'] ?? -1) !== $size
            ) {
                throw new \RuntimeException('Nginx runtime file changed during verification.');
            }
            return [
                'bytes' => $bytes,
                'sha256' => \hash_final($hash),
                'size' => $size,
                'mode' => (int)($afterStatus['mode'] ?? 0),
            ];
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string,bool> $declaredFiles
     * @param array<string,bool> $declaredDirectories
     */
    private function treeMatchesManifest(
        string $root,
        array $declaredFiles,
        array $declaredDirectories,
    ): bool {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            $iterator->setMaxDepth(self::MAX_PATH_DEPTH);
            $visited = 0;
            foreach ($iterator as $item) {
                if (++$visited > self::MAX_TREE_ENTRIES) {
                    return false;
                }
                if ($iterator->getDepth() >= self::MAX_PATH_DEPTH) {
                    return false;
                }
                $path = $item->getPathname();
                $status = @\lstat($path);
                if (!\is_array($status) || $item->isLink()) {
                    return false;
                }
                $relative = \str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    \substr($path, \strlen($root) + 1),
                );
                $identity = \PHP_OS_FAMILY === 'Windows'
                    ? \strtolower($relative)
                    : $relative;
                if ($item->isDir()) {
                    if (!isset($declaredDirectories[$identity])) {
                        return false;
                    }
                    continue;
                }
                if (!$item->isFile()
                    || !$this->isRegularFileStatus($status)
                    || (int)($status['nlink'] ?? 0) !== 1
                    || !isset($declaredFiles[$identity])
                ) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function syncDirectory(string $directory): bool
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return true;
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            return @\fsync($handle);
        } finally {
            @\fclose($handle);
        }
    }

    private function syncTreeDirectories(string $root): bool
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return true;
        }
        try {
            $directories = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            $iterator->setMaxDepth(self::MAX_PATH_DEPTH);
            $visited = 0;
            foreach ($iterator as $item) {
                if (++$visited > self::MAX_TREE_ENTRIES) {
                    return false;
                }
                if ($iterator->getDepth() >= self::MAX_PATH_DEPTH) {
                    return false;
                }
                if ($item->isLink()) {
                    return false;
                }
                if ($item->isDir()) {
                    $directories[] = $item->getPathname();
                    if (\count($directories) > self::MAX_DIRECTORIES) {
                        return false;
                    }
                }
            }
            $directories[] = $root;
            foreach ($directories as $directory) {
                if (!$this->syncDirectory($directory)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function removeTree(string $directory): void
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Failed Nginx runtime publication root is linked or special.'
            );
        }
        // Preflight the complete tree before the first mutation. Returning
        // after a traversal limit is crossed would leave a half-deleted slot
        // whose immutable identity can no longer be reasoned about.
        $records = GatewayBoundedTreeWalker::collect(
            $directory,
            true,
            true,
            self::MAX_TREE_ENTRIES,
            self::MAX_PATH_DEPTH,
        );
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = ($record['directory'] ?? false) === true
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove failed Nginx runtime artifact: '
                        . (string)$record['path']
                );
            }
        }
    }
}
