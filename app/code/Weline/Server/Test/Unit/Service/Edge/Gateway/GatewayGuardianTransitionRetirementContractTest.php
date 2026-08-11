<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayGuardianTransitionRetirementContractTest extends TestCase
{
    private string $gateway;

    protected function setUp(): void
    {
        $this->gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
    }

    public function testTerminalRecoveryTransactionIsRetiredBeforeTheNextRequest(): void
    {
        $protocol = (string)\file_get_contents(
            $this->gateway . '/GatewayGuardianTransitionProtocol.php',
        );
        $ensure = $this->methodBody(
            $protocol,
            'private function ensureCommitRequest(',
            'private function recoverHandshakeArtifacts(',
        );
        self::assertStringContainsString(
            '$this->retireCompletedHandshakeForNextRequestWhileLocked()',
            $ensure,
        );
        self::assertStringContainsString(
            '$this->retireOrphanedTerminalHandshakeWhileLocked();',
            $ensure,
        );
        self::assertLessThan(
            \strpos(
                $ensure,
                '$existing = GatewayProjectStateFilesystem::readOptional(',
            ),
            \strpos($ensure, '$this->resumeHandshakeRetirementWhileLocked();'),
        );
        self::assertLessThan(
            \strpos($ensure, '$head = $this->generationHead()->readWhileLocked();'),
            \strpos(
                $ensure,
                '$this->retireOrphanedTerminalHandshakeWhileLocked();',
            ),
        );

        $terminal = $this->methodBody(
            $protocol,
            'private function terminalHandshakeRetirement(',
            'private function publishHandshakeRetirementWhileLocked(',
        );
        foreach ([
            "!\\hash_equals('STABLE', (string)(\$head['phase'] ?? ''))",
            "\$purpose === 'rollback'",
            '$this->assertTerminalRecoveryTransaction(',
            "'transaction_sha256' => \$transactionRaw === null",
        ] as $contract) {
            self::assertStringContainsString($contract, $terminal);
        }
        self::assertStringContainsString(
            "(int)\$transaction['sequence'] !== 26",
            $protocol,
        );
        self::assertStringContainsString(
            '$this->recoveryTransactionRawAtSequence(',
            $protocol,
        );
    }

    public function testSignedRetirementMarkerMakesMultiFileDeletionCrashReplayable(): void
    {
        $protocol = (string)\file_get_contents(
            $this->gateway . '/GatewayGuardianTransitionProtocol.php',
        );
        self::assertStringContainsString(
            'WLS-GUARDIAN-TRANSITION-RETIREMENT/1',
            $protocol,
        );
        self::assertMatchesRegularExpression(
            '/\\$this->signature\\(\\s*'
                . '\\$this->encodeHandshakeRetirementUnsigned\\(/s',
            $protocol,
        );
        $resume = $this->methodBody(
            $protocol,
            'private function resumeHandshakeRetirementWhileLocked(',
            'private function retireCompletedHandshakeForNextRequestWhileLocked(',
        );
        $request = \strpos(
            $resume,
            '$this->paths->guardianTransitionRequestFile() => [',
        );
        $ack = \strpos(
            $resume,
            '$this->paths->guardianTransitionAcknowledgementFile() => [',
        );
        $transaction = \strpos(
            $resume,
            '$this->paths->guardianRecoveryTransactionFile() => [',
        );
        $markerRemoval = \strrpos(
            $resume,
            "'Recovery Guardian handshake retirement'",
        );
        self::assertIsInt($request);
        self::assertIsInt($ack);
        self::assertIsInt($transaction);
        self::assertIsInt($markerRemoval);
        self::assertLessThan($ack, $request);
        self::assertLessThan($transaction, $ack);
        self::assertLessThan($markerRemoval, $transaction);
        self::assertStringContainsString(
            'foreach (\\array_keys($definitions) as $artifact)',
            $resume,
        );
    }

    public function testRetirementLeafIsAFormalCrossPlatformTrustContract(): void
    {
        $paths = (string)\file_get_contents($this->gateway . '/GatewayPaths.php');
        $protocol = (string)\file_get_contents(
            $this->gateway . '/GatewayGuardianTransitionProtocol.php',
        );
        $installer = (string)\file_get_contents(
            $this->gateway . '/GatewayPlatformServiceInstaller.php',
        );
        $manager = (string)\file_get_contents(
            $this->gateway . '/HostGatewayPackageManager.php',
        );
        $posix = (string)\file_get_contents(
            $this->gateway . '/Native/posix/wls_gateway_launcher.c',
        );
        foreach ([$paths, $protocol, $installer, $manager, $posix] as $source) {
            self::assertStringContainsString(
                'guardian-transition.retirement',
                $source,
            );
        }
        self::assertStringContainsString(
            'public function guardianTransitionRetirementFile(): string',
            $paths,
        );
        self::assertStringContainsString(
            '$this->paths->guardianTransitionRetirementFile()',
            $protocol,
        );
    }

    private function methodBody(
        string $source,
        string $startNeedle,
        string $endNeedle,
    ): string {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start);
        $end = \strpos($source, $endNeedle, $start);
        self::assertIsInt($end);
        return \substr($source, $start, $end - $start);
    }
}
