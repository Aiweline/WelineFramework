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

    /**
     * auto 的第三个出口（宿主无 Nginx、本项目托管 Nginx 就绪 → mode=legacy）
     * 同样是「明文 loopback 后端 + 由别的进程终止公网 TLS」，因此同样需要项目
     * 自己的 ACME worker 去取公网证书。漏掉 legacy 会让该实例永远没有公网证书。
     */
    public function testAutoResolvedManagedNginxStillTriggersFirstCertificateRetry(): void
    {
        self::assertTrue($this->probe(false, [
            'gateway' => [
                'requested_mode' => GatewayStartupDecision::MODE_AUTO,
                'mode' => GatewayStartupDecision::MODE_LEGACY,
                'protocol' => GatewayPaths::PROTOCOL,
                'certificate_pending' => true,
            ],
        ])->shouldRetryCertificate());
    }

    /**
     * 显式 legacy（WLS 1.x 迁移）不走 Gateway 首签路径：它没有 WLS Edge
     * Protocol 2 的 protocol 身份，因此不应触发本项目 ACME worker。
     */
    public function testExplicitLegacyNeverTriggersGatewayFirstIssuance(): void
    {
        self::assertFalse($this->probe(false, [
            'gateway' => [
                'requested_mode' => GatewayStartupDecision::MODE_LEGACY,
                'mode' => GatewayStartupDecision::MODE_LEGACY,
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
