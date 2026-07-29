<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Provider\GatewayFallbackProvider;
use Weline\Server\Service\Provider\GatewayJoinBackendProvider;
use Weline\Server\Service\Provider\GatewayProvider;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;

final class GatewayFallbackProviderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \rtrim(\dirname(__DIR__, 7), '/\\') . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        foreach ([
            'APP_PATH' => BP . 'app' . DS,
            'APP_ETC_PATH' => BP . 'app' . DS . 'etc' . DS,
            'PUB' => BP . 'pub' . DS,
            'VENDOR_PATH' => BP . 'vendor' . DS,
            'APP_CODE_PATH' => BP . 'app' . DS . 'code' . DS,
        ] as $name => $path) {
            if (!\defined($name)) {
                \define($name, $path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @\unlink($file);
        }
    }

    public function testProviderBuildsSingleLoopbackTlsListenerWithH2AndH1Only(): void
    {
        $certificate = $this->temporaryFile('certificate');
        $privateKey = $this->temporaryFile('private-key');
        $context = $this->context([
            'protocols' => ['http2', 'http/1.1'],
            'preferred' => 'http2',
            'protocol_edge' => 'disabled',
            'alt_svc' => false,
        ]);
        $provider = new GatewayFallbackProvider(
            port: 24567,
            certificate: $certificate,
            privateKey: $privateKey,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame(ControlMessage::ROLE_GATEWAY_FALLBACK, $provider->getRole());
        self::assertStringEndsWith('worker_ssl.php', $command->script);
        self::assertContains('--gateway-fallback', $command->arguments);
        self::assertContains('--wls-runtime-topology=direct', $command->arguments);
        self::assertContains('--wls-listener-mode=single', $command->arguments);
        self::assertContains('--worker-count=1', $command->arguments);
        self::assertContains('--ssl-cert=' . $certificate, $command->arguments);
        self::assertContains('--ssl-key=' . $privateKey, $command->arguments);

        $policyArgument = $this->argumentWithPrefix($command->arguments, '--wls-http-policy=');
        $encoded = \substr($policyArgument, \strlen('--wls-http-policy='));
        $padding = (4 - (\strlen($encoded) % 4)) % 4;
        $json = \base64_decode(
            \strtr($encoded . \str_repeat('=', $padding), '-_', '+/'),
            true,
        );
        self::assertIsString($json);
        $policy = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['h2', 'h1'],
            $policy['http_protocol_selection']['protocols'] ?? null,
        );
        self::assertSame('h2', $policy['http_protocol_selection']['preferred'] ?? null);
        self::assertFalse((bool)($policy['http3']['enabled'] ?? true));
        self::assertFalse((bool)($policy['http_protocol_selection']['alt_svc'] ?? true));
    }

    public function testProviderRejectsPortOutsideStableFallbackRange(): void
    {
        $provider = new GatewayFallbackProvider(
            port: 19999,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('20000-29999');
        $provider->buildCommand(1, $this->context());
    }

    public function testProviderUsesMasterOwnedInheritedListenerOnPosix(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Inherited descriptor listener is POSIX-only.');
        }
        $provider = new GatewayFallbackProvider(
            port: 24568,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
            inheritedListener: true,
        );

        $command = $provider->buildCommand(1, $this->context());
        self::assertContains('--wls-listener-mode=shared_fd', $command->arguments);
        self::assertContains('--listen-fd=3', $command->arguments);
        self::assertNotContains('--wls-listener-mode=single', $command->arguments);
    }

    public function testProviderKeepsExplicitIpv6BindButReportsConcreteCertificateOrigin(): void
    {
        $provider = new GatewayFallbackProvider(
            port: 24570,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
            bindHost: '::',
            publicOrigin: 'https://shop.example.test:24570',
        );

        $command = $provider->buildCommand(1, $this->context());

        self::assertSame('::', $command->arguments[0] ?? null);
        self::assertContains(
            '--public-origin=https://shop.example.test:24570',
            $command->arguments,
        );
        self::assertNotContains('--public-origin=https://[::]:24570', $command->arguments);
    }

    public function testProviderRejectsUnresolvedBindHostname(): void
    {
        $provider = new GatewayFallbackProvider(
            port: 24571,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
            bindHost: 'bind.example.test',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolved IPv4 or IPv6');
        $provider->buildCommand(1, $this->context());
    }

    public function testGatewayAgentCommandDoesNotExposeMasterControlToken(): void
    {
        $context = $this->context();
        $provider = new GatewayProvider();

        $command = $provider->buildCommand(1, $context);
        $joined = \implode(' ', $command->arguments);

        self::assertSame(ControlMessage::ROLE_GATEWAY_AGENT, $provider->getRole());
        self::assertStringNotContainsString('unit-control-token', $joined);
        self::assertStringNotContainsString('--control-token=', $joined);
    }

    public function testAutoFallbackKeepsAgentAndBuildsAuthenticatedLoopbackJoinBackend(): void
    {
        $context = $this->context([], ['requested_mode' => 'auto'], 'wls');
        $agent = new GatewayProvider();
        $backend = new GatewayJoinBackendProvider(
            port: 24569,
            inheritedListener: \PHP_OS_FAMILY !== 'Windows',
            runtimeEnabled: true,
            instanceCount: 3,
        );

        self::assertTrue($agent->isEnabled($context));
        self::assertTrue($backend->isEnabled($context));
        self::assertSame(ControlMessage::ROLE_GATEWAY_BACKEND, $backend->getRole());
        self::assertSame(3, $backend->getInstanceCount($context));
        $command = $backend->buildCommand(1, $context);
        self::assertStringEndsWith('worker.php', $command->script);
        self::assertContains('--gateway-join-backend', $command->arguments);
        self::assertContains('--gateway-instance-generation=17', $command->arguments);
        self::assertContains(
            '--gateway-instance-launch-id=' . \str_repeat('a', 32),
            $command->arguments,
        );
        self::assertContains('--worker-count=3', $command->arguments);
        self::assertContains(
            '--gateway-project-uuid=123e4567-e89b-42d3-a456-426614174099',
            $command->arguments,
        );
        $secondCommand = $backend->buildCommand(2, $context);
        self::assertNotSame($command->processName, $secondCommand->processName);
        self::assertStringContainsString('-1-', (string)$command->processName);
        self::assertStringContainsString('-2-', (string)$secondCommand->processName);
        self::assertNotContains('--ssl-cert=', $command->arguments);
        self::assertNotContains('--ssl-key=', $command->arguments);
        self::assertNotEmpty(
            \array_filter(
                $command->arguments,
                static fn (string $argument): bool => \str_starts_with(
                    $argument,
                    '--protocol-edge-token-file=',
                ),
            ),
        );
    }

    public function testOnlyReadyGatewayAgentMayUseTokenlessFallbackCommands(): void
    {
        $server = new MasterControlServer();
        $server->setExpectedControlToken('unit-control-token');
        $agentSocket = \fopen('php://temp', 'r+');
        $workerSocket = \fopen('php://temp', 'r+');
        $registeredAgentSocket = \fopen('php://temp', 'r+');
        self::assertIsResource($agentSocket);
        self::assertIsResource($workerSocket);
        self::assertIsResource($registeredAgentSocket);
        $clients = new \ReflectionProperty($server, 'clients');
        $clients->setAccessible(true);
        $clients->setValue($server, [
            7 => [
                'socket' => $agentSocket,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
                'state' => MasterControlServer::STATE_READY,
            ],
            8 => [
                'socket' => $workerSocket,
                'role' => ControlMessage::ROLE_WORKER,
                'state' => MasterControlServer::STATE_READY,
            ],
            9 => [
                'socket' => $registeredAgentSocket,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
                'state' => MasterControlServer::STATE_REGISTERED,
            ],
        ]);
        $authorize = new \ReflectionMethod($server, 'isAuthorizedControlCommand');
        $authorize->setAccessible(true);

        self::assertTrue($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_BACKEND_ENABLE],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_NATIVE_DRAIN],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_RELOAD],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            8,
            ['action' => ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            9,
            ['action' => ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_RELOAD,
                'control_token' => 'unit-control-token',
            ],
        ));
        $server->close();
    }

    /**
     * @param array<string,mixed> $http
     */
    private function context(
        array $http = [],
        array $gateway = [],
        string $edgeMode = 'gateway',
    ): ServiceContext
    {
        $http += [
            'protocols' => ['h2', 'h1'],
            'preferred' => 'h2',
            'protocol_edge' => 'disabled',
            'alt_svc' => false,
        ];
        return new ServiceContext(
            instanceName: 'unit-gateway-fallback',
            epoch: 3,
            controlPort: 19091,
            masterPid: 12345,
            host: '127.0.0.1',
            mainPort: 19502,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: new RuntimeSelection(
                requestedTopology: RequestedTopology::Auto,
                effectiveTopology: EffectiveTopology::Direct,
                source: 'unit',
                osFamily: 'Linux',
                eventLoopDriver: 'stream_select',
                sslEngine: 'stream',
                listenerMode: 'reuseport',
                policyCompatible: true,
                reasonCodes: ['unit'],
                reason: 'Unit-test runtime selection.',
            ),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'public_origin' => 'https://127.0.0.1:19502/',
                    'edge' => [
                        'adapter' => 'wls',
                        'mode' => $edgeMode,
                    ],
                    'gateway' => $gateway + [
                        'project_uuid' => '123e4567-e89b-42d3-a456-426614174099',
                        'instance_generation' => 17,
                        'launch_id' => \str_repeat('a', 32),
                    ],
                    'http' => $http,
                ],
            ],
            controlToken: 'unit-control-token',
        );
    }

    private function temporaryFile(string $suffix): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'wls-fallback-' . $suffix . '-');
        self::assertIsString($path);
        \file_put_contents($path, $suffix);
        @\chmod($path, 0600);
        $this->temporaryFiles[] = $path;
        return $path;
    }

    /**
     * @param list<string> $arguments
     */
    private function argumentWithPrefix(array $arguments, string $prefix): string
    {
        foreach ($arguments as $argument) {
            if (\str_starts_with($argument, $prefix)) {
                return $argument;
            }
        }
        self::fail('Missing command argument with prefix ' . $prefix);
    }
}
