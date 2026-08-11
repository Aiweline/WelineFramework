<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Control\ControlPlaneServerInterface;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Provider\GatewayProvider;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;

final class GatewayStartupFallbackControlTest extends TestCase
{
    public function testAgentCannotForgeLauncherRequestWithoutControlToken(): void
    {
        $server = new MasterControlServer();
        $server->setExpectedControlToken('launcher-control-token');
        $clients = new \ReflectionProperty(MasterControlServer::class, 'clients');
        $clients->setValue($server, [41 => [
            'role' => ControlMessage::ROLE_GATEWAY_AGENT,
            'state' => MasterControlServer::STATE_READY,
        ]]);
        $authorize = new \ReflectionMethod(
            MasterControlServer::class,
            'isAuthorizedControlCommand',
        );

        self::assertFalse($authorize->invoke($server, 41, [
            'type' => ControlMessage::TYPE_COMMAND,
            'action' => ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST,
        ]));
        self::assertTrue($authorize->invoke($server, 41, [
            'type' => ControlMessage::TYPE_COMMAND,
            'action' => ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST,
            'control_token' => 'launcher-control-token',
        ]));
    }

    public function testUnregisteredAgentRequestIsRejected(): void
    {
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe();
        $messages = [];
        $control = $this->controlServer($messages, []);
        $this->writePrivate($orchestrator, 'context', $this->context());
        $this->writePrivate($orchestrator, 'controlServer', $control);

        $this->invokeHandler($orchestrator, [
            'msg_id' => 'request-1',
            'instance_name' => 'shop',
        ], 99);

        self::assertCount(1, $messages);
        self::assertSame(99, $messages[0]['client']);
        self::assertFalse((bool)($messages[0]['payload']['success'] ?? true));
        self::assertStringContainsString(
            'current READY project Agent',
            (string)($messages[0]['payload']['message'] ?? ''),
        );
    }

    public function testValidAutoRequestIsForwardedOnlyToCurrentAgent(): void
    {
        $endpoint = $this->endpoint();
        $certificate = $this->certificate();
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe(
            $endpoint,
            $certificate,
        );
        $messages = [];
        $control = $this->controlServer($messages, [41]);
        $this->writePrivate($orchestrator, 'context', $this->context());
        $this->writePrivate($orchestrator, 'controlServer', $control);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: GatewayProvider::ROLE,
            instanceId: 1,
            epoch: 9,
            launchId: 'agent-launch',
            pid: 4321,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 41,
        ));
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $certificate,
            'registration failed',
        );
        $request['msg_id'] = 'request-2';

        $this->invokeHandler($orchestrator, $request, 99);

        self::assertCount(2, $messages);
        self::assertSame(41, $messages[0]['client']);
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST,
            $messages[0]['payload']['action'] ?? null,
        );
        self::assertSame(0, $messages[0]['payload']['requested_port'] ?? null);
        self::assertSame(99, $messages[1]['client']);
        self::assertTrue((bool)($messages[1]['payload']['success'] ?? false));
        self::assertTrue((bool)($messages[1]['payload']['data']['accepted'] ?? false));
    }

    public function testCrossInstanceRequestIsRejectedBeforeAgentDispatch(): void
    {
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe();
        $messages = [];
        $control = $this->controlServer($messages, [41]);
        $this->writePrivate($orchestrator, 'context', $this->context());
        $this->writePrivate($orchestrator, 'controlServer', $control);

        $this->invokeHandler($orchestrator, [
            'msg_id' => 'request-3',
            'instance_name' => 'other',
        ], 99);

        self::assertCount(1, $messages);
        self::assertFalse((bool)($messages[0]['payload']['success'] ?? true));
        self::assertStringContainsString(
            'cross-instance',
            (string)($messages[0]['payload']['message'] ?? ''),
        );
    }

    public function testChangedCertificateIsRejectedWithoutAgentDispatch(): void
    {
        $endpoint = $this->endpoint();
        $certificate = $this->certificate();
        $changedCertificate = $certificate;
        $changedCertificate['generation'] = 18;
        $changedCertificate['source_digest'] = \str_repeat('c', 64);
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe(
            $endpoint,
            $changedCertificate,
        );
        $messages = [];
        $control = $this->controlServer($messages, [41]);
        $this->writePrivate($orchestrator, 'context', $this->context());
        $this->writePrivate($orchestrator, 'controlServer', $control);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: GatewayProvider::ROLE,
            instanceId: 1,
            epoch: 9,
            launchId: 'agent-launch',
            pid: 4321,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 41,
        ));
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $certificate,
            'registration failed',
        );
        $request['msg_id'] = 'request-4';

        $this->invokeHandler($orchestrator, $request, 99);

        self::assertCount(1, $messages);
        self::assertSame(99, $messages[0]['client']);
        self::assertFalse((bool)($messages[0]['payload']['success'] ?? true));
        self::assertStringContainsString(
            'certificate generation',
            (string)($messages[0]['payload']['message'] ?? ''),
        );
    }

    public function testAgentFenceCannotChoosePortAndIsRecheckedAfterReservation(): void
    {
        $endpoint = $this->endpoint();
        $certificate = $this->certificate();
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $certificate,
            'registration failed',
        );
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe(
            $endpoint,
            $certificate,
        );
        $this->writePrivate($orchestrator, 'context', $this->context());
        $validate = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'validateGatewayStartupFallbackAgentFence',
        );

        $validated = $validate->invoke($orchestrator, $request, 0);
        self::assertSame(0, $validated['requested_port'] ?? null);
        $handler = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'handleGatewayFallbackCommand',
        );
        $lines = \file($handler->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $handler->getStartLine() - 1,
            $handler->getEndLine() - $handler->getStartLine() + 1,
        ));
        $reservation = \strpos($source, '->reserveBound(');
        $secondValidation = \strpos(
            $source,
            '$this->validateGatewayStartupFallbackAgentFence(',
            (int)$reservation,
        );
        $childStart = \strpos($source, '$this->startInstance(', (int)$reservation);
        self::assertIsInt($reservation);
        self::assertIsInt($secondValidation);
        self::assertIsInt($childStart);
        self::assertLessThan($secondValidation, $reservation);
        self::assertLessThan($childStart, $secondValidation);
        self::assertStringNotContainsString(
            'gatewayFallbackCertificateSource',
            (string)\file_get_contents($handler->getFileName()),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot select a port');
        $validate->invoke($orchestrator, $request, 24567);
    }

    public function testCertificateRotationInvalidatesAgentFenceBeforeBind(): void
    {
        $endpoint = $this->endpoint();
        $request = GatewayStartupFallbackRequest::issue(
            'shop',
            $endpoint,
            $this->certificate(),
            'registration failed',
        );
        $rotated = $this->certificate();
        $rotated['generation'] = 18;
        $rotated['source_digest'] = \str_repeat('c', 64);
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe(
            $endpoint,
            $rotated,
        );
        $this->writePrivate($orchestrator, 'context', $this->context());
        $validate = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'validateGatewayStartupFallbackAgentFence',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact active certificate generation');
        $validate->invoke($orchestrator, $request, 0);
    }

    public function testFallbackManifestExpectationRequiresExactScalarTypes(): void
    {
        $normalize = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'gatewayFallbackServingManifestExpectation',
        );
        $digest = \str_repeat('a', 64);
        self::assertSame([
            'generation' => 7,
            'digest' => $digest,
            'route_count' => 2,
        ], $normalize->invoke(null, [
            'serving_manifest_generation' => 7,
            'serving_manifest_digest' => $digest,
            'serving_manifest_route_count' => 2,
        ]));

        $this->expectException(\RuntimeException::class);
        $normalize->invoke(null, [
            'serving_manifest_generation' => '7',
            'serving_manifest_digest' => $digest,
            'serving_manifest_route_count' => 2,
        ]);
    }

    public function testFallbackMetadataReportsEveryActiveRoute(): void
    {
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe();
        $metadata = (new \ReflectionMethod(
            ServiceOrchestrator::class,
            'gatewayFallbackEndpointMetadata',
        ))->invoke($orchestrator, '127.0.0.1', 24567, [
            'second.example.test',
            '*.wildcard.example.test',
            'first.example.test',
        ]);

        self::assertSame([
            '*.wildcard.example.test',
            'first.example.test',
            'second.example.test',
        ], $metadata['fallback_route_domains'] ?? null);
        self::assertSame([
            'https://first.example.test:24567',
            'https://second.example.test:24567',
        ], $metadata['fallback_urls'] ?? null);
    }

    public function testWildcardOnlyFallbackReportsBindWithoutInventingIpHttpsUrl(): void
    {
        $orchestrator = new GatewayStartupFallbackOrchestratorProbe();
        $metadata = (new \ReflectionMethod(
            ServiceOrchestrator::class,
            'gatewayFallbackEndpointMetadata',
        ))->invoke($orchestrator, '127.0.0.1', 24567, [
            '*.wildcard.example.test',
        ]);

        self::assertSame('127.0.0.1:24567', $metadata['fallback_bind'] ?? null);
        self::assertSame([], $metadata['fallback_urls'] ?? null);
        self::assertSame(
            ['*.wildcard.example.test'],
            $metadata['fallback_route_domains'] ?? null,
        );
        self::assertContains(
            'hostname_and_sni_required',
            $metadata['fallback_limitations'] ?? [],
        );
        self::assertContains(
            'wildcard_route_requires_concrete_hostname',
            $metadata['fallback_limitations'] ?? [],
        );
    }

    /**
     * @param list<array{client:int,payload:array<string,mixed>}> $messages
     * @param list<int> $existingClients
     */
    private function controlServer(array &$messages, array $existingClients): ControlPlaneServerInterface
    {
        $control = $this->createMock(ControlPlaneServerInterface::class);
        $control->method('clientExists')->willReturnCallback(
            static fn (int $clientId): bool => \in_array($clientId, $existingClients, true),
        );
        $control->method('sendTo')->willReturnCallback(
            static function (int $clientId, string $message) use (&$messages): bool {
                $payload = \json_decode($message, true, 32, JSON_THROW_ON_ERROR);
                $messages[] = ['client' => $clientId, 'payload' => $payload];
                return true;
            },
        );
        return $control;
    }

    /** @param array<string,mixed> $message */
    private function invokeHandler(
        ServiceOrchestrator $orchestrator,
        array $message,
        int $clientId,
    ): void {
        $message['action'] = ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST;
        $method = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'handleCommand',
        );
        $method->invoke($orchestrator, $message, $clientId);
    }

    private function writePrivate(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(ServiceOrchestrator::class, $property);
        $reflection->setValue($target, $value);
    }

    private function context(): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'shop',
            epoch: 9,
            controlPort: 19001,
            masterPid: 3210,
            host: '127.0.0.1',
            mainPort: 19002,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'auto',
                'effective_topology' => 'direct',
                'topology_source' => 'unit-test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'stream',
                'listener_mode' => 'shared_fd',
                'policy_compatible' => true,
                'reason_codes' => ['unit_test'],
                'reason' => 'unit test',
            ]),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => [
                'edge' => [
                    'adapter' => 'nginx',
                    'mode' => 'gateway',
                    'scope' => 'host_gateway',
                ],
                'gateway' => [
                    'protocol' => 'wls-edge/2',
                    'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
                    'epoch' => \str_repeat('d', 32),
                    'requested_mode' => 'auto',
                    'certificate_source' => [
                        'domain' => 'shop.example.test',
                    ],
                ],
                'http' => [
                    'protocols' => ['h1'],
                    'preferred' => 'h1',
                    'tls_session_resumption' => false,
                    'alt_svc' => false,
                ],
            ]],
            publicHost: 'shop.example.test',
            controlToken: \str_repeat('e', 64),
            masterToken: \str_repeat('f', 64),
        );
    }

    /** @return array<string,mixed> */
    private function endpoint(): array
    {
        $certificate = $this->certificate();
        return [
            'name' => 'shop',
            'instance_name' => 'shop',
            'master_pid' => 3210,
            'master_epoch' => 9,
            'edge_adapter' => 'nginx',
            'gateway' => [
                'mode' => 'gateway',
                'requested_mode' => 'auto',
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
                'instance_generation' => 12,
                'launch_id' => \str_repeat('a', 32),
                'certificate_pending' => false,
                'certificate_source' => [
                    'domain' => 'shop.example.test',
                    'generation' => 17,
                    'source_digest' => \str_repeat('b', 64),
                    'trust_profile' => $certificate['trust_profile'],
                    'provider' => $certificate['provider'],
                    'material_class' => $certificate['material_class'],
                    'provenance_digest' => $certificate['provenance_digest'],
                    'cert_path' => $certificate['cert_path'],
                    'key_path' => $certificate['key_path'],
                    'leaf_fingerprint_sha256' => $certificate['leaf_fingerprint_sha256'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function certificate(): array
    {
        $domain = 'shop.example.test';
        $sourceDigest = \str_repeat('b', 64);
        $trustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_TEST;
        $provider = ProjectCertificateGenerationStore::PROVIDER_LOCAL_CA;
        $materialClass = ProjectCertificateGenerationStore::MATERIAL_CLASS_LOCAL_CA;
        return [
            'domain' => $domain,
            'generation' => 17,
            'source_digest' => $sourceDigest,
            'trust_profile' => $trustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => ProjectCertificateGenerationStore::provenanceDigest(
                $domain,
                $sourceDigest,
                $trustProfile,
                $provider,
                $materialClass,
            ),
            'cert_path' => '/tmp/unit-cert.pem',
            'key_path' => '/tmp/unit-key.pem',
            'leaf_fingerprint_sha256' => \str_repeat('c', 64),
        ];
    }
}

final class GatewayStartupFallbackOrchestratorProbe extends ServiceOrchestrator
{
    /**
     * @param array<string,mixed>|null $endpoint
     * @param array<string,mixed>|null $certificate
     */
    public function __construct(
        private readonly ?array $endpoint = null,
        private readonly ?array $certificate = null,
    ) {
        parent::__construct();
    }

    protected function gatewayStartupFallbackEndpoint(string $instanceName): ?array
    {
        unset($instanceName);
        return $this->endpoint;
    }

    protected function gatewayStartupFallbackCertificate(string $domain): ?array
    {
        unset($domain);
        return $this->certificate;
    }
}
