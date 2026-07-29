<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

/**
 * Installs and verifies one immutable Nginx runtime directory.
 *
 * A slot is assembled in a sibling candidate directory, every component is
 * copied and re-hashed, then the complete directory is renamed into place.
 */
final class NginxRuntimeArtifact
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string,array{source:string,mode?:int}> $components
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
        if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
            throw new \RuntimeException('Immutable Nginx runtime slot already exists.');
        }
        $parent = \dirname($slotDirectory);
        if (!\is_dir($parent) && !@\mkdir($parent, 0700, true) && !\is_dir($parent)) {
            throw new \RuntimeException('Unable to create the Nginx runtime slot parent.');
        }
        $candidate = $parent . DIRECTORY_SEPARATOR . \basename($slotDirectory)
            . '.candidate.' . \bin2hex(\random_bytes(8));
        if (!@\mkdir($candidate, 0700)) {
            throw new \RuntimeException('Unable to create the Nginx runtime candidate.');
        }

        try {
            $componentManifest = [];
            foreach ($components as $relative => $definition) {
                $relative = $this->validateRelativePath((string)$relative);
                if (!\is_array($definition)) {
                    throw new \InvalidArgumentException('Nginx runtime component definition is invalid.');
                }
                $source = (string)($definition['source'] ?? '');
                $mode = (int)($definition['mode'] ?? 0600);
                if (!\is_file($source)
                    || \is_link($source)
                    || $mode < 0400
                    || $mode > 0777
                ) {
                    throw new \RuntimeException('Nginx runtime source component is missing or unsafe.');
                }
                $target = $candidate . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $targetDirectory = \dirname($target);
                if (!\is_dir($targetDirectory)
                    && !@\mkdir($targetDirectory, 0700, true)
                    && !\is_dir($targetDirectory)
                ) {
                    throw new \RuntimeException('Unable to create an Nginx runtime component directory.');
                }
                $sourceDigest = @\hash_file('sha256', $source);
                if (!\is_string($sourceDigest) || !@\copy($source, $target)) {
                    throw new \RuntimeException('Unable to copy an Nginx runtime component.');
                }
                @\chmod($target, $mode);
                $targetDigest = @\hash_file('sha256', $target);
                if (!\is_string($targetDigest) || !\hash_equals($sourceDigest, $targetDigest)) {
                    throw new \RuntimeException('Nginx runtime component digest changed while copying.');
                }
                $componentManifest[$relative] = [
                    'sha256' => $targetDigest,
                    'size' => (int)\filesize($target),
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
            $manifestFile = $candidate . DIRECTORY_SEPARATOR . 'manifest.json';
            if (@\file_put_contents($manifestFile, $payload, LOCK_EX) !== \strlen($payload)) {
                throw new \RuntimeException('Unable to write the Nginx runtime manifest.');
            }
            @\chmod($manifestFile, 0600);
            $verified = $this->verify($candidate, $role);
            if (!($verified['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Nginx runtime candidate verification failed: '
                    . (string)($verified['reason'] ?? 'unknown')
                );
            }
            if (!@\rename($candidate, $slotDirectory)) {
                throw new \RuntimeException('Unable to activate the immutable Nginx runtime slot.');
            }
            @\chmod($slotDirectory, 0700);
            return $manifest;
        } catch (\Throwable $throwable) {
            $this->removeTree($candidate);
            throw $throwable;
        }
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
        if (!\is_dir($slotDirectory) || \is_link($slotDirectory)) {
            return $failure('Nginx runtime slot is missing or unsafe.');
        }
        $manifestFile = $slotDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!\is_file($manifestFile) || \is_link($manifestFile)) {
            return $failure('Nginx runtime manifest is missing or unsafe.');
        }
        $manifest = \json_decode((string)@\file_get_contents($manifestFile), true);
        if (!\is_array($manifest)
            || (int)($manifest['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals(\strtolower(\trim($expectedRole)), (string)($manifest['role'] ?? ''))
            || !\is_array($manifest['components'] ?? null)
            || $manifest['components'] === []
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
        foreach ($manifest['components'] as $relative => $expected) {
            try {
                $relative = $this->validateRelativePath((string)$relative);
            } catch (\Throwable) {
                return $failure('Nginx runtime component path is invalid.');
            }
            if (!\is_array($expected)) {
                return $failure('Nginx runtime component manifest is invalid.');
            }
            $file = $slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $digest = @\hash_file('sha256', $file);
            if (!\is_file($file)
                || \is_link($file)
                || !\is_string($digest)
                || !\hash_equals((string)($expected['sha256'] ?? ''), $digest)
                || (int)($expected['size'] ?? -1) !== (int)\filesize($file)
            ) {
                return $failure('Nginx runtime component digest or size mismatch: ' . $relative);
            }
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
        $relative = \str_replace('\\', '/', \trim($relative));
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:/', $relative) === 1
            || \in_array('..', \explode('/', $relative), true)
            || \str_contains($relative, "\0")
        ) {
            throw new \InvalidArgumentException('Nginx runtime component path must be relative and contained.');
        }
        return $relative;
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
        if (!\is_dir($directory) || \is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($directory);
    }
}
