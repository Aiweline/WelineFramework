<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;

final class GatewayDomainIdnaFailClosedTest extends TestCase
{
    public function testInvalidAsciiAlabelIsRejectedByBuilderAndServingManifest(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('IDNA fail-closed conversion requires ext-intl.');
        }
        $invalid = 'xn--a.example';
        self::assertFalse(@\idn_to_ascii(
            $invalid,
            IDNA_DEFAULT,
            \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0,
        ));

        $normalizer = new \ReflectionMethod(
            GatewayRegistrationBuilder::class,
            'normalizeGatewayDomain',
        );
        $normalizer->setAccessible(true);
        self::assertNull($normalizer->invoke(
            new GatewayRegistrationBuilder(),
            $invalid,
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IDNA conversion failed');
        ProjectServingManifestStore::normalizeHost($invalid);
    }

    public function testPublicRegistrationFiltersLocalCertificateFacts(): void
    {
        $builder = new GatewayRegistrationBuilder();
        $normalizer = new \ReflectionMethod($builder, 'normalizeGatewayDomain');
        self::assertSame('localhost', $normalizer->invoke($builder, 'localhost'));
        self::assertSame('127.0.0.1', $normalizer->invoke($builder, '127.0.0.1'));
        self::assertSame('::1', $normalizer->invoke($builder, '::1'));

        $exact = ['domain' => 'million-a.wls.test', 'marker' => 'exact'];
        $wildcard = ['domain' => '*.example.test', 'marker' => 'wildcard'];
        $filter = new \ReflectionMethod(
            $builder,
            'publicRegistrationCertificateRoutes',
        );
        self::assertSame([$exact, $wildcard], $filter->invoke($builder, [
            ['domain' => '127.0.0.1', 'marker' => 'ipv4-loopback'],
            ['domain' => '192.0.2.1', 'marker' => 'ipv4-literal'],
            ['domain' => '::1', 'marker' => 'ipv6-loopback'],
            ['domain' => 'localhost', 'marker' => 'localhost'],
            ['domain' => '*.localhost', 'marker' => 'wildcard-localhost'],
            $exact,
            $wildcard,
        ]));

        $build = new \ReflectionMethod($builder, 'buildLocked');
        $lines = \file((string)$build->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $build->getStartLine() - 1,
            $build->getEndLine() - $build->getStartLine() + 1,
        ));
        self::assertStringContainsString(
            '$this->publicRegistrationCertificateRoutes(',
            $source,
        );
    }

    public function testInvalidAsciiAlabelIsRejectedByCertificateStore(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('IDNA fail-closed conversion requires ext-intl.');
        }
        $normalizer = new \ReflectionMethod(
            ProjectCertificateGenerationStore::class,
            'normalizeDomain',
        );
        $normalizer->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IDNA conversion failed');
        $normalizer->invoke(
            new ProjectCertificateGenerationStore((string)BP),
            'xn--a.example',
        );
    }

    public function testControllerRequiresIdnaAndRejectsInvalidAsciiAlabel(): void
    {
        $this->loadController();
        $main = $this->controllerMethodSource('main');
        self::assertStringContainsString("'idn_to_ascii',", $main);
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('Controller IDNA behavior requires ext-intl.');
        }
        $normalizer = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'normalizeDomain',
        );
        $normalizer->setAccessible(true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('IDNA conversion failed');
        $normalizer->invoke(
            (new \ReflectionClass(\WlsEdgeGatewayController::class))
                ->newInstanceWithoutConstructor(),
            'xn--a.example',
        );
    }

    private function loadController(): void
    {
        if (\class_exists('WlsEdgeGatewayController', false)) {
            return;
        }
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
    }

    private function controllerMethodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            $method,
        );
        $lines = \file((string)$reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
