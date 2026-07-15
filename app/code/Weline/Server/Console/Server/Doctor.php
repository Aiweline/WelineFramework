<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;
use Weline\Server\Service\Runtime\RuntimeDiagnosticsFormatter;
use Weline\Server\Service\Runtime\RuntimeEndpointMetadata;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:doctor - read-only WLS runtime diagnostics.
 */
class Doctor extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $json = isset($args['json']);
        $instanceName = $this->parseInstanceName($args);
        $diagnostics = $this->buildDiagnostics($instanceName);

        if ($json) {
            echo \json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return;
        }

        $this->printer->setup('WLS Doctor');
        $this->printer->note('Instance: ' . $instanceName);
        $this->printer->note('Status: ' . (string)$diagnostics['status']);
        $strategy = \is_array($diagnostics['strategy'] ?? null) ? $diagnostics['strategy'] : [];
        foreach ((new RuntimeDiagnosticsFormatter())->formatStartupSummary(
            (new RuntimeCapabilityDetector())->detect(),
            $strategy
        ) as $line) {
            if (\str_starts_with($line, 'WARNING:') || \str_starts_with($line, 'Warning:')) {
                $this->printer->warning($line);
            } elseif (\str_starts_with($line, 'INFO:')) {
                $this->printer->note($line);
            } else {
                $this->printer->note($line);
            }
        }
        $protocols = \is_array($diagnostics['protocols'] ?? null) ? $diagnostics['protocols'] : [];
        $policy = \is_array($protocols['default_policy'] ?? null) ? $protocols['default_policy'] : [];
        $http3 = \is_array($protocols['http3_readiness'] ?? null) ? $protocols['http3_readiness'] : [];
        $this->printer->note('HTTP default: target=' . (string)($policy['target_preferred'] ?? 'http/3')
            . ', effective=' . (string)($policy['effective_preferred'] ?? 'unknown'));
        $this->printer->note('HTTP/3 readiness: ' . ((bool)($http3['ready'] ?? false) ? 'ready' : 'not ready')
            . ' (' . (string)($http3['summary'] ?? 'unknown') . ')');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDiagnostics(string $instanceName = 'default'): array
    {
        $profile = (new RuntimeCapabilityDetector())->detect();
        $config = $this->resolveConfigForInstance($instanceName);
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $manager->getRawInstanceData($instanceName);
        $endpointMetadata = \is_array($endpoint)
            ? RuntimeEndpointMetadata::fromEndpoint($endpoint)->toArray()
            : [];
        try {
            $strategy = (new RuntimeStrategyResolver())->resolve($config, [], $profile);
        } catch (\RuntimeException $exception) {
            $strategy = [
                'status' => 'unsafe',
                'runtime_strategy' => $config['runtime_strategy'] ?? 'auto',
                'warnings' => [$exception->getMessage()],
            ];
        }

        $selectionData = \is_array($endpointMetadata['runtime_selection'] ?? null)
            ? $endpointMetadata['runtime_selection']
            : [];
        $runningSchemaV3 = \is_array($endpoint)
            && (int)($endpoint['schema_version'] ?? 0) >= RuntimeSelection::ENDPOINT_SCHEMA_VERSION
            && \strtolower(\trim((string)($endpoint['lifecycle_state'] ?? ''))) === 'running';
        if ($runningSchemaV3 && ($endpointMetadata['runtime_selection_valid'] ?? null) !== true) {
            $strategy['status'] = 'unsafe';
            $strategy['warnings'] = \array_values(\array_unique(\array_merge(
                (array)($strategy['warnings'] ?? []),
                ['Running endpoint schema v3 is invalid: '
                    . (string)($endpointMetadata['runtime_selection_error'] ?? 'unknown validation error')]
            )));
        } elseif ($runningSchemaV3 && $selectionData !== []) {
            $selection = RuntimeSelection::fromArray($selectionData);
            if ($selection->osFamily === $profile->osFamily()) {
                $strategy = \array_replace($strategy, [
                    'worker_count' => \max(1, (int)($endpoint['count'] ?? $strategy['worker_count'] ?? 1)),
                    'worker_count_reason' => 'observed running endpoint schema v3',
                    'requested_topology' => $selection->requestedTopology->value,
                    'effective_topology' => $selection->effectiveTopology->value,
                    'topology' => $selection->effectiveTopology->value,
                    'topology_source' => $selection->source,
                    'dispatcher_enabled' => $selection->isDispatcher(),
                    'direct_reuse_port' => $selection->isDirect() && $selection->listenerMode === 'reuseport',
                    'direct_listener_mode' => $selection->listenerMode,
                    'listener_strategy' => $selection->listenerMode,
                    'topology_reason' => $selection->reason,
                    'topology_reason_codes' => $selection->reasonCodes,
                    'event_loop_driver' => $selection->eventLoopDriver,
                    'ssl_engine' => $selection->sslEngine,
                    'policy_compatible' => $selection->policyCompatible,
                    'runtime_selection' => $selection,
                ]);
            } else {
                $strategy['warnings'] = \array_values(\array_unique(\array_merge(
                    (array)($strategy['warnings'] ?? []),
                    ['Ignoring running endpoint runtime_selection from ' . $selection->osFamily
                        . ' while diagnosing current ' . $profile->osFamily() . ' runtime.']
                )));
                $endpointMetadata['runtime_selection_cross_runtime'] = true;
                $endpointMetadata['runtime_selection_ignored_reason'] = 'endpoint os_family=' . $selection->osFamily
                    . ', current os_family=' . $profile->osFamily();
            }
        }
        $diagnostics = (new RuntimeDiagnosticsFormatter())->toDiagnosticArray($profile, $strategy);
        $diagnostics['protocols'] = (new HttpProtocolCapabilityProbe())->snapshot();
        $diagnostics['instance'] = $instanceName;
        $diagnostics['config_source'] = $runningSchemaV3
            ? 'running endpoint schema v3'
            : ($config['source'] ?? 'runtime/default');
        if ($endpointMetadata !== []) {
            $diagnostics['runtime_observation'] = $endpointMetadata;
        }

        return $diagnostics;
    }

    private function parseInstanceName(array $args): string
    {
        if (isset($args['instance']) && (string)$args['instance'] !== '') {
            return (string)$args['instance'];
        }

        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $positional[] = (string)$arg;
            }
        }
        \array_shift($positional);

        return $positional[0] ?? 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigForInstance(string $instanceName): array
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $raw = $manager->getRawInstanceData($instanceName);
        $env = \Weline\Framework\App\Env::getInstance()->getConfig() ?: [];
        $wls = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
        $runtime = \is_array($wls['runtime'] ?? null) ? $wls['runtime'] : [];
        $loop = \is_array($wls['loop'] ?? null) ? $wls['loop'] : [];
        $supervisor = \is_array($wls['supervisor'] ?? null) ? $wls['supervisor'] : [];
        $serverConfig = \is_array($wls['servers'][$instanceName] ?? null) ? $wls['servers'][$instanceName] : [];
        $config = \array_merge([
            'worker_count' => 'auto',
            'mode' => 'io',
            'runtime_strategy' => $runtime['strategy'] ?? 'auto',
            'topology' => $runtime['topology'] ?? 'auto',
            'event_loop' => $loop['driver'] ?? 'auto',
            'supervisor' => ['enabled' => $supervisor['enabled'] ?? 'auto'],
            'source' => 'runtime/default',
        ], $wls, $serverConfig);

        if (\is_array($raw)) {
            $schemaVersion = (int)($raw['schema_version'] ?? 0);
            foreach (['count', 'worker_count', 'mode', 'runtime_strategy', 'event_loop'] as $key) {
                if (isset($raw[$key])) {
                    $config[$key === 'count' ? 'worker_count' : $key] = $raw[$key];
                }
            }
            if ($schemaVersion >= RuntimeSelection::ENDPOINT_SCHEMA_VERSION) {
                $selection = \is_array($raw['runtime_selection'] ?? null) ? $raw['runtime_selection'] : [];
                $requestedTopology = $raw['requested_topology']
                    ?? $selection['requested_topology']
                    ?? null;
                if (\is_scalar($requestedTopology) && \trim((string)$requestedTopology) !== '') {
                    $config['topology'] = \trim((string)$requestedTopology);
                }
            } elseif (isset($raw['topology'])) {
                $config['topology'] = $raw['topology'];
            }
            $config['source'] = 'instance record';
        }

        return $config;
    }

    public function tip(): string
    {
        return 'Read-only WLS runtime diagnostics and optimization advice';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:doctor [instance]',
            'Read-only WLS runtime diagnostics',
            [
                '[instance]' => 'Instance name, default: default',
                '--json' => 'Output machine-readable JSON',
            ],
            [],
            [
                'Show diagnostics' => 'php bin/w server:doctor',
                'Show JSON' => 'php bin/w server:doctor --json',
            ]
        );
    }
}
