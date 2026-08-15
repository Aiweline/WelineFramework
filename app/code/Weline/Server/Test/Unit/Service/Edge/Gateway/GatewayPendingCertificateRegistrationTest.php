<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;

final class GatewayPendingCertificateRegistrationTest extends TestCase
{
    public function testExactProductionPendingEndpointSurvivesPublicRouteFiltering(): void
    {
        $builder = $this->builder();
        $certificateMap = [
            'localhost' => $this->pendingMaterial(),
            '127.0.0.1' => $this->pendingMaterial(),
        ];

        $certificateMap = $this->appendPending(
            $builder,
            $certificateMap,
            'shop.example.com',
            $this->pendingSource(),
            true,
        );
        $preflight = $this->invoke(
            $builder,
            'preflightCertificateRoutes',
            [$certificateMap],
        );
        $public = $this->invoke(
            $builder,
            'publicRegistrationCertificateRoutes',
            [$preflight],
        );

        self::assertCount(1, $public);
        self::assertSame('shop.example.com', $public[0]['domain']);
        self::assertSame('pending', $public[0]['material']['certificate_state']);
        self::assertSame('', $public[0]['material']['cert']);
        self::assertSame('', $public[0]['material']['key']);
        self::assertSame('external', $public[0]['material']['provider']);
    }

    public function testPendingSourceWithoutTheOuterPendingFenceIsNotPublished(): void
    {
        $builder = $this->builder();
        $certificateMap = ['localhost' => $this->pendingMaterial()];

        self::assertSame($certificateMap, $this->appendPending(
            $builder,
            $certificateMap,
            'shop.example.com',
            $this->pendingSource(),
            false,
        ));
    }

    /** @param array<string,mixed> $sourceOverride */
    #[DataProvider('invalidPendingAfterImages')]
    public function testMalformedPendingEndpointAfterImagesFailClosed(
        string $publicHost,
        string $endpointCertificate,
        string $endpointPrivateKey,
        array $sourceOverride,
        string $trustProfile = 'production',
    ): void {
        $this->expectException(\RuntimeException::class);
        $this->appendPending(
            $this->builder(),
            [],
            $publicHost,
            \array_replace($this->pendingSource(), $sourceOverride),
            true,
            $endpointCertificate,
            $endpointPrivateKey,
            $trustProfile,
        );
    }

    /** @return iterable<string,array{string,string,string,array<string,mixed>,string}> */
    public static function invalidPendingAfterImages(): iterable
    {
        yield 'wildcard' => ['*.example.com', '', '', [], 'production'];
        yield 'domain mismatch' => [
            'shop.example.com',
            '',
            '',
            ['domain' => 'other.example.com'],
            'production',
        ];
        yield 'profile mismatch' => [
            'shop.example.com',
            '',
            '',
            ['trust_profile' => 'test'],
            'production',
        ];
        yield 'test policy' => [
            'shop.example.com',
            '',
            '',
            ['trust_profile' => 'test'],
            'test',
        ];
        yield 'endpoint certificate' => [
            'shop.example.com',
            '/tmp/cert.pem',
            '',
            [],
            'production',
        ];
        yield 'source certificate' => [
            'shop.example.com',
            '',
            '',
            ['cert_path' => '/tmp/cert.pem'],
            'production',
        ];
        yield 'source digest' => [
            'shop.example.com',
            '',
            '',
            ['source_digest' => \str_repeat('a', 64)],
            'production',
        ];
        yield 'nonzero generation' => [
            'shop.example.com',
            '',
            '',
            ['generation' => 1],
            'production',
        ];
        yield 'wrong provider' => [
            'shop.example.com',
            '',
            '',
            ['provider' => 'none'],
            'production',
        ];
    }

    /** @return array<string,mixed> */
    private function pendingSource(): array
    {
        return [
            'domain' => 'shop.example.com',
            'cert_path' => '',
            'key_path' => '',
            'generation' => 0,
            'source_digest' => '',
            'trust_profile' => 'production',
            'provider' => 'external',
            'material_class' => '',
            'provenance_digest' => '',
            'leaf_fingerprint_sha256' => '',
            'pending' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function pendingMaterial(): array
    {
        return [
            'cert' => '',
            'key' => '',
            'chain' => '',
            'cert_type' => 'exact',
            'force_https' => 1,
            'force_root_to_www' => 0,
            'certificate_state' => 'pending',
            'provider' => 'external',
        ];
    }

    private function builder(): GatewayRegistrationBuilder
    {
        return (new \ReflectionClass(GatewayRegistrationBuilder::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param array<mixed,mixed> $certificateMap
     * @param array<string,mixed> $source
     * @return array<mixed,mixed>
     */
    private function appendPending(
        GatewayRegistrationBuilder $builder,
        array $certificateMap,
        string $publicHost,
        array $source,
        bool $pending,
        string $endpointCertificate = '',
        string $endpointPrivateKey = '',
        string $trustProfile = 'production',
    ): array {
        return $this->invoke($builder, 'appendEndpointPendingGenerationFallback', [
            $certificateMap,
            $publicHost,
            $endpointCertificate,
            $endpointPrivateKey,
            $source,
            $trustProfile,
            $pending,
        ]);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invoke(
        GatewayRegistrationBuilder $builder,
        string $method,
        array $arguments,
    ): mixed {
        return (new \ReflectionMethod($builder, $method))->invokeArgs(
            $builder,
            $arguments,
        );
    }
}
