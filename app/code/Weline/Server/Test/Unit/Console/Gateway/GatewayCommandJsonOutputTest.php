<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\AbstractGatewayCommand;

final class GatewayCommandJsonOutputTest extends TestCase
{
    public function testSuccessUsesStableSingleDocumentEnvelope(): void
    {
        $command = $this->probeCommand();

        \ob_start();
        $command->emitSuccess(['generation' => 7]);
        $output = (string)\ob_get_clean();
        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('wls-gateway-command/1', $decoded['schema']);
        self::assertTrue($decoded['ok']);
        self::assertSame(['generation' => 7], $decoded['payload']);
        self::assertStringEndsWith(PHP_EOL, $output);
    }

    public function testFailureUsesStableErrorShapeWithoutHumanOutput(): void
    {
        $command = $this->probeCommand();

        \ob_start();
        $exit = $command->emitFailure();
        $output = (string)\ob_get_clean();
        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $exit);
        self::assertSame('wls-gateway-command/1', $decoded['schema']);
        self::assertFalse($decoded['ok']);
        self::assertSame([], $decoded['payload']);
        self::assertSame([
            'code' => 'probe_failed',
            'message' => 'probe message',
            'details' => ['retryable' => false],
        ], $decoded['error']);
        self::assertStringNotContainsString("\033", $output);
    }

    private function probeCommand(): object
    {
        return new class extends AbstractGatewayCommand {
            public function execute(array $args = [], array $data = []): int
            {
                return 0;
            }

            public function emitSuccess(array $payload): void
            {
                $statusSource = \file_get_contents(
                    \dirname(__DIR__, 4) . '/Console/Server/Gateway/Status.php',
                );
                \PHPUnit\Framework\Assert::assertIsString($statusSource);
                \PHPUnit\Framework\Assert::assertStringContainsString(
                    '$this->gateway()->status();',
                    $statusSource,
                );
                \PHPUnit\Framework\Assert::assertStringNotContainsString(
                    'administratorStatus()',
                    $statusSource,
                );

                $this->output($payload, true);
            }

            public function emitFailure(): int
            {
                return $this->failure(
                    'probe message',
                    true,
                    'probe_failed',
                    ['retryable' => false],
                );
            }
        };
    }
}
