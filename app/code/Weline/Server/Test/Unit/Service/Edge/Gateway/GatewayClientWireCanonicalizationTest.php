<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayClientWireCanonicalizationTest extends TestCase
{
    public function testPreservesSignedWireNumberLexemesAcrossPhpRuntimes(): void
    {
        $secret = \str_repeat('a', 64);
        $requestId = \str_repeat('b', 32);
        $canonical = '{"epoch":"' . \str_repeat('c', 32)
            . '","ok":true,"payload":{"clock":{"drift":1e+0,"stable":0.0},'
            . '"numeric_string":"1e+0"},"protocol":"' . GatewayPaths::PROTOCOL
            . '","request_id":"' . $requestId . '"}';
        $signature = \hash_hmac('sha256', $canonical, $secret);
        $wire = '{"protocol":"' . GatewayPaths::PROTOCOL
            . '","request_id":"' . $requestId
            . '","ok":true,"epoch":"' . \str_repeat('c', 32)
            . '","payload":{"clock":{"drift":1e+0,"stable":0.0},'
            . '"numeric_string":"1e+0"},"signature":"' . $signature . '"}' . "\n";

        $method = new \ReflectionMethod(GatewayClient::class, 'canonicalResponseFromWire');
        $actual = $method->invoke(null, $wire, $signature);

        self::assertSame($canonical, $actual);
        self::assertSame($signature, \hash_hmac('sha256', $actual, $secret));
        self::assertStringContainsString('"numeric_string":"1e+0"', $actual);
    }

    public function testRejectsAResponseWhoseParsedSignatureDoesNotMatch(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'canonicalResponseFromWire');

        $this->expectException(\RuntimeException::class);
        $method->invoke(
            null,
            '{"protocol":"wls-edge/2","signature":"' . \str_repeat('a', 64) . '"}',
            \str_repeat('b', 64),
        );
    }

    public function testAuthenticatedLegacyResponseIsSanitizedAfterVerification(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
        $marker = 'must-not-cross-client-boundary';
        $response = [
            'protocol' => GatewayPaths::PROTOCOL,
            'ok' => true,
            'payload' => [
                'route' => [
                    'edge_capability_secret' => $marker,
                    'edge_capability_digest' => \str_repeat('c', 64),
                ],
            ],
            'signature' => \str_repeat('d', 64),
        ];

        $sanitized = $method->invoke(null, $response, 'project', [
            'operation' => 'own-status',
            'payload' => [],
        ]);

        self::assertArrayNotHasKey('edge_capability_secret', $sanitized['payload']['route']);
        self::assertSame(\str_repeat('c', 64), $sanitized['payload']['route']['edge_capability_digest']);
        self::assertSame(\str_repeat('d', 64), $sanitized['signature']);
    }

    public function testOnlyValidatedEnrollmentCredentialSurvivesSanitization(): void
    {
        $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
        $projectUuid = '11111111-2222-4333-8444-555555555555';
        $hostId = \str_repeat('a', 32);
        $credential = [
            'schema_version' => 1,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \str_repeat('b', 32),
            'secret' => \str_repeat('c', 64),
            'issued_at' => '2026-07-31T00:00:00+00:00',
            'unexpected_secret' => 'drop-me',
        ];
        $request = [
            'operation' => 'enroll',
            'host_id' => $hostId,
            'payload' => ['project_uuid' => $projectUuid],
        ];

        $sanitized = $method->invoke(null, [
            'ok' => true,
            'payload' => ['credential' => $credential],
        ], 'admin', $request);

        self::assertSame(\str_repeat('c', 64), $sanitized['payload']['credential']['secret']);
        self::assertArrayNotHasKey('unexpected_secret', $sanitized['payload']['credential']);

        $credential['credential_id'] = 'invalid';
        $rejected = $method->invoke(null, [
            'ok' => true,
            'payload' => ['credential' => $credential],
        ], 'admin', $request);
        self::assertArrayNotHasKey('credential', $rejected['payload']);
    }
}
