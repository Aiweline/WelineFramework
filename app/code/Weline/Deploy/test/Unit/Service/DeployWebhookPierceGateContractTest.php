<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployWebhookPierceGate;
use Weline\Framework\Http\Fpc\FpcPierceStamp;

/**
 * WLS Webhook URL 附带 FPC 签章；ack 指纹与「确认已换」状态机。
 */
final class DeployWebhookPierceGateContractTest extends TestCase
{
    public function testSourcePinsWlsGateAndAckKey(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/DeployWebhookPierceGate.php';
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('Runtime::isWls()', $source);
        self::assertStringContainsString('ACK_CONFIG_KEY', $source);
        self::assertStringContainsString('webhook_pierce_ack_hash', $source);
        self::assertStringContainsString('FpcPierceStamp::QUERY_NAME', $source);
        self::assertStringContainsString('appendPierceQuery', $source);
    }

    public function testConfigSavePreservesAckFingerprint(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Backend/Config.php';
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('DeployWebhookPierceGate::ACK_CONFIG_KEY', $source);
        self::assertStringContainsString('confirmPierceUrl', $source);
        self::assertStringContainsString('pierceAckPending', $source);
    }

    public function testAppendPierceQueryPreservesPathAndSetsQuery(): void
    {
        $gate = new class () extends DeployWebhookPierceGate {
            public function __construct()
            {
                // Skip parent: methods under test are overridden.
            }

            public function shouldAttachPierceToWebhookUrl(): bool
            {
                return true;
            }

            public function currentPierceSecret(): string
            {
                return \str_repeat('ab', 32);
            }
        };

        $url = $gate->appendPierceQuery('https://example.test/~wh~deadbeef');
        self::assertStringStartsWith('https://example.test/~wh~deadbeef?', $url);
        self::assertStringContainsString(FpcPierceStamp::QUERY_NAME . '=', $url);
        self::assertStringContainsString(\str_repeat('ab', 32), $url);
    }

    public function testAppendPierceSkippedWhenNotWlsMode(): void
    {
        $gate = new class () extends DeployWebhookPierceGate {
            public function __construct()
            {
            }

            public function shouldAttachPierceToWebhookUrl(): bool
            {
                return false;
            }
        };

        $base = 'https://example.test/~wh~deadbeef';
        self::assertSame($base, $gate->appendPierceQuery($base));
    }

    public function testAckPendingClearsAfterAcknowledge(): void
    {
        $secret = \str_repeat('cd', 32);
        $ackBox = (object)['hash' => ''];
        $gate = new class ($secret, $ackBox) extends DeployWebhookPierceGate {
            public function __construct(
                private readonly string $secret,
                private readonly object $ackBox,
            ) {
            }

            public function shouldAttachPierceToWebhookUrl(): bool
            {
                return true;
            }

            public function currentPierceSecret(): string
            {
                return $this->secret;
            }

            public function acknowledgedFingerprint(): string
            {
                return (string)$this->ackBox->hash;
            }

            public function acknowledgeCurrentPierce(): bool
            {
                $this->ackBox->hash = $this->currentPierceFingerprint();

                return $this->ackBox->hash !== '';
            }
        };

        self::assertTrue($gate->isPierceAckPending());
        self::assertTrue($gate->acknowledgeCurrentPierce());
        self::assertFalse($gate->isPierceAckPending());
        self::assertSame(\hash('sha256', $secret), $ackBox->hash);
    }
}
