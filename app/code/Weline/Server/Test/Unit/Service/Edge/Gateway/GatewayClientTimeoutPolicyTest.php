<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

final class GatewayClientTimeoutPolicyTest extends TestCase
{
    public function testRepairResponseCoversThePublicationProbeWindow(): void
    {
        $client = new GatewayClient(timeoutSeconds: 2.0);

        foreach (['repair', 'revoke', 'transfer', 'upgrade'] as $operation) {
            self::assertSame(
                90.0,
                $this->responseTimeout($client, 'admin', $operation),
                $operation,
            );
        }
        self::assertSame(2.0, $this->responseTimeout($client, 'admin', 'status'));
        foreach (['register', 'renew', 'drain', 'unregister'] as $operation) {
            self::assertSame(
                90.0,
                $this->responseTimeout($client, 'project', $operation),
                $operation,
            );
        }
        self::assertSame(2.0, $this->responseTimeout($client, 'project', 'heartbeat'));
        self::assertSame(2.0, $this->responseTimeout($client, 'project', 'own-status'));
    }

    public function testWindowsBrokerCoversTheAdminPublicationResponseWindow(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/windows/wls_gateway_broker.c',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            '#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS 90000U',
            $source,
        );
        self::assertStringContainsString(
            '#define WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS 90000U',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/wls_connect_controller\(\s*channel->controller_port,\s*'
                . 'wcscmp\(channel->channel, L"admin"\) == 0\s*'
                . '\? WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS\s*'
                . ': WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS\s*,\s*'
                . 'channel->fencing\s*\)/s',
            $source,
        );
    }

    public function testCompleteAcknowledgementIsValidatedBeforeAnyFurtherDeadlineGate(): void
    {
        foreach ([
            'GatewayClient.php'
                => 'throw new \\RuntimeException(self::UNPROVEN_RESPONSE_ERROR);',
            'GatewayEmergencyRevocationClient.php'
                => 'Native gateway guardian returned no complete revocation acknowledgement.',
        ] as $file => $validationMessage) {
            $source = \file_get_contents(
                \dirname(__DIR__, 5) . '/Service/Edge/Gateway/' . $file,
            );
            self::assertIsString($source);
            $read = \strpos($source, '$line = @\\fgets');
            $validation = \strpos($source, $validationMessage, (int)$read);
            self::assertIsInt($read, $file);
            self::assertIsInt($validation, $file);

            $commitBoundary = \substr($source, $read, $validation - $read);
            self::assertStringNotContainsString(
                'remainingDeadlineSeconds($deadlineMonotonic)',
                $commitBoundary,
                $file . ' must authenticate a complete acknowledgement even when the caller deadline crossed after the read.',
            );
        }
    }

    public function testCurrentUnprovenResponseFailureRemainsIdempotentlyRetryable(): void
    {
        $method = new ReflectionMethod(
            GatewayHostManager::class,
            'publicationStatusTransportFailureRetryable',
        );
        $manager = new GatewayHostManager();

        self::assertTrue($method->invoke(
            $manager,
            new \RuntimeException(GatewayClient::UNPROVEN_RESPONSE_ERROR),
        ));
        self::assertFalse($method->invoke(
            $manager,
            new \RuntimeException('WLS Gateway returned an invalid protocol response.'),
        ));
    }

    private function responseTimeout(
        GatewayClient $client,
        string $channel,
        string $operation,
    ): float {
        $method = new ReflectionMethod(GatewayClient::class, 'responseTimeoutSeconds');
        return (float)$method->invoke($client, $channel, $operation);
    }
}
