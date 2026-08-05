<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\MasterProcess;

final class MasterProcessDeferredSslPolicyTest extends TestCase
{
    public function testTlsBackendStillTriggersExistingDeferredCertificateFlow(): void
    {
        self::assertTrue($this->probe(true, [])->shouldRetryCertificate());
    }

    public function testAuthenticatedGatewayPendingCertificateTriggersAcmeForHttpBackend(): void
    {
        self::assertTrue($this->probe(false, [
            'gateway' => [
                'mode' => GatewayStartupDecision::MODE_GATEWAY,
                'protocol' => GatewayPaths::PROTOCOL,
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
    }

    public function testAutoFallbackStillTriggersFirstCertificateRetry(): void
    {
        self::assertTrue($this->probe(false, [
            'gateway' => [
                'requested_mode' => GatewayStartupDecision::MODE_AUTO,
                'mode' => GatewayStartupDecision::MODE_WLS,
                'protocol' => GatewayPaths::PROTOCOL,
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
    }

    public function testUntrustedOrNonPendingHttpBackendCannotTriggerCertificateFlow(): void
    {
        self::assertFalse($this->probe(false, [
            'gateway' => [
                'mode' => GatewayStartupDecision::MODE_GATEWAY,
                'protocol' => 'wls-edge/1',
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
        self::assertFalse($this->probe(false, [
            'gateway' => [
                'mode' => GatewayStartupDecision::MODE_WLS,
                'protocol' => GatewayPaths::PROTOCOL,
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
        self::assertFalse($this->probe(false, [
            'gateway' => [
                'requested_mode' => GatewayStartupDecision::MODE_AUTO,
                'mode' => GatewayStartupDecision::MODE_WLS,
                'protocol' => 'wls-edge/1',
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
        self::assertFalse($this->probe(false, [
            'gateway' => [
                'mode' => GatewayStartupDecision::MODE_GATEWAY,
                'protocol' => GatewayPaths::PROTOCOL,
                'certificate_pending' => false,
            ],
        ])->shouldRetryCertificate());
    }

    /** @param array<string,mixed> $config */
    private function probe(bool $sslEnabled, array $config): MasterProcessDeferredSslPolicyProbe
    {
        return new MasterProcessDeferredSslPolicyProbe($sslEnabled, $config);
    }
}

final class MasterProcessDeferredSslPolicyProbe extends MasterProcess
{
    /** @param array<string,mixed> $config */
    public function __construct(bool $sslEnabled, array $config)
    {
        $this->sslEnabled = $sslEnabled;
        $this->config = $config;
    }

    public function shouldRetryCertificate(): bool
    {
        return $this->shouldTriggerDeferredSslRetryAfterStartup();
    }
}
