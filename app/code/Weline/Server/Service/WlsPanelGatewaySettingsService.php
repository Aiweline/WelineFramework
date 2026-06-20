<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Model\ReverseProxy;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;

class WlsPanelGatewaySettingsService
{
    private const RUNTIME_ACTION_NONE = 'none';
    private const RUNTIME_ACTION_RELOAD = 'reload';
    private const RUNTIME_ACTION_RESTART = 'restart';
    private const TRAFFIC_MODE_AUTO = 'auto';
    private const TRAFFIC_MODE_DIRECT_LISTEN = 'direct_listen';
    private const TRAFFIC_MODE_PASSTHROUGH = 'passthrough';

    public function __construct(
        private readonly ServerInstanceManager $instanceManager,
        private readonly IpcControlGateway $ipcControlGateway
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettingsData(?string $requestedInstance = null): array
    {
        try {
            $instances = $this->collectInstances();
            $selection = $this->selectInstance($instances, $requestedInstance);

            return [
                'config' => $this->getGatewayModeConfig(),
                'instances' => \array_values($instances),
                'selected_instance' => $selection['instance'],
                'auto_selected' => $selection['auto_selected'],
                'ambiguous_gateway_targets' => $selection['ambiguous'],
                'active_route_count' => \count($this->buildActiveRoutes()),
                'gateway_instance_count' => $this->countInstances($instances, 'gateway_enabled'),
                'running_gateway_instance_count' => $this->countInstances($instances, 'gateway_running'),
                'error' => '',
            ];
        } catch (\Throwable $throwable) {
            return [
                'config' => $this->emptyGatewayModeConfig(),
                'instances' => [],
                'selected_instance' => '',
                'auto_selected' => false,
                'ambiguous_gateway_targets' => false,
                'active_route_count' => 0,
                'gateway_instance_count' => 0,
                'running_gateway_instance_count' => 0,
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveConfiguration(array $input): array
    {
        try {
            $enabled = $this->truthy($input['gateway_enabled'] ?? false);
            $listen = $this->normalizeListenAddress((string)($input['gateway_listen'] ?? ''));
            $previousConfig = $this->getGatewayModeConfig();
            $previousTrafficMode = (string)($previousConfig['traffic_mode'] ?? self::TRAFFIC_MODE_AUTO);
            $trafficMode = $this->normalizeTrafficMode((string)($input['gateway_traffic_mode'] ?? self::TRAFFIC_MODE_AUTO));

            $env = Env::getInstance();
            $savedEnabled = $env->setConfig('wls.gateway.enabled', $enabled);
            $savedListen = $env->setConfig('wls.gateway.listen', $listen);
            $savedTrafficMode = $env->setConfig('wls.gateway.traffic_mode', $trafficMode);
            if (!$savedEnabled || !$savedListen || !$savedTrafficMode) {
                return [
                    'success' => false,
                    'message' => (string)__('Gateway mode configuration could not be saved.'),
                    'selected_instance' => (string)($input['gateway_instance'] ?? ''),
                ];
            }

            $runtimeAction = $this->normalizeRuntimeAction((string)($input['runtime_action'] ?? self::RUNTIME_ACTION_RELOAD));
            $applyResult = ['success' => false, 'message' => '', 'selected_instance' => ''];
            if ($enabled && $this->truthy($input['apply_routes'] ?? true)) {
                $applyResult = $this->applyRoutes($input);
            }
            $selectedInstance = \trim((string)($applyResult['selected_instance'] ?? ''));
            if ($selectedInstance === '') {
                $selectedInstance = $this->resolveSelectedRuntimeInstance($input);
            }

            $runtimeActionBlocked = false;
            $runtimeResult = $this->buildRuntimeActionBlockedResult($runtimeAction, $trafficMode);
            if ($runtimeResult !== null) {
                $runtimeActionBlocked = true;
            } else {
                $runtimeResult = $this->applyRuntimeAction($runtimeAction, $selectedInstance, $trafficMode);
            }

            $restartSucceeded = $runtimeAction === self::RUNTIME_ACTION_RESTART
                && (bool)($runtimeResult['success'] ?? false);
            $modeRestartRequired = $trafficMode !== self::TRAFFIC_MODE_AUTO && !$restartSucceeded;
            if ($modeRestartRequired && !$runtimeActionBlocked) {
                $runtimeResult = $this->appendRuntimeWarning(
                    $runtimeResult,
                    (string)__('Gateway traffic mode changes require a target WLS restart before the listener topology changes.')
                );
            }

            return [
                'success' => true,
                'message' => (string)__('Gateway mode configuration saved.'),
                'selected_instance' => $selectedInstance,
                'listen' => $listen,
                'enabled' => $enabled,
                'traffic_mode' => $trafficMode,
                'traffic_mode_changed' => $previousTrafficMode !== $trafficMode,
                'traffic_mode_restart_required' => $modeRestartRequired,
                'runtime_action' => $runtimeAction,
                'runtime_action_blocked' => $runtimeActionBlocked,
                'runtime_action_success' => (bool)($runtimeResult['success'] ?? false),
                'runtime_action_message' => (string)($runtimeResult['message'] ?? ''),
                'restart_required' => !$restartSucceeded,
                'gateway_applied' => (bool)($applyResult['success'] ?? false),
                'gateway_apply_message' => (string)($applyResult['message'] ?? ''),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'selected_instance' => (string)($input['gateway_instance'] ?? ''),
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function applyRoutes(array $input): array
    {
        $requestedInstance = \trim((string)($input['gateway_instance'] ?? $input['wls_instance'] ?? $input['instance'] ?? ''));
        $settings = $this->getSettingsData($requestedInstance);
        $selectedInstance = \trim((string)($settings['selected_instance'] ?? ''));

        if ($requestedInstance === '' && !empty($settings['ambiguous_gateway_targets'])) {
            return [
                'success' => false,
                'message' => (string)__('Select a Gateway instance before applying routes.'),
                'data' => [
                    'routes' => (int)($settings['active_route_count'] ?? 0),
                    'gateways' => (int)($settings['running_gateway_instance_count'] ?? 0),
                ],
            ];
        }

        if ($selectedInstance === '') {
            $selectedInstance = 'default';
        }

        $routes = $this->buildActiveRoutes();
        $result = $this->ipcControlGateway->proxyApply($selectedInstance, $routes);
        $result['selected_instance'] = $selectedInstance;
        $result['route_count'] = \count($routes);

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveSelectedRuntimeInstance(array $input): string
    {
        $requestedInstance = \trim((string)($input['gateway_instance'] ?? $input['wls_instance'] ?? $input['instance'] ?? ''));
        $settings = $this->getSettingsData($requestedInstance);
        $selected = \trim((string)($settings['selected_instance'] ?? ''));
        if ($selected !== '') {
            return $selected;
        }

        return $requestedInstance;
    }

    /**
     * @return array{success:bool,message:string,data?:array}
     */
    private function applyRuntimeAction(string $runtimeAction, string $selectedInstance, string $trafficMode): array
    {
        if ($runtimeAction === self::RUNTIME_ACTION_NONE) {
            return [
                'success' => true,
                'message' => (string)__('Runtime action skipped.'),
            ];
        }

        if ($selectedInstance === '') {
            return [
                'success' => false,
                'message' => (string)__('Select a target WLS instance before applying runtime changes.'),
            ];
        }

        if ($runtimeAction === self::RUNTIME_ACTION_RESTART) {
            return $this->restartInstance($selectedInstance, $trafficMode);
        }

        $result = $this->ipcControlGateway->reloadAsync($selectedInstance, ControlMessage::RELOAD_TYPE_FORCE, 8.0);

        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('WLS runtime reload requested.')),
            'data' => \is_array($result['data'] ?? null) ? $result['data'] : [],
        ];
    }

    /**
     * @return array{success:bool,message:string,data?:array}
     */
    private function restartInstance(string $selectedInstance, string $trafficMode): array
    {
        $info = $this->instanceManager->getPersistedInstanceInfo($selectedInstance);
        if (!$info instanceof ServerInstanceInfo) {
            return [
                'success' => false,
                'message' => (string)__('Selected WLS instance %{1} was not found.', [$selectedInstance]),
                'data' => [],
            ];
        }

        $raw = $this->instanceManager->getRawInstanceData($selectedInstance) ?? [];
        $command = $this->buildRestartCommand($selectedInstance, $trafficMode, $info, $raw);
        $pid = Processer::create($command, false);
        if ($pid <= 0) {
            return [
                'success' => false,
                'message' => (string)__('WLS restart command was submitted but no valid PID was returned.'),
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('WLS restart command submitted for %{1}. Master PID: %{2}', [$selectedInstance, (string)$pid]),
            'data' => [
                'pid' => $pid,
                'port' => $info->port,
                'workers' => $info->workerCount,
                'ssl_enabled' => $info->sslEnabled,
                'topology' => $this->restartTopology($trafficMode, $info, $raw),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function buildRestartCommand(
        string $selectedInstance,
        string $trafficMode,
        ServerInstanceInfo $info,
        array $raw
    ): string {
        $parts = [
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg(BP . 'bin/w'),
            'server:start',
            \escapeshellarg($selectedInstance),
            '-r',
            '-f',
        ];

        if ($info->port > 0) {
            $this->appendCommandOption($parts, '-p', (string)$info->port);
        }
        if ($info->workerCount > 0) {
            $this->appendCommandOption($parts, '-c', (string)$info->workerCount);
        }

        $topology = $this->restartTopology($trafficMode, $info, $raw);
        if ($topology !== '') {
            $this->appendCommandOption($parts, '--topology', $topology);
        }

        if (!$info->sslEnabled) {
            $parts[] = '--no-ssl';
        } else {
            $this->appendCommandOption($parts, '--ssl-cert', (string)($raw['ssl_cert'] ?? ''));
            $this->appendCommandOption($parts, '--ssl-key', (string)($raw['ssl_key'] ?? ''));
            $this->appendCommandOption($parts, '--ssl-domain', (string)($raw['ssl_domain'] ?? $raw['public_host'] ?? ''));
        }

        $this->appendCommandOption($parts, '--worker-memory-limit', (string)($raw['worker_memory_limit'] ?? ''));
        $this->appendCommandOption($parts, '--dispatcher-memory-limit', (string)($raw['dispatcher_memory_limit'] ?? ''));

        return \implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function restartTopology(string $trafficMode, ServerInstanceInfo $info, array $raw): string
    {
        $topology = $this->trafficModeTopology($trafficMode);
        if ($topology !== '') {
            return $topology;
        }

        $rawTopology = \strtolower(\trim((string)($raw['topology'] ?? '')));
        if (\in_array($rawTopology, ['direct', 'dispatcher', 'independent'], true)) {
            return $rawTopology;
        }
        if (!empty($raw['direct_reuse_port'])) {
            return 'direct';
        }

        return $info->dispatcherEnabled ? 'dispatcher' : 'independent';
    }

    /**
     * @param array<int, string> $parts
     */
    private function appendCommandOption(array &$parts, string $name, string $value): void
    {
        $value = \trim($value);
        if ($value === '') {
            return;
        }

        $parts[] = $name;
        $parts[] = \escapeshellarg($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function getGatewayModeConfig(): array
    {
        $env = Env::getInstance();
        $savedConfig = $env->getConfig('wls.gateway', []);
        $savedConfig = \is_array($savedConfig) ? $savedConfig : [];
        $savedEnabled = $this->truthy($savedConfig['enabled'] ?? false);
        $savedListen = \trim((string)($savedConfig['listen'] ?? '0.0.0.0:443'));
        $savedTrafficMode = $this->normalizeTrafficMode((string)($savedConfig['traffic_mode'] ?? self::TRAFFIC_MODE_AUTO));

        $envEnabled = \getenv('WLS_GATEWAY_ENABLED');
        $envListen = \getenv('WLS_GATEWAY_LISTEN');
        $envTrafficMode = \getenv('WLS_GATEWAY_TRAFFIC_MODE');
        $hasEnabledOverride = $envEnabled !== false && \trim((string)$envEnabled) !== '';
        $hasListenOverride = $envListen !== false && \trim((string)$envListen) !== '';
        $hasTrafficModeOverride = $envTrafficMode !== false && \trim((string)$envTrafficMode) !== '';

        $effectiveEnabled = $hasEnabledOverride
            ? $this->truthy((string)$envEnabled)
            : $savedEnabled;
        $effectiveListen = $hasListenOverride
            ? \trim((string)$envListen)
            : $savedListen;
        $effectiveTrafficMode = $hasTrafficModeOverride
            ? $this->normalizeTrafficMode((string)$envTrafficMode)
            : $savedTrafficMode;
        $directListenCapability = $this->directListenCapability();

        return [
            'enabled' => $savedEnabled,
            'listen' => $savedListen !== '' ? $savedListen : '0.0.0.0:443',
            'traffic_mode' => $savedTrafficMode,
            'traffic_mode_label' => $this->trafficModeLabel($savedTrafficMode),
            'effective_enabled' => $effectiveEnabled,
            'effective_listen' => $effectiveListen !== '' ? $effectiveListen : '0.0.0.0:443',
            'effective_traffic_mode' => $effectiveTrafficMode,
            'effective_traffic_mode_label' => $this->trafficModeLabel($effectiveTrafficMode),
            'effective_traffic_mode_hint' => $this->trafficModeHint($effectiveTrafficMode),
            'direct_listen_capability' => $directListenCapability,
            'traffic_mode_options' => $this->trafficModeOptions(),
            'env_override' => $hasEnabledOverride || $hasListenOverride || $hasTrafficModeOverride,
            'env_enabled' => $hasEnabledOverride ? (string)$envEnabled : '',
            'env_listen' => $hasListenOverride ? (string)$envListen : '',
            'env_traffic_mode' => $hasTrafficModeOverride ? (string)$envTrafficMode : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGatewayModeConfig(): array
    {
        return [
            'enabled' => false,
            'listen' => '0.0.0.0:443',
            'traffic_mode' => self::TRAFFIC_MODE_AUTO,
            'traffic_mode_label' => $this->trafficModeLabel(self::TRAFFIC_MODE_AUTO),
            'effective_enabled' => false,
            'effective_listen' => '0.0.0.0:443',
            'effective_traffic_mode' => self::TRAFFIC_MODE_AUTO,
            'effective_traffic_mode_label' => $this->trafficModeLabel(self::TRAFFIC_MODE_AUTO),
            'effective_traffic_mode_hint' => $this->trafficModeHint(self::TRAFFIC_MODE_AUTO),
            'direct_listen_capability' => $this->directListenCapability(),
            'traffic_mode_options' => $this->trafficModeOptions(),
            'env_override' => false,
            'env_enabled' => '',
            'env_listen' => '',
            'env_traffic_mode' => '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectInstances(): array
    {
        $instances = [];
        foreach ($this->instanceManager->getAllPersistedInstanceInfo() as $name => $info) {
            if (!$info instanceof ServerInstanceInfo) {
                continue;
            }

            $instanceName = (string)($info->name !== '' ? $info->name : $name);
            $raw = $this->instanceManager->getRawInstanceData($instanceName) ?? [];
            $gatewayConfig = \is_array($raw['gateway'] ?? null) ? $raw['gateway'] : [];
            $runtimeConfig = \is_array($raw['runtime'] ?? null) ? $raw['runtime'] : [];
            $statusResult = $this->instanceManager->getMasterIpcStatusResult($instanceName, 0.7);
            $statusData = !empty($statusResult['success']) && \is_array($statusResult['data'] ?? null)
                ? $statusResult['data']
                : [];
            $services = \is_array($statusData['services'] ?? null) ? $statusData['services'] : [];
            $gatewayRows = \is_array($services['gateway']['instances'] ?? null)
                ? \array_values($services['gateway']['instances'])
                : [];
            $gatewayReadyCount = $this->countReadyGatewayRows($gatewayRows);
            $listen = $this->resolveListenAddress($gatewayConfig, $gatewayRows);
            $gatewayEnabled = $this->truthy($gatewayConfig['enabled'] ?? false) || $gatewayReadyCount > 0;

            $instances[] = [
                'name' => $instanceName,
                'listen' => $listen,
                'main_listen' => $info->getListenAddress(),
                'topology' => (string)($raw['topology'] ?? ($runtimeConfig['topology'] ?? '')),
                'direct_reuse_port' => !empty($raw['direct_reuse_port']),
                'control_port' => $info->controlPort,
                'gateway_enabled' => $gatewayEnabled,
                'gateway_running' => $gatewayReadyCount > 0,
                'gateway_ready_count' => $gatewayReadyCount,
                'gateway_process_count' => \count($gatewayRows),
                'instance_running' => (bool)($statusData['running'] ?? false),
                'status_message' => (string)($statusResult['message'] ?? ''),
            ];
        }

        return $instances;
    }

    /**
     * @param array<int, array<string, mixed>> $instances
     * @return array{instance:string,auto_selected:bool,ambiguous:bool}
     */
    private function selectInstance(array $instances, ?string $requestedInstance = null): array
    {
        $requestedInstance = \trim((string)$requestedInstance);
        if ($requestedInstance !== '') {
            foreach ($instances as $instance) {
                if ((string)($instance['name'] ?? '') === $requestedInstance) {
                    return [
                        'instance' => $requestedInstance,
                        'auto_selected' => false,
                        'ambiguous' => false,
                    ];
                }
            }
        }

        $runningGatewayInstances = \array_values(\array_filter(
            $instances,
            static fn(array $instance): bool => !empty($instance['gateway_enabled']) && !empty($instance['gateway_running'])
        ));
        if (\count($runningGatewayInstances) === 1) {
            return [
                'instance' => (string)$runningGatewayInstances[0]['name'],
                'auto_selected' => true,
                'ambiguous' => false,
            ];
        }
        if (\count($runningGatewayInstances) > 1) {
            return [
                'instance' => '',
                'auto_selected' => false,
                'ambiguous' => true,
            ];
        }

        $enabledGatewayInstances = \array_values(\array_filter(
            $instances,
            static fn(array $instance): bool => !empty($instance['gateway_enabled'])
        ));
        if (\count($enabledGatewayInstances) === 1) {
            return [
                'instance' => (string)$enabledGatewayInstances[0]['name'],
                'auto_selected' => true,
                'ambiguous' => false,
            ];
        }

        if (\count($instances) === 1) {
            return [
                'instance' => (string)$instances[0]['name'],
                'auto_selected' => true,
                'ambiguous' => false,
            ];
        }

        return [
            'instance' => '',
            'auto_selected' => false,
            'ambiguous' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildActiveRoutes(): array
    {
        $routesData = [];
        foreach ($this->freshProxy()->getActiveRules() as $route) {
            $routesData[] = [
                'domain' => (string)($route[ReverseProxy::schema_fields_DOMAIN] ?? ''),
                'backend_host' => (string)($route[ReverseProxy::schema_fields_BACKEND_HOST] ?? ''),
                'backend_port' => (int)($route[ReverseProxy::schema_fields_BACKEND_PORT] ?? 0),
                'backend_ssl' => (bool)($route[ReverseProxy::schema_fields_BACKEND_SSL] ?? false),
                'priority' => (int)($route[ReverseProxy::schema_fields_PRIORITY] ?? 0),
            ];
        }

        return $routesData;
    }

    /**
     * @param array<string, mixed> $gatewayConfig
     * @param array<int, array<string, mixed>> $gatewayRows
     */
    private function resolveListenAddress(array $gatewayConfig, array $gatewayRows): string
    {
        $listen = \trim((string)($gatewayConfig['listen'] ?? ''));
        if ($listen !== '') {
            return $listen;
        }

        foreach ($gatewayRows as $gatewayRow) {
            $port = (int)($gatewayRow['port'] ?? 0);
            if ($port > 0) {
                return '*:' . $port;
            }
        }

        return !empty($gatewayConfig['enabled']) ? '0.0.0.0:443' : '';
    }

    /**
     * @param array<int, array<string, mixed>> $gatewayRows
     */
    private function countReadyGatewayRows(array $gatewayRows): int
    {
        $count = 0;
        foreach ($gatewayRows as $gatewayRow) {
            $state = \strtolower(\trim((string)($gatewayRow['state'] ?? '')));
            if (\in_array($state, ['ready', 'running'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $instances
     */
    private function countInstances(array $instances, string $flag): int
    {
        $count = 0;
        foreach ($instances as $instance) {
            if (!empty($instance[$flag])) {
                $count++;
            }
        }

        return $count;
    }

    private function truthy(mixed $value): bool
    {
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return \in_array($value, [1, true], true);
    }

    private function normalizeRuntimeAction(string $runtimeAction): string
    {
        $runtimeAction = \strtolower(\trim($runtimeAction));
        return \in_array($runtimeAction, [self::RUNTIME_ACTION_NONE, self::RUNTIME_ACTION_RELOAD, self::RUNTIME_ACTION_RESTART], true)
            ? $runtimeAction
            : self::RUNTIME_ACTION_RELOAD;
    }

    private function normalizeTrafficMode(string $trafficMode): string
    {
        $trafficMode = \strtolower(\trim(\str_replace('-', '_', $trafficMode)));
        return \in_array($trafficMode, [self::TRAFFIC_MODE_AUTO, self::TRAFFIC_MODE_DIRECT_LISTEN, self::TRAFFIC_MODE_PASSTHROUGH], true)
            ? $trafficMode
            : self::TRAFFIC_MODE_AUTO;
    }

    private function trafficModeTopology(string $trafficMode): string
    {
        return match ($this->normalizeTrafficMode($trafficMode)) {
            self::TRAFFIC_MODE_DIRECT_LISTEN => 'direct',
            self::TRAFFIC_MODE_PASSTHROUGH => 'dispatcher',
            default => '',
        };
    }

    private function trafficModeLabel(string $trafficMode): string
    {
        return match ($this->normalizeTrafficMode($trafficMode)) {
            self::TRAFFIC_MODE_DIRECT_LISTEN => (string)__('Direct listen'),
            self::TRAFFIC_MODE_PASSTHROUGH => (string)__('Passthrough'),
            default => (string)__('Auto'),
        };
    }

    private function trafficModeHint(string $trafficMode): string
    {
        return match ($this->normalizeTrafficMode($trafficMode)) {
            self::TRAFFIC_MODE_DIRECT_LISTEN => (string)__('Prefer SO_REUSEPORT direct worker listening when the runtime supports it. Falls back only after an explicit restart is corrected.'),
            self::TRAFFIC_MODE_PASSTHROUGH => (string)__('Force Dispatcher/Gateway passthrough so one listener accepts traffic and routes to workers.'),
            default => (string)__('Let WLS choose the stable topology for the current operating system and runtime profile.'),
        };
    }

    /**
     * @return array<int, array{value:string,label:string,description:string}>
     */
    private function trafficModeOptions(): array
    {
        return [
            [
                'value' => self::TRAFFIC_MODE_AUTO,
                'label' => $this->trafficModeLabel(self::TRAFFIC_MODE_AUTO),
                'description' => $this->trafficModeHint(self::TRAFFIC_MODE_AUTO),
            ],
            [
                'value' => self::TRAFFIC_MODE_DIRECT_LISTEN,
                'label' => $this->trafficModeLabel(self::TRAFFIC_MODE_DIRECT_LISTEN),
                'description' => $this->trafficModeHint(self::TRAFFIC_MODE_DIRECT_LISTEN),
            ],
            [
                'value' => self::TRAFFIC_MODE_PASSTHROUGH,
                'label' => $this->trafficModeLabel(self::TRAFFIC_MODE_PASSTHROUGH),
                'description' => $this->trafficModeHint(self::TRAFFIC_MODE_PASSTHROUGH),
            ],
        ];
    }

    /**
     * @return array{success:bool,message:string,data?:array}|null
     */
    private function buildRuntimeActionBlockedResult(string $runtimeAction, string $trafficMode): ?array
    {
        if ($runtimeAction !== self::RUNTIME_ACTION_RESTART
            || $trafficMode !== self::TRAFFIC_MODE_DIRECT_LISTEN) {
            return null;
        }

        $capability = $this->directListenCapability();
        if (!empty($capability['supported'])) {
            return null;
        }

        return [
            'success' => false,
            'message' => (string)__(
                'Direct listen restart was not submitted because this runtime cannot use SO_REUSEPORT. Configuration was saved; keep Auto or Passthrough on this host, or restart on a supported Linux/macOS runtime.'
            ),
            'data' => [
                'reason' => 'direct_listen_unsupported',
                'os_family' => (string)($capability['os_family'] ?? PHP_OS_FAMILY),
                'reuse_port_constant' => (bool)($capability['reuse_port_constant'] ?? false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function directListenCapability(): array
    {
        try {
            $profile = ObjectManager::getInstance(RuntimeCapabilityDetector::class)->detect();
            $supported = $profile->supportsReusePort();
            $osFamily = $profile->osFamily();
            $reusePortConstant = (bool)$profile->get('reuse_port_constant', false);

            return [
                'supported' => $supported,
                'os_family' => $osFamily,
                'reuse_port_constant' => $reusePortConstant,
                'label' => $supported
                    ? (string)__('Direct listen supported')
                    : (string)__('Direct listen unavailable'),
                'message' => $supported
                    ? (string)__('This runtime can use SO_REUSEPORT direct listen after a WLS restart with direct topology.')
                    : (string)__('This runtime cannot use SO_REUSEPORT direct listen; keep Auto or Passthrough here and validate direct mode on a supported Linux or macOS runtime.'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'supported' => false,
                'os_family' => PHP_OS_FAMILY,
                'reuse_port_constant' => false,
                'label' => (string)__('Direct listen capability unknown'),
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param array{success:bool,message:string,data?:array} $runtimeResult
     * @return array{success:bool,message:string,data?:array}
     */
    private function appendRuntimeWarning(array $runtimeResult, string $warning): array
    {
        $message = \trim((string)($runtimeResult['message'] ?? ''));
        $runtimeResult['success'] = false;
        $runtimeResult['message'] = \trim($message . ' ' . $warning);

        return $runtimeResult;
    }

    private function normalizeListenAddress(string $listen): string
    {
        $listen = \trim($listen);
        if ($listen === '') {
            return '0.0.0.0:443';
        }
        if (\str_starts_with($listen, ':')) {
            $listen = '0.0.0.0' . $listen;
        }

        $parts = \explode(':', $listen);
        if (\count($parts) !== 2) {
            throw new \InvalidArgumentException((string)__('Gateway listen address must use host:port format.'));
        }

        $host = \trim($parts[0]);
        $port = (int)\trim($parts[1]);
        if ($host === '' || $host === '*') {
            $host = '0.0.0.0';
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException((string)__('Gateway listen port must be between 1 and 65535.'));
        }
        if (!\preg_match('/^[a-zA-Z0-9._*-]+$/', $host)) {
            throw new \InvalidArgumentException((string)__('Gateway listen host is invalid.'));
        }

        return $host . ':' . $port;
    }

    private function freshProxy(): ReverseProxy
    {
        /** @var ReverseProxy $proxy */
        $proxy = ObjectManager::getInstance(ReverseProxy::class, [], false);
        return $proxy;
    }
}
