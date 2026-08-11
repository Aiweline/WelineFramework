<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * One-shot, same-Master authorization envelope for an initial auto-mode
 * registration failure. It carries no port authority: only the current
 * Gateway Agent may turn an accepted request into the existing authenticated
 * fallback-enable command, whose allocator chooses and binds the host lease.
 */
final class GatewayStartupFallbackRequest
{
    public const SCHEMA_VERSION = 2;
    private const MAX_AGE_SECONDS = 120;

    /** @var list<string> */
    private const DIGEST_FIELDS = [
        'schema_version',
        'request_id',
        'instance_name',
        'project_uuid',
        'instance_generation',
        'master_pid',
        'master_epoch',
        'master_launch_id',
        'requested_mode',
        'effective_mode',
        'certificate_domain',
        'certificate_generation',
        'certificate_source_digest',
        'certificate_trust_profile',
        'certificate_provider',
        'certificate_material_class',
        'certificate_provenance_digest',
        'certificate_path',
        'private_key_path',
        'certificate_leaf_fingerprint_sha256',
        'requested_port',
        'failure_digest',
        'issued_at',
    ];

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $activeCertificate
     * @return array<string,int|string>
     */
    public static function issue(
        string $instanceName,
        array $endpoint,
        array $activeCertificate,
        string $failure,
        ?int $issuedAt = null,
    ): array {
        $expected = self::expectedIdentity(
            $instanceName,
            $endpoint,
            $activeCertificate,
        );
        $request = $expected + [
            'schema_version' => self::SCHEMA_VERSION,
            'request_id' => \bin2hex(\random_bytes(16)),
            'requested_port' => 0,
            'failure_digest' => \hash('sha256', \substr($failure, 0, 2048)),
            'issued_at' => $issuedAt ?? \time(),
        ];
        $request = self::ordered($request);
        $request['request_digest'] = self::digest($request);
        return self::normalize($request, (int)$request['issued_at']);
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $activeCertificate
     * @return array<string,int|string>
     */
    public static function assertMatches(
        array $request,
        string $instanceName,
        array $endpoint,
        array $activeCertificate,
        ?int $now = null,
    ): array {
        $normalized = self::normalize($request, $now ?? \time());
        $expected = self::expectedIdentity(
            $instanceName,
            $endpoint,
            $activeCertificate,
        );
        foreach ($expected as $field => $value) {
            $actual = $normalized[$field] ?? null;
            $matches = \is_int($value)
                ? $actual === $value
                : \is_string($actual) && \hash_equals($value, $actual);
            if (!$matches) {
                throw new \RuntimeException(
                    'Gateway startup fallback request does not match the current '
                    . $field . ' fence.',
                );
            }
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,int|string>
     */
    private static function normalize(array $request, int $now): array
    {
        $raw = self::ordered($request);
        if (!\is_string($request['request_digest'] ?? null)) {
            throw new \RuntimeException(
                'Gateway startup fallback request digest has an invalid type.',
            );
        }
        $normalized = [
            'schema_version' => (int)$raw['schema_version'],
            'request_id' => \strtolower(\trim((string)$raw['request_id'])),
            'instance_name' => \trim((string)$raw['instance_name']),
            'project_uuid' => \strtolower(\trim((string)$raw['project_uuid'])),
            'instance_generation' => (int)$raw['instance_generation'],
            'master_pid' => (int)$raw['master_pid'],
            'master_epoch' => (int)$raw['master_epoch'],
            'master_launch_id' => \strtolower(\trim((string)$raw['master_launch_id'])),
            'requested_mode' => \strtolower(\trim((string)$raw['requested_mode'])),
            'effective_mode' => \strtolower(\trim((string)$raw['effective_mode'])),
            'certificate_domain' => self::normalizeDomain((string)(
                $raw['certificate_domain']
            )),
            'certificate_generation' => (int)$raw['certificate_generation'],
            'certificate_source_digest' => \strtolower(\trim((string)(
                $raw['certificate_source_digest']
            ))),
            'certificate_trust_profile' => ProjectCertificateGenerationStore::normalizeTrustProfile(
                (string)$raw['certificate_trust_profile'],
            ),
            'certificate_provider' => ProjectCertificateGenerationStore::normalizeProvider(
                (string)$raw['certificate_provider'],
            ),
            'certificate_material_class' => \strtolower(\trim((string)(
                $raw['certificate_material_class']
            ))),
            'certificate_provenance_digest' => \strtolower(\trim((string)(
                $raw['certificate_provenance_digest']
            ))),
            'certificate_path' => \trim((string)$raw['certificate_path']),
            'private_key_path' => \trim((string)$raw['private_key_path']),
            'certificate_leaf_fingerprint_sha256' => \strtolower(\trim((string)(
                $raw['certificate_leaf_fingerprint_sha256']
            ))),
            'requested_port' => (int)$raw['requested_port'],
            'failure_digest' => \strtolower(\trim((string)$raw['failure_digest'])),
            'issued_at' => (int)$raw['issued_at'],
        ];
        $requestDigest = \strtolower(\trim((string)($request['request_digest'] ?? '')));
        if ($normalized['schema_version'] !== self::SCHEMA_VERSION
            || \preg_match('/\A[a-f0-9]{32}\z/D', $normalized['request_id']) !== 1
            || !self::validInstanceName($normalized['instance_name'])
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-'
                    . '[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $normalized['project_uuid'],
            ) !== 1
            || $normalized['instance_generation'] < 1
            || $normalized['master_pid'] < 2
            || $normalized['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $normalized['master_launch_id']) !== 1
            || !\hash_equals(GatewayStartupDecision::MODE_AUTO, $normalized['requested_mode'])
            || !\hash_equals(GatewayStartupDecision::MODE_GATEWAY, $normalized['effective_mode'])
            || $normalized['certificate_domain'] === ''
            || $normalized['certificate_generation'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $normalized['certificate_source_digest']) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $normalized['certificate_provenance_digest']) !== 1
            || !\hash_equals(
                ProjectCertificateGenerationStore::provenanceDigest(
                    $normalized['certificate_domain'],
                    $normalized['certificate_source_digest'],
                    $normalized['certificate_trust_profile'],
                    $normalized['certificate_provider'],
                    $normalized['certificate_material_class'],
                ),
                $normalized['certificate_provenance_digest'],
            )
            || ($normalized['certificate_trust_profile']
                    === ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION
                && $normalized['certificate_material_class']
                    !== ProjectCertificateGenerationStore::MATERIAL_CLASS_PUBLIC_TRUST)
            || !self::validAbsolutePath($normalized['certificate_path'])
            || !self::validAbsolutePath($normalized['private_key_path'])
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $normalized['certificate_leaf_fingerprint_sha256'],
            ) !== 1
            || $normalized['requested_port'] !== 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $normalized['failure_digest']) !== 1
            || $normalized['issued_at'] < 1
            || \abs($now - $normalized['issued_at']) > self::MAX_AGE_SECONDS
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || !\hash_equals(self::digest($normalized), $requestDigest)
        ) {
            throw new \RuntimeException(
                'Gateway startup fallback request envelope is invalid or expired.',
            );
        }
        $normalized['request_digest'] = $requestDigest;
        return $normalized;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $activeCertificate
     * @return array<string,int|string>
     */
    private static function expectedIdentity(
        string $instanceName,
        array $endpoint,
        array $activeCertificate,
    ): array {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $source = \is_array($gateway['certificate_source'] ?? null)
            ? $gateway['certificate_source']
            : [];
        $endpointInstance = \trim((string)(
            $endpoint['instance_name'] ?? $endpoint['name'] ?? ''
        ));
        $domain = self::normalizeDomain((string)($source['domain'] ?? ''));
        $activeDomain = self::normalizeDomain((string)(
            $activeCertificate['domain'] ?? ''
        ));
        $sourceDigest = \strtolower(\trim((string)(
            $source['source_digest'] ?? ''
        )));
        $activeDigest = \strtolower(\trim((string)(
            $activeCertificate['source_digest'] ?? ''
        )));
        $activeTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile((string)(
            $activeCertificate['trust_profile'] ?? ''
        ));
        $activeProvider = ProjectCertificateGenerationStore::normalizeProvider((string)(
            $activeCertificate['provider'] ?? ''
        ));
        $activeMaterialClass = \strtolower(\trim((string)(
            $activeCertificate['material_class'] ?? ''
        )));
        $activeProvenanceDigest = \strtolower(\trim((string)(
            $activeCertificate['provenance_digest'] ?? ''
        )));
        $activeCertificatePath = \trim((string)(
            $activeCertificate['cert_path'] ?? ''
        ));
        $activePrivateKeyPath = \trim((string)(
            $activeCertificate['key_path'] ?? ''
        ));
        $activeLeafFingerprint = \strtolower(\trim((string)(
            $activeCertificate['leaf_fingerprint_sha256'] ?? ''
        )));
        $sourceGeneration = (int)($source['generation'] ?? 0);
        $activeGeneration = (int)($activeCertificate['generation'] ?? 0);
        $requestedMode = \strtolower(\trim((string)(
            $gateway['requested_mode'] ?? ''
        )));
        $effectiveMode = \strtolower(\trim((string)($gateway['mode'] ?? '')));
        if (!self::validInstanceName($instanceName)
            || !\hash_equals($instanceName, $endpointInstance)
            || !\hash_equals(GatewayStartupDecision::MODE_AUTO, $requestedMode)
            || !\hash_equals(GatewayStartupDecision::MODE_GATEWAY, $effectiveMode)
            || !\hash_equals('nginx', \strtolower(\trim((string)(
                $endpoint['edge_adapter'] ?? ''
            ))))
            || ($gateway['certificate_pending'] ?? false) === true
            || $domain === ''
            || !\hash_equals($domain, $activeDomain)
            || $sourceGeneration < 1
            || $sourceGeneration !== $activeGeneration
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
            || !\hash_equals($sourceDigest, $activeDigest)
        ) {
            throw new \RuntimeException(
                'Gateway startup fallback requires the current auto-mode Master '
                . 'and exact active certificate generation.',
            );
        }
        foreach ([
            'trust_profile' => $activeTrustProfile,
            'provider' => $activeProvider,
            'material_class' => $activeMaterialClass,
            'provenance_digest' => $activeProvenanceDigest,
            'cert_path' => $activeCertificatePath,
            'key_path' => $activePrivateKeyPath,
            'leaf_fingerprint_sha256' => $activeLeafFingerprint,
        ] as $sourceField => $expectedValue) {
            if (!\hash_equals(
                $expectedValue,
                (string)($source[$sourceField] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Gateway startup fallback certificate provenance changed.',
                );
            }
        }
        return [
            'instance_name' => $instanceName,
            'project_uuid' => \strtolower(\trim((string)(
                $gateway['project_uuid'] ?? ''
            ))),
            'instance_generation' => (int)($gateway['instance_generation'] ?? 0),
            'master_pid' => (int)($endpoint['master_pid'] ?? 0),
            'master_epoch' => (int)($endpoint['master_epoch'] ?? 0),
            'master_launch_id' => \strtolower(\trim((string)(
                $gateway['launch_id'] ?? ''
            ))),
            'requested_mode' => $requestedMode,
            'effective_mode' => $effectiveMode,
            'certificate_domain' => $domain,
            'certificate_generation' => $activeGeneration,
            'certificate_source_digest' => $activeDigest,
            'certificate_trust_profile' => $activeTrustProfile,
            'certificate_provider' => $activeProvider,
            'certificate_material_class' => $activeMaterialClass,
            'certificate_provenance_digest' => $activeProvenanceDigest,
            'certificate_path' => $activeCertificatePath,
            'private_key_path' => $activePrivateKeyPath,
            'certificate_leaf_fingerprint_sha256' => $activeLeafFingerprint,
        ];
    }

    /** @param array<string,mixed> $request */
    private static function digest(array $request): string
    {
        $ordered = self::ordered($request);
        return \hash('sha256', \json_encode(
            $ordered,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,int|string>
     */
    private static function ordered(array $request): array
    {
        $ordered = [];
        foreach (self::DIGEST_FIELDS as $field) {
            if (!\array_key_exists($field, $request)) {
                throw new \RuntimeException(
                    'Gateway startup fallback request field is missing: ' . $field,
                );
            }
            $value = $request[$field];
            if (!\is_int($value) && !\is_string($value)) {
                throw new \RuntimeException(
                    'Gateway startup fallback request field has an invalid type: ' . $field,
                );
            }
            $ordered[$field] = $value;
        }
        return $ordered;
    }

    private static function normalizeDomain(string $domain): string
    {
        return \strtolower(\rtrim(\trim($domain), '.'));
    }

    private static function validInstanceName(string $instanceName): bool
    {
        return $instanceName !== ''
            && \strlen($instanceName) <= 128
            && !\str_contains($instanceName, "\0")
            && !\str_contains($instanceName, '/')
            && !\str_contains($instanceName, '\\');
    }

    private static function validAbsolutePath(string $path): bool
    {
        if ($path === '' || \strlen($path) > 4096 || \str_contains($path, "\0")) {
            return false;
        }
        return PHP_OS_FAMILY === 'Windows'
            ? \preg_match(
                '/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D',
                $path,
            ) === 1
            : \str_starts_with($path, '/');
    }
}
