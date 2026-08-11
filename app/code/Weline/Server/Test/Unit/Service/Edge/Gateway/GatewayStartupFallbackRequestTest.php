<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

final class GatewayStartupFallbackRequestTest extends TestCase
{
    public function testExactAutoRuntimeAndCertificateEnvelopeRoundTrips(): void
    {
        $endpoint = $this->endpoint();
        $certificate = $this->certificate();
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $certificate,
            'registration failed',
            1_700_000_000,
        );

        $validated = GatewayStartupFallbackRequest::assertMatches(
            $request,
            'shop',
            $endpoint,
            $certificate,
            1_700_000_010,
        );

        self::assertSame(0, $validated['requested_port']);
        self::assertSame(17, $validated['certificate_generation']);
        self::assertSame('auto', $validated['requested_mode']);
        self::assertSame('gateway', $validated['effective_mode']);
    }

    public function testCrossInstanceEnvelopeIsRejected(): void
    {
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $this->endpoint(),
            $this->certificate(),
            'registration failed',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current auto-mode Master');
        GatewayStartupFallbackRequest::assertMatches(
            $request,
            'other',
            $this->endpoint(),
            $this->certificate(),
        );
    }

    public function testInvalidCertificateGenerationIsRejected(): void
    {
        $endpoint = $this->endpoint();
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $this->certificate(),
            'registration failed',
        );
        $rotated = $this->certificate();
        $rotated['generation'] = 18;
        $rotated['source_digest'] = \str_repeat('c', 64);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact active certificate generation');
        GatewayStartupFallbackRequest::assertMatches(
            $request,
            'shop',
            $endpoint,
            $rotated,
        );
    }

    public function testExplicitGatewayModeCannotIssueStartupFallback(): void
    {
        $endpoint = $this->endpoint();
        $endpoint['gateway']['requested_mode'] = 'gateway';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current auto-mode Master');
        GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $this->certificate(),
            'registration failed',
        );
    }

    public function testAuthorizedStartupRequestBypassesNinetySecondOutageTimer(): void
    {
        $action = Agent::decideFallbackLifecycleAction(
            now: 10.0,
            dataPlaneHealthy: false,
            fallbackEligible: false,
            controlAvailable: true,
            downSince: 0.0,
            activeSince: 0.0,
            fallbackDrainStartedAt: 0.0,
            lastFallbackCommandAt: 0.0,
            fallbackRequested: false,
            fallbackDrainRequested: false,
            projectDraining: false,
            startupFallbackRequested: true,
        );

        self::assertSame(ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE, $action);
    }

    public function testAgentRejectsRequestFromAnotherMasterLaunch(): void
    {
        $endpoint = $this->endpoint();
        $certificate = $this->certificate();
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $certificate,
            'registration failed',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not belong to this Agent launch');
        Agent::validateStartupFallbackRequest(
            $request,
            $endpoint,
            $certificate,
            'shop',
            '123e4567-e89b-42d3-a456-426614174000',
            12,
            3210,
            9,
            \str_repeat('c', 32),
        );
    }

    /** @return array<string,mixed> */
    private function endpoint(): array
    {
        $certificate = $this->certificate();
        return [
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
                'certificate_source' => $certificate,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function certificate(): array
    {
        $domain = 'shop.example.test';
        $sourceDigest = \str_repeat('b', 64);
        $trustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_TEST;
        $provider = ProjectCertificateGenerationStore::PROVIDER_SELF_SIGNED;
        $materialClass = ProjectCertificateGenerationStore::MATERIAL_CLASS_SELF_SIGNED;
        $base = PHP_OS_FAMILY === 'Windows'
            ? 'C:\\wls-test\\certificates'
            : '/tmp/wls-test/certificates';
        return [
            'domain' => $domain,
            'generation' => 17,
            'source_digest' => $sourceDigest,
            'trust_profile' => $trustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => ProjectCertificateGenerationStore::provenanceDigest(
                $domain,
                $sourceDigest,
                $trustProfile,
                $provider,
                $materialClass,
            ),
            'cert_path' => $base . DIRECTORY_SEPARATOR . 'fullchain.pem',
            'key_path' => $base . DIRECTORY_SEPARATOR . 'privkey.pem',
            'leaf_fingerprint_sha256' => \str_repeat('d', 64),
        ];
    }
}
