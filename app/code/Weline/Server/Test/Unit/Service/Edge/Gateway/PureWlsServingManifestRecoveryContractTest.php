<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Source contracts cover cold-start branches whose real execution is bound to
 * BP, process locks and listener ownership. The adjacent behavioral assertion
 * proves that the complete reused selector identity is accepted by the
 * startup-fallback envelope without allocating a new certificate generation.
 */
final class PureWlsServingManifestRecoveryContractTest extends TestCase
{
    public function testMultiDomainRecoverySelectsTheRequestedHostInsteadOfTheFirstRoute(): void
    {
        $routes = [
            [
                'domain' => 'first.example.test',
                'certificate_generation' => 41,
            ],
            [
                'domain' => 'requested.example.test',
                'certificate_generation' => 42,
            ],
        ];
        $method = new \ReflectionMethod(
            \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::class,
            'selectActiveRouteForRequestedHost',
        );

        self::assertSame(
            $routes[1],
            $method->invoke(null, $routes, 'REQUESTED.EXAMPLE.TEST.'),
        );
        self::assertNull($method->invoke(null, $routes, 'missing.example.test'));

        $execute = $this->methodSource(Start::class, 'execute');
        self::assertMatchesRegularExpression(
            '/NativeServingManifestStartupRecovery::fromEndpoint\(.*?resolveCertificateHost\(/s',
            $execute,
        );
    }

    public function testTerminalEndpointCanRecoverItsImmutablePreviousMasterFence(): void
    {
        $endpoint = [
            'master_pid' => 0,
            'master_epoch' => 9,
            'lifecycle_state' => 'failed',
            'gateway' => [
                'instance_id' => 'shop',
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
                'instance_generation' => 12,
                'launch_id' => \str_repeat('a', 32),
                'backend_identity_schema' => 'wls-backend-listener-identity/2',
            ],
        ];
        $payload = [
            'instance_id' => 'shop',
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'instance_generation' => 12,
            'master_pid' => 3210,
            'master_epoch' => 9,
            'launch_id' => \str_repeat('a', 32),
        ];
        $method = new \ReflectionMethod(
            \Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::class,
            'terminalRecoveryFence',
        );

        self::assertSame([
            'master_pid' => 3210,
            'master_epoch' => 9,
            'launch_id' => \str_repeat('a', 32),
            'instance_generation' => 12,
        ], $method->invoke(null, $endpoint, $endpoint['gateway'], $payload));

        $starting = $endpoint;
        $starting['lifecycle_state'] = 'starting';
        self::assertNull($method->invoke(null, $starting, $starting['gateway'], $payload));
        $foreign = $payload;
        $foreign['launch_id'] = \str_repeat('b', 32);
        self::assertNull($method->invoke(null, $endpoint, $endpoint['gateway'], $foreign));
    }

    public function testMonotonicRebuildUsesWholeProjectAuthorityAndPreservesSiblingDomain(): void
    {
        $ensure = $this->methodSource(Start::class, 'ensureSslCertificate');
        $rebuildAt = \strpos(
            $ensure,
            'if ($this->nativeServingManifestRebuildRequired)',
        );
        $proofAt = \strpos($ensure, '$manifestRecoveryProof', (int)$rebuildAt);
        self::assertIsInt($rebuildAt);
        self::assertIsInt($proofAt);
        $rebuild = \substr($ensure, $rebuildAt, $proofAt - $rebuildAt);

        self::assertGreaterThanOrEqual(
            2,
            \substr_count($rebuild, '$this->nativeServingManifestRebuildActiveDomains'),
        );
        self::assertStringContainsString('->authoritySnapshot(', $rebuild);
        self::assertStringContainsString('$selectedDomain = $certificateHost;', $rebuild);
        self::assertStringContainsString('$selectedDomain = $candidateDomain;', $rebuild);
        self::assertMatchesRegularExpression(
            '/activeProjectCertificateResult\(\s*\$selectedDomain,/s',
            $rebuild,
        );
        self::assertStringContainsString(
            "\$recovered['code'] = 'TLS_SERVING_MANIFEST_REBUILT'",
            $rebuild,
        );

        $execute = $this->methodSource(Start::class, 'execute');
        self::assertMatchesRegularExpression(
            '/\$config\[\'ssl_domain\'\]\s*=\s*\(\$servingManifestRecovery\s*'
                . '\|\|\s*\(\$sslResult\[\'project_generation_reused\'\].*?\)\s*===\s*true\)'
                . '\s*\?\s*\(string\)\(\$sslResult\[\'domain\'\]/s',
            $execute,
        );
    }

    public function testReusedSelectorCarriesTheCompleteAfterImageWithoutActivation(): void
    {
        $execute = $this->methodSource(Start::class, 'execute');
        $start = \strpos(
            $execute,
            "} elseif ((\$sslResult['project_generation_reused'] ?? false) === true) {",
        );
        self::assertIsInt($start);
        $end = \strpos($execute, '} elseif ($sslEnabled', $start);
        self::assertIsInt($end);
        $reused = \substr($execute, $start, $end - $start);

        foreach ($this->completeAfterImageFields() as $field) {
            self::assertStringContainsString("'{$field}' =>", $reused, $field);
        }
        self::assertStringNotContainsString('->activate(', $reused);
        self::assertStringNotContainsString('allocateCertificateGeneration', $reused);
        self::assertStringNotContainsString('ProjectCertificateGenerationStore', $reused);

        $result = $this->methodSource(Start::class, 'activeProjectCertificateResult');
        foreach ($this->completeAfterImageFields() as $field) {
            self::assertStringContainsString("'{$field}' =>", $result, $field);
        }
        self::assertStringContainsString("'project_generation_reused' => true", $result);
        self::assertStringNotContainsString('->activate(', $result);

        $managed = $this->methodSource(
            ManagedNginxConfigWriter::class,
            'resolveManagedCertificateGeneration',
        );
        foreach ([
            'domain',
            'generation',
            'source_digest',
            'cert_path',
            'key_path',
            'chain_path',
            'leaf_fingerprint_sha256',
            'cert_sha256',
            'key_sha256',
            'chain_sha256',
        ] as $field) {
            self::assertStringContainsString("'{$field}'", $managed, $field);
            self::assertStringContainsString("'{$field}' =>", $reused, $field);
        }
    }

    public function testServingManifestRecoveryCarriesItsSelectedDomainAfterImage(): void
    {
        $execute = $this->methodSource(Start::class, 'execute');
        $start = \strpos($execute, 'if ($servingManifestRecovery) {');
        self::assertIsInt($start);
        $end = \strpos(
            $execute,
            "} elseif ((\$sslResult['project_generation_reused'] ?? false) === true) {",
            $start,
        );
        self::assertIsInt($end);
        $recovery = \substr($execute, $start, $end - $start);

        self::assertStringContainsString("'domain' =>", $recovery);
        self::assertStringContainsString("\$sslResult['domain']", $recovery);
    }

    public function testAutoFallbackAcceptsTheExactReusedSelectorIdentity(): void
    {
        $domain = 'sibling.example.test';
        $sourceDigest = \str_repeat('b', 64);
        $trustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_TEST;
        $provider = ProjectCertificateGenerationStore::PROVIDER_SELF_SIGNED;
        $materialClass = ProjectCertificateGenerationStore::MATERIAL_CLASS_SELF_SIGNED;
        $provenanceDigest = ProjectCertificateGenerationStore::provenanceDigest(
            $domain,
            $sourceDigest,
            $trustProfile,
            $provider,
            $materialClass,
        );
        $certificatePath = \PHP_OS_FAMILY === 'Windows'
            ? 'C:\\wls\\snapshots\\fullchain.pem'
            : '/tmp/wls/snapshots/fullchain.pem';
        $privateKeyPath = \PHP_OS_FAMILY === 'Windows'
            ? 'C:\\wls\\snapshots\\privkey.pem'
            : '/tmp/wls/snapshots/privkey.pem';
        $active = [
            'domain' => $domain,
            'generation' => 17,
            'source_digest' => $sourceDigest,
            'trust_profile' => $trustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => $provenanceDigest,
            'leaf_fingerprint_sha256' => \str_repeat('d', 64),
            'cert_path' => $certificatePath,
            'key_path' => $privateKeyPath,
            'chain_path' => '',
            'cert_sha256' => \str_repeat('e', 64),
            'key_sha256' => \str_repeat('f', 64),
            'chain_sha256' => '',
        ];
        $endpoint = [
            'name' => 'shop',
            'instance_name' => 'shop',
            'master_pid' => 3210,
            'master_epoch' => 9,
            'edge_adapter' => 'nginx',
            'gateway' => [
                'mode' => 'gateway',
                'requested_mode' => 'auto',
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
                'instance_generation' => 12,
                'launch_id' => \str_repeat('a', 32),
                'certificate_pending' => false,
                'certificate_source' => [
                    'domain' => $domain,
                    'generation' => 17,
                    'source_digest' => $sourceDigest,
                    'trust_profile' => $trustProfile,
                    'provider' => $provider,
                    'material_class' => $materialClass,
                    'provenance_digest' => $provenanceDigest,
                    'cert_path' => $certificatePath,
                    'key_path' => $privateKeyPath,
                    'leaf_fingerprint_sha256' => \str_repeat('d', 64),
                ],
            ],
        ];

        $issued = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $active,
            'gateway registration failed',
            1_700_000_000,
        );
        $validated = GatewayStartupFallbackRequest::assertMatches(
            $issued,
            'shop',
            $endpoint,
            $active,
            1_700_000_010,
        );

        self::assertSame($domain, $validated['certificate_domain']);
        self::assertSame(17, $validated['certificate_generation']);
        self::assertSame($provenanceDigest, $validated['certificate_provenance_digest']);
    }

    public function testTombstoneAndAbsentAuthorityCannotFallThroughToLegacyPemOrSigning(): void
    {
        $ensure = $this->methodSource(Start::class, 'ensureSslCertificate');
        $rebuildAt = \strpos(
            $ensure,
            'if ($this->nativeServingManifestRebuildRequired)',
        );
        $configuredAt = \strpos($ensure, '$configuredCertPath', (int)$rebuildAt);
        self::assertIsInt($rebuildAt);
        self::assertIsInt($configuredAt);
        $authorityGate = \substr($ensure, $rebuildAt, $configuredAt - $rebuildAt);

        $invalidAt = \strpos(
            $authorityGate,
            "'code' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID'",
        );
        $httpOnlyAt = \strpos(
            $authorityGate,
            "'code' => 'TLS_CERTIFICATE_RETIRED_HTTP_ONLY'",
        );
        self::assertIsInt($invalidAt);
        self::assertIsInt($httpOnlyAt);
        self::assertStringContainsString(
            'has neither a current certificate selector nor a tombstone',
            $authorityGate,
        );
        self::assertStringContainsString(
            'starting an HTTP-only runtime without PEM material',
            $authorityGate,
        );
        foreach ([
            'tryUseStartupCertificateFiles(',
            'restoreManagedCertificateForConfig(',
            'generateSelfSignedCertificate(',
            'ensureCertificate(',
        ] as $forbiddenFallback) {
            self::assertStringNotContainsString(
                $forbiddenFallback,
                $authorityGate,
                $forbiddenFallback,
            );
        }
    }

    public function testCertificateLifecycleAlwaysPrecedesPublicationTransaction(): void
    {
        $publish = $this->methodSource(
            ProjectServingManifestStore::class,
            'publishFromRegistration',
        );
        self::assertStringContainsString(
            'withCertificateAuthorityPublicationTransaction(',
            $publish,
        );
        self::assertStringNotContainsString('->withPublicationTransaction(', $publish);

        $ordered = $this->methodSource(
            ProjectServingManifestStore::class,
            'withCertificateAuthorityPublicationTransaction',
        );
        $lifecycleAt = \strpos($ordered, '->withCertificateLifecycleLock(');
        $publicationAt = \strpos($ordered, '->withPublicationTransaction(');
        self::assertIsInt($lifecycleAt);
        self::assertIsInt($publicationAt);
        self::assertLessThan(
            $publicationAt,
            $lifecycleAt,
            'The project certificate lifecycle lock must be acquired before publication.',
        );

        foreach ([
            'handleGatewayFallbackCommand',
            'handleRunningGatewayFallbackEnable',
        ] as $method) {
            $orchestrator = $this->methodSource(ServiceOrchestrator::class, $method);
            self::assertStringContainsString(
                '->withCertificateAuthorityPublicationTransaction(',
                $orchestrator,
                $method,
            );
            self::assertStringNotContainsString(
                '->withPublicationTransaction(',
                $orchestrator,
                $method,
            );
        }
        $orchestratorClass = (string)\file_get_contents(
            (string)(new \ReflectionClass(ServiceOrchestrator::class))->getFileName(),
        );
        self::assertSame(0, \substr_count(
            $orchestratorClass,
            '->withPublicationTransaction(',
        ));
    }

    /** @return list<string> */
    private function completeAfterImageFields(): array
    {
        return [
            'domain',
            'generation',
            'source_digest',
            'trust_profile',
            'provider',
            'material_class',
            'provenance_digest',
            'leaf_fingerprint_sha256',
            'cert_path',
            'key_path',
            'chain_path',
            'cert_sha256',
            'key_sha256',
            'chain_sha256',
        ];
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = \file($reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
