<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Policy\PolicyStage;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Framework\Runtime\Policy\RuntimePolicyDescriptor;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Provider\GatewayFallbackProvider;
use Weline\Server\Service\Provider\GatewayJoinBackendProvider;
use Weline\Server\Service\Provider\GatewayProvider;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;

final class GatewayFallbackProviderTest extends TestCase
{
    private const HOST_LEASE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $gatewayTokenInstances = [];

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
        foreach ($this->gatewayTokenInstances as $instanceName) {
            $tokenFile =
                \Weline\Server\Service\Edge\Gateway\GatewayBackendIngressTokenStore::tokenFile($instanceName);
            @\unlink($tokenFile);
            @\unlink(\dirname($tokenFile) . DIRECTORY_SEPARATOR . '.state.lock');
            @\rmdir(\dirname($tokenFile));
        }
    }

    public function testTerminalStopDrainCompletionOutranksReversibleFallbackPhase(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_FALLBACK,
            instanceId: 1,
            state: ServiceInstance::STATE_DRAINING,
            ipcClientId: 91,
            metadata: [
                'fallback_listener_phase' =>
                    GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
            ],
        );
        $orchestrator->getRegistry()->addInstance($instance);
        (new \ReflectionProperty(ServiceOrchestrator::class, 'pendingStopReason'))
            ->setValue($orchestrator, 'server_stop');

        (new \ReflectionMethod(ServiceOrchestrator::class, 'handleDrainingComplete'))
            ->invoke($orchestrator, ['reason' => 'server_stop'], 91);

        self::assertSame(ServiceInstance::STATE_STOPPING, $instance->state);
        self::assertSame('server_stop', $instance->getMeta('exit_reason'));
    }

    public function testReversibleFallbackPhaseStillIgnoresGenericCompletionOutsideStop(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_FALLBACK,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 92,
            metadata: [
                'fallback_listener_phase' =>
                    GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
            ],
        );
        $orchestrator->getRegistry()->addInstance($instance);

        (new \ReflectionMethod(ServiceOrchestrator::class, 'handleDrainingComplete'))
            ->invoke($orchestrator, [], 92);

        self::assertSame(ServiceInstance::STATE_READY, $instance->state);
    }

    public function testProviderBuildsSingleLoopbackTlsListenerWithH2AndH1Only(): void
    {
        $certificate = $this->temporaryFile('certificate');
        $privateKey = $this->temporaryFile('private-key');
        $context = $this->context([
            'protocols' => ['http2', 'http/1.1'],
            'preferred' => 'http2',
            'alt_svc' => false,
        ]);
        $provider = new GatewayFallbackProvider(
            port: 24567,
            certificate: $certificate,
            privateKey: $privateKey,
            hostLeaseId: self::HOST_LEASE_ID,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame(ControlMessage::ROLE_GATEWAY_FALLBACK, $provider->getRole());
        self::assertStringEndsWith('worker_ssl.php', $command->script);
        self::assertContains('--gateway-fallback', $command->arguments);
        self::assertContains('--wls-runtime-topology=direct', $command->arguments);
        self::assertContains('--wls-listener-mode=single', $command->arguments);
        self::assertContains('--worker-count=1', $command->arguments);
        self::assertNotContains('--ssl-cert=' . $certificate, $command->arguments);
        self::assertNotContains('--ssl-key=' . $privateKey, $command->arguments);
        self::assertContains(
            '--serving-manifest=' . \sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'wls-serving-manifest-unit.json',
            $command->arguments,
        );
        self::assertContains('--serving-manifest-generation=1', $command->arguments);
        self::assertContains(
            '--serving-manifest-digest=' . \str_repeat('f', 64),
            $command->arguments,
        );
        self::assertContains('--serving-instance-generation=17', $command->arguments);

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
            hostLeaseId: self::HOST_LEASE_ID,
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
            hostLeaseId: self::HOST_LEASE_ID,
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
            hostLeaseId: self::HOST_LEASE_ID,
        );

        $command = $provider->buildCommand(1, $this->context());

        self::assertSame('::', $command->arguments[0] ?? null);
        self::assertContains(
            '--public-origin=https://shop.example.test:24570',
            $command->arguments,
        );
        self::assertNotContains('--public-origin=https://[::]:24570', $command->arguments);
        self::assertContains(
            '--gateway-host-lease-id=' . self::HOST_LEASE_ID,
            $command->arguments,
        );
    }

    public function testWildcardOnlyProviderDoesNotTurnBindIpIntoTlsAuthority(): void
    {
        $provider = new GatewayFallbackProvider(
            port: 24572,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
            bindHost: '127.0.0.1',
            hostLeaseId: self::HOST_LEASE_ID,
        );
        $context = $this->context([], [
            'certificate_source' => ['domain' => '*.example.test'],
        ]);

        $command = $provider->buildCommand(1, $context);

        self::assertContains('--public-origin=', $command->arguments);
        self::assertNotContains(
            '--public-origin=https://127.0.0.1:24572',
            $command->arguments,
        );
    }

    public function testSslFallbackWorkerAcceptsLeaseBoundLiteralPublicBindPolicy(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/bin/worker_ssl.php',
        );

        $fallbackGuardStart = \strpos($source, 'if ($isGatewayFallbackWorker');
        self::assertIsInt($fallbackGuardStart);
        $nextFallbackGuard = \strpos(
            $source,
            'if ($isGatewayFallbackWorker',
            $fallbackGuardStart + 1,
        );
        self::assertIsInt($nextFallbackGuard);
        $fallbackBindGuard = \substr(
            $source,
            $fallbackGuardStart,
            $nextFallbackGuard - $fallbackGuardStart,
        );
        self::assertStringContainsString(
            '\\filter_var($privateListenerHost, FILTER_VALIDATE_IP) === false',
            $fallbackBindGuard,
        );
        self::assertStringNotContainsString(
            "!\\in_array(\$privateListenerHost, ['127.0.0.1', '::1'], true)",
            $fallbackBindGuard,
        );
        self::assertStringContainsString(
            "\\preg_match('/^[a-f0-9]{32}\$/D', \$gatewayHostLeaseId)",
            $source,
        );
    }

    public function testProviderRejectsUnresolvedBindHostname(): void
    {
        $provider = new GatewayFallbackProvider(
            port: 24571,
            certificate: $this->temporaryFile('certificate'),
            privateKey: $this->temporaryFile('private-key'),
            bindHost: 'bind.example.test',
            hostLeaseId: self::HOST_LEASE_ID,
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

    public function testExplicitPureWlsDoesNotStartOrBuildGatewayAgent(): void
    {
        $context = $this->context([], ['requested_mode' => 'wls'], 'wls');
        $provider = new GatewayProvider();

        self::assertFalse($provider->isEnabled($context));
        $staleEffectiveMode = $this->context(
            [],
            ['requested_mode' => 'wls'],
            'gateway',
        );
        self::assertFalse($provider->isEnabled($staleEffectiveMode));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must not start');
        $provider->buildCommand(1, $staleEffectiveMode);
    }

    public function testPersistedPureWlsWithoutRequestedModeDoesNotStartGatewayAgent(): void
    {
        $provider = new GatewayProvider();
        $context = $this->context([], [], 'wls');

        self::assertFalse($provider->isEnabled($context));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must not start');
        $provider->buildCommand(1, $context);
    }

    public function testLegacyPromotionMayBuildGatewayAgentOnlyThroughExplicitLifecycle(): void
    {
        $provider = new GatewayProvider();
        $context = $this->context([], ['requested_mode' => 'legacy'], 'legacy');

        self::assertFalse($provider->isEnabled($context));
        $command = $provider->buildCommand(1, $context);
        self::assertNotContains('--certificate-retirement-only', $command->arguments);
    }

    public function testExplicitRetirementOnlyAgentReplaysTheFullOutboxEveryHeartbeat(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Console/Server/Gateway/Agent.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $loop = new \ReflectionMethod(Agent::class, 'runCertificateRetirementOnlyLoop');
        $loopSource = \implode('', \array_slice(
            $lines,
            $loop->getStartLine() - 1,
            $loop->getEndLine() - $loop->getStartLine() + 1,
        ));
        self::assertStringContainsString('self::HEARTBEAT_SECONDS', $loopSource);
        self::assertStringContainsString(
            'pendingRetirementIntents($now + 0.25)',
            $loopSource,
        );
        self::assertStringContainsString("'retirements'", $loopSource);
        self::assertStringContainsString('startDesiredStateJob(', $loopSource);

        $worker = new \ReflectionMethod(Agent::class, 'executeDesiredStateWorker');
        $workerSource = \implode('', \array_slice(
            $lines,
            $worker->getStartLine() - 1,
            $worker->getEndLine() - $worker->getStartLine() + 1,
        ));
        self::assertMatchesRegularExpression(
            '/replayPendingCertificateRetirements\(\s*75\.0,\s*8,\s*'
                . '\$mutationDeadline,\s*\)/',
            $workerSource,
        );
        self::assertStringContainsString("if (\$action !== 'retirements')", $workerSource);
    }

    public function testPromotionAgentWaitsForAuthenticatedJoinBackendBeforeRegistrationReplay(): void
    {
        self::assertFalse(Agent::canReplayRegistration(true, 'NOT_REQUIRED'));
        self::assertFalse(Agent::canReplayRegistration(true, 'STARTING'));
        self::assertFalse(Agent::canReplayRegistration(true, 'STALE'));
        self::assertTrue(Agent::canReplayRegistration(true, 'ACTIVE'));
        self::assertTrue(Agent::canReplayRegistration(false, 'NOT_REQUIRED'));
        self::assertSame('gateway_agent_enable', ControlMessage::ACTION_GATEWAY_AGENT_ENABLE);
        self::assertSame('gateway_agent_status', ControlMessage::ACTION_GATEWAY_AGENT_STATUS);
        self::assertSame('gateway_agent_commit', ControlMessage::ACTION_GATEWAY_AGENT_COMMIT);
        self::assertSame('gateway_agent_disable', ControlMessage::ACTION_GATEWAY_AGENT_DISABLE);
        self::assertSame(
            'gateway_legacy_nginx_restore',
            ControlMessage::ACTION_GATEWAY_LEGACY_NGINX_RESTORE,
        );
    }

    public function testPromotionRuntimeEndpointTransactionCommitsOrRestoresAtomically(): void
    {
        $endpoint = [
            'master_pid' => 12345,
            'master_epoch' => 3,
            'edge_adapter' => 'nginx',
            'gateway' => [
                'requested_mode' => 'legacy',
                'mode' => 'legacy',
                'protocol' => 'legacy/1',
                'degraded_reason' => 'legacy-observation',
                'public_http' => 80,
                'public_https' => 443,
                'epoch' => 'legacy-epoch',
                'join_backend' => ['state' => 'NOT_REQUIRED'],
                'fallback_state' => 'LEGACY_ACTIVE',
                'runtime_generation' => 7,
            ],
        ];
        $prepare = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'applyPromotionGatewayRuntimeIntent',
        );
        $commit = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'commitPromotionGatewayRuntimeIntentProjection',
        );
        $restore = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'restorePromotionGatewayRuntimeIntentProjection',
        );
        $transactionId = \str_repeat('b', 32);
        $policyDigest = \str_repeat('d', 64);

        $prepared = $prepare->invoke(
            null,
            $endpoint,
            12345,
            3,
            $transactionId,
            $policyDigest,
            1000,
        );
        self::assertSame('wls', $prepared['edge_adapter']);
        self::assertSame('auto', $prepared['gateway']['requested_mode']);
        self::assertSame('wls', $prepared['gateway']['mode']);
        self::assertSame('wls-edge/2', $prepared['gateway']['protocol']);
        self::assertSame('ATTACHING', $prepared['gateway']['promotion_state']);
        self::assertSame(
            $endpoint['gateway']['join_backend'],
            $prepared['gateway']['join_backend'],
        );
        $preparedAgain = $prepare->invoke(
            null,
            $prepared,
            12345,
            3,
            \str_repeat('c', 32),
            $policyDigest,
            1001,
        );
        self::assertSame(
            $transactionId,
            $preparedAgain['gateway']['promotion_transaction_id'],
        );
        self::assertSame(
            $policyDigest,
            $preparedAgain['gateway']['promotion_previous_edge']['runtime_policy_digest'],
        );

        $committed = $commit->invoke(
            null,
            $preparedAgain,
            12345,
            3,
            $transactionId,
            1002,
        );
        self::assertSame('COMMITTED', $committed['gateway']['promotion_state']);
        self::assertSame(
            $transactionId,
            $committed['gateway']['promotion_committed_transaction_id'],
        );
        self::assertArrayNotHasKey('promotion_previous_edge', $committed['gateway']);
        self::assertArrayNotHasKey('promotion_transaction_id', $committed['gateway']);
        self::assertSame(
            $committed,
            $commit->invoke(null, $committed, 12345, 3, $transactionId, 1003),
        );

        $mutatedDuringAttach = $preparedAgain;
        $mutatedDuringAttach['gateway']['mode'] = 'gateway';
        $mutatedDuringAttach['gateway']['degraded_reason'] = '';
        $mutatedDuringAttach['gateway']['public_http'] = 18080;
        $mutatedDuringAttach['gateway']['public_https'] = 18443;
        $mutatedDuringAttach['gateway']['epoch'] = \str_repeat('e', 32);
        $mutatedDuringAttach['gateway']['join_backend'] = ['state' => 'ACTIVE'];
        $mutatedDuringAttach['gateway']['fallback_state'] = 'NATIVE_EDGE_DRAINING';
        $mutatedDuringAttach['gateway']['runtime_generation'] = 99;
        $mutatedDuringAttach['gateway']['runtime_observed_at'] = 'attach-observation';
        $mutatedDuringAttach['gateway']['runtime_observed_timestamp'] = 1001;
        $mutatedDuringAttach['gateway']['native_edge'] = ['state' => 'DRAINING'];

        $restored = $restore->invoke(null, $mutatedDuringAttach, 12345, 3);
        self::assertSame('nginx', $restored['edge_adapter']);
        self::assertSame('legacy', $restored['gateway']['requested_mode']);
        self::assertSame('legacy', $restored['gateway']['mode']);
        self::assertSame('legacy/1', $restored['gateway']['protocol']);
        foreach ([
            'degraded_reason',
            'public_http',
            'public_https',
            'epoch',
            'join_backend',
            'fallback_state',
            'runtime_generation',
        ] as $field) {
            self::assertSame(
                $endpoint['gateway'][$field],
                $restored['gateway'][$field],
                'Promotion rollback must restore gateway field ' . $field,
            );
        }
        self::assertArrayNotHasKey('runtime_observed_at', $restored['gateway']);
        self::assertArrayNotHasKey('runtime_observed_timestamp', $restored['gateway']);
        self::assertArrayNotHasKey('native_edge', $restored['gateway']);
        self::assertArrayNotHasKey('promotion_previous_edge', $restored['gateway']);
        self::assertArrayNotHasKey('promotion_state', $restored['gateway']);
    }

    public function testPromotionRejectsMissingRuntimePolicyRollbackPoint(): void
    {
        $prepare = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'applyPromotionGatewayRuntimeIntent',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime policy digest is invalid');
        $prepare->invoke(
            null,
            ['master_pid' => 12345, 'master_epoch' => 3],
            12345,
            3,
            \str_repeat('b', 32),
            '',
            1000,
        );
    }

    public function testGatewayJoinPolicyWideningPreservesEveryAcceptedPolicyFact(): void
    {
        $descriptor = new RuntimePolicyDescriptor(
            id: 'unit.gateway.policy',
            priority: 11,
            stage: PolicyStage::MANDATORY_REQUEST,
            requiredInputs: ['host'],
            matcher: ['type' => 'host_guard', 'strict' => true],
            action: ['type' => 'reject', 'status' => 400],
            critical: true,
            supportedTopologies: ['direct', 'dispatcher'],
            capabilities: ['unit_capability'],
        );
        $active = RuntimePolicyBundle::fromDescriptors(
            descriptors: [$descriptor],
            version: 'unit-v7',
            topology: 'dispatcher',
            metadata: [
                'limits' => ['header_bytes' => 65536],
                'provider_registry_digest' => \str_repeat('a', 64),
            ],
            generatedAt: 1000,
        );
        $widen = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'buildGatewayJoinRuntimePolicyBundle',
        );

        $joined = $widen->invoke(null, $active);

        self::assertInstanceOf(RuntimePolicyBundle::class, $joined);
        self::assertSame('both', $joined->topology);
        self::assertSame($active->version, $joined->version);
        self::assertSame($active->metadata, $joined->metadata);
        self::assertSame(
            \array_map(static fn (RuntimePolicyDescriptor $item): array => $item->toArray(), $active->descriptors),
            \array_map(static fn (RuntimePolicyDescriptor $item): array => $item->toArray(), $joined->descriptors),
        );
        self::assertNotSame($active->digest, $joined->digest);
        self::assertTrue($joined->supportsTopology('direct'));
        self::assertTrue($joined->supportsTopology('dispatcher'));
    }

    public function testAutoFallbackKeepsAgentAndBuildsAuthenticatedLoopbackJoinBackend(): void
    {
        $instanceName = 'unit-gateway-backend-' . \bin2hex(\random_bytes(6));
        $this->gatewayTokenInstances[] = $instanceName;
        $context = $this->context(
            [],
            ['requested_mode' => 'auto'],
            'wls',
            $instanceName,
        );
        $agent = new GatewayProvider();
        $backend = new GatewayJoinBackendProvider(
            port: 24569,
            inheritedListener: \PHP_OS_FAMILY !== 'Windows',
            runtimeEnabled: true,
            instanceCount: 3,
            hostLeaseId: self::HOST_LEASE_ID,
        );

        self::assertTrue($agent->isEnabled($context));
        self::assertNotContains(
            '--certificate-retirement-only',
            $agent->buildCommand(1, $context)->arguments,
        );
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
                    '--gateway-backend-token-file=',
                ),
            ),
        );
    }

    public function testOnlyAuthenticatedReadyGatewayAgentMayUseTokenlessFallbackCommands(): void
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
        $clients->setValue($server, [
            7 => [
                'socket' => $agentSocket,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
                'state' => MasterControlServer::STATE_READY,
                'managed_child_authenticated' => true,
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
                'managed_child_authenticated' => true,
            ],
        ]);
        $authorize = new \ReflectionMethod($server, 'isAuthorizedControlCommand');

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
            ['action' => ControlMessage::ACTION_GATEWAY_AGENT_ENABLE],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_AGENT_STATUS],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_AGENT_COMMIT],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_AGENT_DISABLE],
        ));
        self::assertFalse($authorize->invoke(
            $server,
            7,
            ['action' => ControlMessage::ACTION_GATEWAY_LEGACY_NGINX_RESTORE],
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
        $clients->setValue($server, [
            7 => [
                'socket' => $agentSocket,
                'role' => ControlMessage::ROLE_GATEWAY_AGENT,
                'state' => MasterControlServer::STATE_READY,
                'managed_child_authenticated' => false,
            ],
        ]);
        self::assertFalse($authorize->invoke(
            $server,
            7,
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
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_GATEWAY_AGENT_ENABLE,
                'control_token' => 'unit-control-token',
            ],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_GATEWAY_AGENT_STATUS,
                'control_token' => 'unit-control-token',
            ],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_GATEWAY_AGENT_COMMIT,
                'control_token' => 'unit-control-token',
            ],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_GATEWAY_AGENT_DISABLE,
                'control_token' => 'unit-control-token',
            ],
        ));
        self::assertTrue($authorize->invoke(
            $server,
            8,
            [
                'action' => ControlMessage::ACTION_GATEWAY_LEGACY_NGINX_RESTORE,
                'control_token' => 'unit-control-token',
            ],
        ));
        $server->close();
    }

    public function testFallbackReversibleDrainKeepsCredentialUntilFinalDisable(): void
    {
        $drain = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'handleRunningGatewayFallbackDrain',
        );
        $lines = \file($drain->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $drain->getStartLine() - 1,
            $drain->getEndLine() - $drain->getStartLine() + 1,
        ));

        self::assertStringContainsString('beginDrain(', $source);
        self::assertStringContainsString('sendGatewayFallbackListenerTransition(', $source);
        self::assertStringContainsString('restoreActiveAfterFailedDrain(', $source);
        self::assertStringContainsString('LISTENER_PHASE_DRAIN_PREPARED', $source);
        self::assertStringNotContainsString('suspendGatewayFallbackCredential(', $source);
        self::assertStringNotContainsString('resumeGatewayFallbackCredential(', $source);
        self::assertStringNotContainsString('markDraining(', $source);
        self::assertStringNotContainsString('closeGatewayFallbackListener(', $source);
        self::assertStringNotContainsString('$this->sendDrainToInstance($instance, 300.0);', $source);
        self::assertStringNotContainsString(
            '$instance->state = ServiceInstance::STATE_DRAINING;',
            $source,
        );

        $ack = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'handleGatewayFallbackListenerAck',
        );
        $ackSource = \implode('', \array_slice(
            $lines,
            $ack->getStartLine() - 1,
            $ack->getEndLine() - $ack->getStartLine() + 1,
        ));
        self::assertStringContainsString('validateGatewayFallbackListenerAck(', $ackSource);
        self::assertStringContainsString('acknowledgeDrain(', $ackSource);
        self::assertStringContainsString('gatewayFallbackServingFenceDigest(', $ackSource);
        self::assertStringContainsString('compensateGatewayFallbackToDrain(', $ackSource);
        self::assertStringContainsString('closeGatewayFallbackListener(', $ackSource);
        self::assertStringNotContainsString('suspendGatewayFallbackCredential(', $ackSource);
        self::assertStringNotContainsString(
            '$instance->state = ServiceInstance::STATE_DRAINING;',
            $ackSource,
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($ackSource, 'gatewayFallbackUndrainCommitAllowed('),
            'UNDRAIN must re-check the full stop/certificate fence at the ACTIVE commit barrier.',
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($ackSource, '$afterImage = $leases->status('),
            'Both DRAIN_ACKED and ACTIVE publication failures must reconcile a committed after-image.',
        );

        foreach ([
            'handleFailedGatewayFallbackListenerAck',
            'compensateGatewayFallbackToDrain',
        ] as $methodName) {
            $method = new \ReflectionMethod(ServiceOrchestrator::class, $methodName);
            $methodSource = \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
            self::assertStringNotContainsString(
                '$instance->state = ServiceInstance::STATE_DRAINING;',
                $methodSource,
                $methodName . ' must not enter the terminal service-drain lifecycle.',
            );
        }

        $drainingComplete = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'handleDrainingComplete',
        );
        $drainingCompleteSource = \implode('', \array_slice(
            $lines,
            $drainingComplete->getStartLine() - 1,
            $drainingComplete->getEndLine() - $drainingComplete->getStartLine() + 1,
        ));
        $fallbackGuard = \strpos(
            $drainingCompleteSource,
            'ControlMessage::ROLE_GATEWAY_FALLBACK',
        );
        $terminalMutation = \strpos(
            $drainingCompleteSource,
            '$this->markAutonomousWorkerExitPending(',
        );
        self::assertIsInt($fallbackGuard);
        self::assertIsInt($terminalMutation);
        self::assertLessThan(
            $terminalMutation,
            $fallbackGuard,
            'A reversible fallback listener must be rejected before generic terminal drain handling.',
        );
        self::assertStringContainsString(
            'GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED',
            $drainingCompleteSource,
        );

        $digest = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'gatewayFallbackActionDigest',
        );
        $digestSource = \implode('', \array_slice(
            $lines,
            $digest->getStartLine() - 1,
            $digest->getEndLine() - $digest->getStartLine() + 1,
        ));
        self::assertStringContainsString(
            'ControlMessage::gatewayFallbackListenerActionDigest(',
            $digestSource,
        );
        self::assertStringNotContainsString('\\hash(', $digestSource);
    }

    public function testFallbackWorkerSupportsExactReversibleListenerTransition(): void
    {
        $transition = \str_repeat('a', 32);
        $pidNamespaceId = PHP_OS_FAMILY === 'Linux'
            ? 'pid:[4026531836]'
            : '';
        $identity = [
            'schema' => 'wls-gateway-fallback-listener/1',
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174099',
            'wls_instance' => 'fallback-worker-contract',
            'role' => 'gateway_fallback',
            'slot_id' => 'gateway_fallback#1',
            'service_generation' => 9,
            'service_lease_id' => \str_repeat('1', 32),
            'worker_pid' => 22001,
            'worker_process_birth' => \str_repeat('2', 64),
            'worker_pid_namespace_id' => $pidNamespaceId,
            'worker_launch_id' => \str_repeat('3', 32),
            'master_pid' => 22000,
            'master_epoch' => 7,
            'master_launch_id' => \str_repeat('4', 32),
            'master_process_birth' => \str_repeat('5', 64),
            'master_pid_namespace_id' => $pidNamespaceId,
            'port' => 24567,
            'host_lease_instance' => 'fallback-worker-contract-gateway-fallback',
            'host_lease_id' => \str_repeat('6', 32),
            'host_boot_id' => \str_repeat('7', 64),
            'bind_host' => '127.0.0.1',
            'listener_proof_digest' => \str_repeat('8', 64),
            'listener_transport' => 'posix_inherited_fd',
            'listener_receipt_digest' => \str_repeat('9', 64),
        ];
        $digest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transition,
            '',
            $identity,
        );
        $encoded = ControlMessage::gatewayFallbackListenerTransition(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transition,
            $digest,
            '',
            $identity,
            300,
        );
        $message = \json_decode(\trim($encoded), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(ControlMessage::TYPE_DRAIN, $message['type']);
        self::assertSame($transition, $message['transition_id']);
        self::assertSame('DRAINING', $message['target_listener_state']);
        self::assertSame($digest, $message['action_digest']);
        self::assertSame($identity, $message['identity']);

        $ack = ControlMessage::gatewayFallbackListenerAck(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transition,
            $digest,
            '',
            $identity,
            true,
        );
        $receipt = \json_decode(\trim($ack), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(
            ControlMessage::TYPE_GATEWAY_FALLBACK_LISTENER_ACK,
            $receipt['type'],
        );
        self::assertSame('DRAINING', $receipt['target_listener_state']);
        self::assertSame('DRAINING', $receipt['listener_state']);

        $worker = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        self::assertStringContainsString('case \\Weline\\Server\\IPC\\ControlMessage::TYPE_UNDRAIN:', $worker);
        self::assertStringContainsString('$gatewayFallbackListenerDraining', $worker);
        self::assertStringContainsString(
            'rejectWithoutAdmission: $gatewayFallbackListenerDraining',
            $worker,
        );
        self::assertStringContainsString('gatewayFallbackListenerAck(', $worker);
        self::assertStringContainsString('if (!$isGatewayFallbackWorker)', $worker);
        self::assertStringNotContainsString('$isGatewayFallbackWorker && $ipcDraining', $worker);
        $reversibleStart = \strpos(
            $worker,
            'case \\Weline\\Server\\IPC\\ControlMessage::TYPE_DRAIN:',
        );
        $terminalStart = \strpos($worker, '// 排水模式：', $reversibleStart ?: 0);
        self::assertIsInt($reversibleStart);
        self::assertIsInt($terminalStart);
        $reversibleBranch = \substr(
            $worker,
            $reversibleStart,
            $terminalStart - $reversibleStart,
        );
        self::assertStringNotContainsString('$ipcDraining = true', $reversibleBranch);
        self::assertStringNotContainsString('beginDrain()', $reversibleBranch);
        self::assertStringNotContainsString('initiateGoaway()', $reversibleBranch);
        self::assertStringNotContainsString('drainingComplete(', $reversibleBranch);
    }

    /**
     * @param array<string,mixed> $http
     */
    private function context(
        array $http = [],
        array $gateway = [],
        string $edgeMode = 'gateway',
        string $instanceName = 'unit-gateway-fallback',
    ): ServiceContext
    {
        $http += [
            'protocols' => ['h2', 'h1'],
            'preferred' => 'h2',
            'alt_svc' => false,
        ];
        $capabilityEvidence = [
            'schema' => 'wls-session-capability/1',
            'reason' => 'unit-isolated',
        ];
        $gateway += [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174099',
            'instance_generation' => 17,
            'launch_id' => \str_repeat('a', 32),
        ];
        $gateway['backend_capability_launch'] ??= [
            'schema' => \Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityResolver::LAUNCH_SNAPSHOT_SCHEMA,
            'instance_generation' => 17,
            'launch_id' => \str_repeat('a', 32),
            'mode' => 'isolated',
            'evidence' => $capabilityEvidence,
            'evidence_digest' => \hash(
                'sha256',
                \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                    $capabilityEvidence,
                ),
            ),
        ];
        return new ServiceContext(
            instanceName: $instanceName,
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
                    'serving_manifest_path' => \sys_get_temp_dir()
                        . DIRECTORY_SEPARATOR
                        . 'wls-serving-manifest-unit.json',
                    'serving_manifest_generation' => 1,
                    'serving_manifest_digest' => \str_repeat('f', 64),
                    'serving_instance_generation' => 17,
                    'serving_certificate_trust_profile' => 'test',
                    'edge' => [
                        'adapter' => 'wls',
                        'mode' => $edgeMode,
                    ],
                    'gateway' => $gateway,
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
