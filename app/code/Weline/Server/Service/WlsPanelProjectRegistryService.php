<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\ReverseProxy;
use Weline\Server\Model\WlsPanelProject;
use Weline\Server\Service\Control\IpcControlGateway;

class WlsPanelProjectRegistryService
{
    public function __construct(
        private readonly ServerInstanceManager $instanceManager
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProjects(): array
    {
        return $this->freshProject()->getAllProjects();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormData(int $projectId = 0): array
    {
        if ($projectId > 0) {
            $project = $this->loadProject($projectId);
            if ($project->getData(WlsPanelProject::schema_fields_ID)) {
                return $project->getData();
            }
        }

        return [
            WlsPanelProject::schema_fields_ID => 0,
            WlsPanelProject::schema_fields_NAME => '',
            WlsPanelProject::schema_fields_DOMAIN => '',
            WlsPanelProject::schema_fields_ADMIN_URL => '',
            WlsPanelProject::schema_fields_PANEL_URL => '',
            WlsPanelProject::schema_fields_PROJECT_PATH => '',
            WlsPanelProject::schema_fields_PHP_PROFILE => '',
            WlsPanelProject::schema_fields_DATABASE_PROFILE => '',
            WlsPanelProject::schema_fields_GATEWAY_ENABLED => 1,
            WlsPanelProject::schema_fields_BACKEND_HOST => '127.0.0.1',
            WlsPanelProject::schema_fields_BACKEND_PORT => '',
            WlsPanelProject::schema_fields_BACKEND_SSL => 0,
            WlsPanelProject::schema_fields_STATUS => WlsPanelProject::STATUS_ACTIVE,
            WlsPanelProject::schema_fields_DESCRIPTION => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,project_id:int,gateway_synced:bool,gateway_message:string,gateway_applied:bool,gateway_apply_message:string}
     */
    public function saveFromPanel(array $input): array
    {
        try {
            $projectId = (int)($input[WlsPanelProject::schema_fields_ID] ?? $input['project_id'] ?? 0);
            $project = $projectId > 0 ? $this->loadProject($projectId) : $this->freshProject();
            if ($projectId > 0 && !$project->getData(WlsPanelProject::schema_fields_ID)) {
                throw new \InvalidArgumentException((string)__('Managed project does not exist.'));
            }

            $project->setData(WlsPanelProject::schema_fields_NAME, $this->stringValue($input, WlsPanelProject::schema_fields_NAME));
            $project->setData(WlsPanelProject::schema_fields_DOMAIN, $this->stringValue($input, WlsPanelProject::schema_fields_DOMAIN));
            $project->setData(WlsPanelProject::schema_fields_ADMIN_URL, $this->stringValue($input, WlsPanelProject::schema_fields_ADMIN_URL));
            $project->setData(WlsPanelProject::schema_fields_PANEL_URL, $this->stringValue($input, WlsPanelProject::schema_fields_PANEL_URL));
            $project->setData(WlsPanelProject::schema_fields_PROJECT_PATH, $this->stringValue($input, WlsPanelProject::schema_fields_PROJECT_PATH));
            $project->setData(WlsPanelProject::schema_fields_PHP_PROFILE, $this->stringValue($input, WlsPanelProject::schema_fields_PHP_PROFILE));
            $project->setData(WlsPanelProject::schema_fields_DATABASE_PROFILE, $this->stringValue($input, WlsPanelProject::schema_fields_DATABASE_PROFILE));
            $project->setData(WlsPanelProject::schema_fields_GATEWAY_ENABLED, $this->truthy($input[WlsPanelProject::schema_fields_GATEWAY_ENABLED] ?? 0) ? 1 : 0);
            $project->setData(WlsPanelProject::schema_fields_BACKEND_HOST, $this->stringValue($input, WlsPanelProject::schema_fields_BACKEND_HOST));
            $project->setData(WlsPanelProject::schema_fields_BACKEND_PORT, (int)($input[WlsPanelProject::schema_fields_BACKEND_PORT] ?? 0));
            $project->setData(WlsPanelProject::schema_fields_BACKEND_SSL, $this->truthy($input[WlsPanelProject::schema_fields_BACKEND_SSL] ?? 0) ? 1 : 0);
            $project->setData(WlsPanelProject::schema_fields_STATUS, $this->normalizeStatus($this->stringValue($input, WlsPanelProject::schema_fields_STATUS)));
            $project->setData(WlsPanelProject::schema_fields_DESCRIPTION, $this->stringValue($input, WlsPanelProject::schema_fields_DESCRIPTION));
            $project->save();

            $gatewayResult = $this->syncGatewayRule($project);
            $applyResult = $this->applyGatewayConfig($input);

            return [
                'success' => true,
                'message' => (string)__('Managed project saved.'),
                'project_id' => (int)$project->getData(WlsPanelProject::schema_fields_ID),
                'gateway_synced' => (bool)$gatewayResult['success'],
                'gateway_message' => (string)$gatewayResult['message'],
                'gateway_applied' => (bool)($applyResult['success'] ?? false),
                'gateway_apply_message' => (string)($applyResult['message'] ?? ''),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'project_id' => (int)($input[WlsPanelProject::schema_fields_ID] ?? $input['project_id'] ?? 0),
                'gateway_synced' => false,
                'gateway_message' => '',
                'gateway_applied' => false,
                'gateway_apply_message' => '',
            ];
        }
    }

    /**
     * @return array{success:bool,message:string,gateway_applied?:bool,gateway_apply_message?:string}
     */
    public function deleteFromPanel(int $projectId, array $input = []): array
    {
        if ($projectId <= 0) {
            return ['success' => false, 'message' => (string)__('Managed project ID is invalid.')];
        }

        try {
            $project = $this->loadProject($projectId);
            if (!$project->getData(WlsPanelProject::schema_fields_ID)) {
                return ['success' => false, 'message' => (string)__('Managed project does not exist.')];
            }

            $this->deleteLinkedProxy($project);
            $project->delete();
            $applyResult = $this->applyGatewayConfig($input);

            return [
                'success' => true,
                'message' => (string)__('Managed project removed.'),
                'gateway_applied' => (bool)($applyResult['success'] ?? false),
                'gateway_apply_message' => (string)($applyResult['message'] ?? ''),
            ];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function projectToCard(array $project): array
    {
        $status = (string)($project[WlsPanelProject::schema_fields_STATUS] ?? WlsPanelProject::STATUS_ACTIVE);
        $phpProfile = \trim((string)($project[WlsPanelProject::schema_fields_PHP_PROFILE] ?? ''));
        $databaseProfile = \trim((string)($project[WlsPanelProject::schema_fields_DATABASE_PROFILE] ?? ''));
        $backend = $this->buildBackendTarget($project);

        return [
            'type' => 'registered',
            'id' => (int)($project[WlsPanelProject::schema_fields_ID] ?? 0),
            'name' => (string)($project[WlsPanelProject::schema_fields_NAME] ?? ''),
            'domain' => (string)($project[WlsPanelProject::schema_fields_DOMAIN] ?? ''),
            'status' => $status === WlsPanelProject::STATUS_ACTIVE ? (string)__('Active') : (string)__('Inactive'),
            'path_label' => (string)__('Project Path'),
            'path' => (string)($project[WlsPanelProject::schema_fields_PROJECT_PATH] ?? ''),
            'backend' => $backend,
            'admin' => (string)($project[WlsPanelProject::schema_fields_ADMIN_URL] ?? ''),
            'panel' => (string)($project[WlsPanelProject::schema_fields_PANEL_URL] ?? ''),
            'php' => '',
            'php_label' => $phpProfile !== '' ? $phpProfile : (string)__('Runtime profile editable'),
            'db' => '',
            'db_label' => $databaseProfile !== '' ? $databaseProfile : (string)__('Click to configure profile'),
            'gateway_enabled' => (int)($project[WlsPanelProject::schema_fields_GATEWAY_ENABLED] ?? 0),
            'gateway_proxy_id' => (int)($project[WlsPanelProject::schema_fields_GATEWAY_PROXY_ID] ?? 0),
        ];
    }

    public function loadProject(int $projectId): WlsPanelProject
    {
        return $this->freshProject()
            ->clearQuery()
            ->where(WlsPanelProject::schema_fields_ID, $projectId)
            ->find()
            ->fetch();
    }

    private function syncGatewayRule(WlsPanelProject $project): array
    {
        $gatewayEnabled = (int)$project->getData(WlsPanelProject::schema_fields_GATEWAY_ENABLED) === 1;
        $proxyId = (int)$project->getData(WlsPanelProject::schema_fields_GATEWAY_PROXY_ID);

        if (!$gatewayEnabled) {
            if ($proxyId > 0) {
                $proxy = $this->loadProxy($proxyId);
                if ($proxy->getData(ReverseProxy::schema_fields_ID)) {
                    $proxy->setData(ReverseProxy::schema_fields_STATUS, ReverseProxy::STATUS_INACTIVE);
                    $proxy->save();
                }
            }
            return ['success' => true, 'message' => (string)__('Gateway rule is disabled for this project.')];
        }

        $domain = (string)$project->getData(WlsPanelProject::schema_fields_DOMAIN);
        $proxy = $proxyId > 0 ? $this->loadProxy($proxyId) : $this->freshProxy()->loadByDomain($domain);
        if (!$proxy->getData(ReverseProxy::schema_fields_ID)) {
            $proxy = $this->freshProxy();
        }

        $proxy->setData(ReverseProxy::schema_fields_DOMAIN, $domain);
        $proxy->setData(ReverseProxy::schema_fields_BACKEND_HOST, (string)$project->getData(WlsPanelProject::schema_fields_BACKEND_HOST));
        $proxy->setData(ReverseProxy::schema_fields_BACKEND_PORT, (int)$project->getData(WlsPanelProject::schema_fields_BACKEND_PORT));
        $proxy->setData(ReverseProxy::schema_fields_BACKEND_SSL, (int)$project->getData(WlsPanelProject::schema_fields_BACKEND_SSL));
        $proxy->setData(ReverseProxy::schema_fields_PRIORITY, 100);
        $proxy->setData(
            ReverseProxy::schema_fields_STATUS,
            (string)$project->getData(WlsPanelProject::schema_fields_STATUS) === WlsPanelProject::STATUS_ACTIVE
                ? ReverseProxy::STATUS_ACTIVE
                : ReverseProxy::STATUS_INACTIVE
        );
        $proxy->setData(
            ReverseProxy::schema_fields_DESCRIPTION,
            'WLS Panel: ' . (string)$project->getData(WlsPanelProject::schema_fields_NAME)
        );
        $proxy->save();

        $savedProxyId = (int)$proxy->getData(ReverseProxy::schema_fields_ID);
        if ($savedProxyId > 0 && $savedProxyId !== $proxyId) {
            $project->setData(WlsPanelProject::schema_fields_GATEWAY_PROXY_ID, $savedProxyId);
            $project->save();
        }

        return ['success' => true, 'message' => (string)__('Gateway rule saved.')];
    }

    private function deleteLinkedProxy(WlsPanelProject $project): void
    {
        $proxyId = (int)$project->getData(WlsPanelProject::schema_fields_GATEWAY_PROXY_ID);
        if ($proxyId <= 0) {
            return;
        }

        $proxy = $this->loadProxy($proxyId);
        if (!$proxy->getData(ReverseProxy::schema_fields_ID)) {
            return;
        }

        $projectDomain = (string)$project->getData(WlsPanelProject::schema_fields_DOMAIN);
        $proxyDomain = (string)$proxy->getData(ReverseProxy::schema_fields_DOMAIN);
        if ($projectDomain !== '' && $projectDomain === $proxyDomain) {
            $proxy->delete();
        }
    }

    /**
     * @param array<string, mixed> $project
     */
    private function buildBackendTarget(array $project): string
    {
        $host = \trim((string)($project[WlsPanelProject::schema_fields_BACKEND_HOST] ?? ''));
        $port = (int)($project[WlsPanelProject::schema_fields_BACKEND_PORT] ?? 0);
        if ((int)($project[WlsPanelProject::schema_fields_GATEWAY_ENABLED] ?? 0) !== 1 || $host === '') {
            return '';
        }

        $scheme = (int)($project[WlsPanelProject::schema_fields_BACKEND_SSL] ?? 0) === 1 ? 'https' : 'http';
        return $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyGatewayConfig(array $input): array
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

        /** @var IpcControlGateway $gateway */
        $gateway = ObjectManager::getInstance(IpcControlGateway::class);
        return $gateway->proxyApply($this->resolveControlInstance($input), $routesData);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveControlInstance(array $input): string
    {
        $candidates = [
            $input['gateway_instance'] ?? null,
            $input['wls_instance'] ?? null,
            $input['instance'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            \getenv('WLS_INSTANCE') ?: null,
            \getenv('WLS_INSTANCE_NAME') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $value = \trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        $autoDetectedInstance = $this->resolveSingleRunningGatewayInstance();
        if ($autoDetectedInstance !== null) {
            return $autoDetectedInstance;
        }

        return 'default';
    }

    private function resolveSingleRunningGatewayInstance(): ?string
    {
        try {
            $instances = $this->instanceManager->getAllPersistedInstanceInfo();
        } catch (\Throwable) {
            return null;
        }

        $matches = [];
        foreach ($instances as $name => $info) {
            if (!$info instanceof \Weline\Server\Service\Contract\ServerInstanceInfo) {
                continue;
            }

            $raw = $this->instanceManager->getRawInstanceData((string)$name);
            if (!\is_array($raw) || empty($raw['gateway']['enabled'])) {
                continue;
            }

            try {
                $runtimeStats = $this->instanceManager->getRuntimeStatsForInstance($info);
            } catch (\Throwable) {
                continue;
            }

            if ((bool)($runtimeStats['instance_running'] ?? false)) {
                $matches[] = (string)$name;
            }
        }

        return \count($matches) === 1 ? $matches[0] : null;
    }

    private function loadProxy(int $proxyId): ReverseProxy
    {
        return $this->freshProxy()
            ->clearQuery()
            ->where(ReverseProxy::schema_fields_ID, $proxyId)
            ->find()
            ->fetch();
    }

    private function freshProject(): WlsPanelProject
    {
        /** @var WlsPanelProject $project */
        $project = ObjectManager::getInstance(WlsPanelProject::class, [], false);
        return $project;
    }

    private function freshProxy(): ReverseProxy
    {
        /** @var ReverseProxy $proxy */
        $proxy = ObjectManager::getInstance(ReverseProxy::class, [], false);
        return $proxy;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stringValue(array $input, string $key): string
    {
        return \trim((string)($input[$key] ?? ''));
    }

    private function truthy(mixed $value): bool
    {
        return \in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    private function normalizeStatus(string $status): string
    {
        return \in_array($status, [WlsPanelProject::STATUS_ACTIVE, WlsPanelProject::STATUS_INACTIVE], true)
            ? $status
            : WlsPanelProject::STATUS_ACTIVE;
    }
}
