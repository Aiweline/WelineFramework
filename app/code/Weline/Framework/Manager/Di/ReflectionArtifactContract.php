<?php

declare(strict_types=1);

namespace Weline\Framework\Manager\Di;

/**
 * Shared compile/runtime contract for ObjectManager reflection bridge artifacts.
 *
 * Authority: generated/compiled_factories.php + generated/reflection_metadata.php
 * must use these envelopes. Legacy flat returns are rejected at runtime.
 */
final class ReflectionArtifactContract
{
    public const COMPILED_FACTORIES_FORMAT = 'weline-compiled-factories/v2';

    public const REFLECTION_METADATA_FORMAT = 'weline-reflection-metadata/v2';

    public const REJECT_MISSING = 'missing';

    public const REJECT_LEGACY_FLAT = 'legacy_flat';

    public const REJECT_FORMAT_MISMATCH = 'format_mismatch';

    public const REJECT_PHP_VERSION_MISMATCH = 'php_version_mismatch';

    public const REJECT_INVALID_PAYLOAD = 'invalid_payload';

    /**
     * @return array{ok: true, factories: array<string, \Closure>, source_signatures: array<string, string>}|array{ok: false, reason: string}
     */
    public static function acceptCompiledFactoriesPayload(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return ['ok' => false, 'reason' => self::REJECT_INVALID_PAYLOAD];
        }

        if (!\array_key_exists('format', $payload)) {
            return ['ok' => false, 'reason' => self::REJECT_LEGACY_FLAT];
        }

        if (($payload['format'] ?? null) !== self::COMPILED_FACTORIES_FORMAT) {
            return ['ok' => false, 'reason' => self::REJECT_FORMAT_MISMATCH];
        }

        if ((int)($payload['php_version_id'] ?? 0) !== \PHP_VERSION_ID) {
            return ['ok' => false, 'reason' => self::REJECT_PHP_VERSION_MISMATCH];
        }

        $factories = $payload['factories'] ?? null;
        $signatures = $payload['source_signatures'] ?? null;
        if (!\is_array($factories) || !\is_array($signatures)) {
            return ['ok' => false, 'reason' => self::REJECT_INVALID_PAYLOAD];
        }

        return [
            'ok' => true,
            'factories' => $factories,
            'source_signatures' => $signatures,
        ];
    }

    /**
     * @return array{ok: true, metadata: array<string, array>, source_signatures: array<string, string>}|array{ok: false, reason: string}
     */
    public static function acceptReflectionMetadataPayload(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return ['ok' => false, 'reason' => self::REJECT_INVALID_PAYLOAD];
        }

        if (!\array_key_exists('format', $payload)) {
            return ['ok' => false, 'reason' => self::REJECT_LEGACY_FLAT];
        }

        if (($payload['format'] ?? null) !== self::REFLECTION_METADATA_FORMAT) {
            return ['ok' => false, 'reason' => self::REJECT_FORMAT_MISMATCH];
        }

        if ((int)($payload['php_version_id'] ?? 0) !== \PHP_VERSION_ID) {
            return ['ok' => false, 'reason' => self::REJECT_PHP_VERSION_MISMATCH];
        }

        $metadata = $payload['metadata'] ?? null;
        $signatures = $payload['source_signatures'] ?? null;
        if (!\is_array($metadata) || !\is_array($signatures)) {
            return ['ok' => false, 'reason' => self::REJECT_INVALID_PAYLOAD];
        }

        return [
            'ok' => true,
            'metadata' => $metadata,
            'source_signatures' => $signatures,
        ];
    }

    /**
     * Source signature used by both reflection:compile and ObjectManager runtime checks.
     *
     * Factory mode hashes the class file plus the constructor or zero-arg static getInstance
     * declaration file (same selection rules as the former ObjectManager::currentSourceSignature).
     */
    public static function computeSourceSignature(
        string $className,
        ?string $methodName,
        bool $factoryMode,
        ?\ReflectionClass $class = null,
    ): ?string {
        try {
            $class ??= new \ReflectionClass($className);
            $method = null;
            if ($factoryMode) {
                $constructor = $class->getConstructor();
                if ($constructor !== null && $constructor->isPrivate() && $class->hasMethod('getInstance')) {
                    $candidate = $class->getMethod('getInstance');
                    if ($candidate->isStatic() && $candidate->getNumberOfRequiredParameters() === 0) {
                        $method = $candidate;
                    }
                }
                $method ??= $constructor;
            } elseif ($methodName !== null && $class->hasMethod($methodName)) {
                $method = $class->getMethod($methodName);
            }

            $files = [];
            $classFile = $class->getFileName();
            if (\is_string($classFile) && $classFile !== '') {
                $files[$classFile] = true;
            }
            if ($method instanceof \ReflectionMethod) {
                $declarationFile = $method->getDeclaringClass()->getFileName();
                if (\is_string($declarationFile) && $declarationFile !== '') {
                    $files[$declarationFile] = true;
                }
            }
            if ($files === []) {
                return null;
            }

            $paths = \array_keys($files);
            \sort($paths, \SORT_STRING);
            $context = \hash_init('sha256');
            foreach ($paths as $path) {
                $digest = (string)@\hash_file('sha256', $path);
                if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    return null;
                }
                \hash_update($context, $digest . "\0");
            }

            return \hash_final($context);
        } catch (\Throwable) {
            return null;
        }
    }
}
