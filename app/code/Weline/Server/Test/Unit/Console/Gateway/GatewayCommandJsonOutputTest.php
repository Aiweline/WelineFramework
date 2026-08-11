<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\AbstractGatewayCommand;
use Weline\Server\Console\Server\Gateway\Rebootstrap;

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

    public function testOutputRecursivelyRedactsLegacyGatewaySecrets(): void
    {
        $command = $this->probeCommand();
        $marker = 'must-never-reach-cli-output';

        \ob_start();
        $command->emitSuccess([
            'routes' => [[
                'backend_identity' => [
                    'edge_capability_secret' => $marker,
                    'edge_capability_digest' => \str_repeat('a', 64),
                ],
                'credential_id' => \str_repeat('b', 32),
                'credential_secret' => $marker,
                'certificate_fingerprint' => \str_repeat('c', 64),
            ]],
            'admin_token' => $marker,
            'credential_installed' => true,
            'signature' => \str_repeat('d', 64),
        ]);
        $output = (string)\ob_get_clean();
        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $route = $decoded['payload']['routes'][0];

        self::assertStringNotContainsString($marker, $output);
        self::assertArrayNotHasKey('edge_capability_secret', $route['backend_identity']);
        self::assertSame(\str_repeat('a', 64), $route['backend_identity']['edge_capability_digest']);
        self::assertSame(\str_repeat('b', 32), $route['credential_id']);
        self::assertArrayNotHasKey('credential_secret', $route);
        self::assertSame(\str_repeat('c', 64), $route['certificate_fingerprint']);
        self::assertArrayNotHasKey('admin_token', $decoded['payload']);
        self::assertTrue($decoded['payload']['credential_installed']);
        self::assertSame(\str_repeat('d', 64), $decoded['payload']['signature']);
    }

    public function testRebootstrapCommandPublishesReplaySafeHelpAndGuardsPackagePath(): void
    {
        $command = new Rebootstrap();
        $command->__init();
        $help = $command->help();
        self::assertIsString($help);
        self::assertStringContainsString('server:gateway:rebootstrap', $help);
        self::assertStringContainsString('--nonce', $help);
        self::assertStringContainsString('--confirm', $help);
        self::assertStringContainsString('同一 package、nonce 与 profile', $help);

        \ob_start();
        $exit = $command->execute([
            'json' => true,
            'confirm' => true,
            'package' => 'relative/package',
            'nonce' => \str_repeat('a', 32),
        ]);
        $output = (string)\ob_get_clean();
        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $exit);
        self::assertFalse($decoded['ok']);
        self::assertSame(
            'absolute_package_required',
            $decoded['error']['code'],
        );
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
