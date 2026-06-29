<?php
declare(strict_types=1);

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Env;
use Weline\Framework\App\Controller\BackendController;
use Weline\Acl\Model\Acl as AclModel;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\WlsPanelDashboardDataService;
use Weline\Server\Service\WlsPanelGatewaySettingsService;
use Weline\Server\Service\WlsPanelPluginDiscoveryService;
use Weline\Server\Service\WlsPanelPluginRefreshService;
use Weline\Server\Service\WlsPanelProjectConfigCenterService;
use Weline\Server\Service\WlsPanelProjectRegistryService;
use Weline\Server\Service\WlsPanelSecurityDataService;

#[Acl('Weline_Server::wls_panel', 'WLS Panel', 'mdi-view-dashboard', '访问 WLS Panel', 'Weline_Backend::system_service_group', accessMode: AclModel::ACCESS_MODE_READ)]
class WlsPanel extends BackendController
{
    private const APPSTORE_PRODUCTION_PLATFORM_URL = 'https://app.aiweline.com';
    private const APPSTORE_LOCAL_PLATFORM_URL = 'https://app.weline.test:9523';

    #[Acl('Weline_Server::wls_panel_dashboard', '查看 WLS Panel Dashboard', 'mdi-view-dashboard-outline', '查看 WLS Panel Dashboard', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getIndex(): string
    {
        return $this->renderPanel('dashboard', (string)__('WLS Panel'));
    }

    #[Acl('Weline_Server::wls_panel_projects', '查看 WLS Panel Projects', 'mdi-server-network', '查看 WLS Panel 托管项目', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getProjects(): string
    {
        return $this->renderPanel('projects', (string)__('WLS Projects'));
    }

    #[Acl('Weline_Server::wls_panel_gateway', '查看 WLS Panel Gateway', 'mdi-router-network', '查看 WLS Panel 网关', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getGateway(): string
    {
        return $this->renderPanel('gateway', (string)__('WLS Gateway'));
    }

    #[Acl('Weline_Server::wls_panel_plugin', 'View WLS Panel Plugin', 'mdi-puzzle-outline', 'View WLS Panel plugin page', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getPlugin(): string
    {
        return $this->renderPanel('plugin', (string)__('WLS Plugin'));
    }

    #[Acl('Weline_Server::wls_panel_marketplace', 'View WLS Plugin Marketplace', 'mdi-storefront-outline', 'View WLS Plugin Marketplace', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getMarketplace(): string
    {
        return $this->renderPanel('marketplace', (string)__('WLS Plugin Marketplace'));
    }

    #[Acl('Weline_Server::wls_panel_plugin_refresh', '刷新 WLS Panel 插件能力', 'mdi-refresh', '刷新 WLS Panel 插件能力', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postPluginRefresh(): string
    {
        /** @var WlsPanelPluginRefreshService $pluginRefreshService */
        $pluginRefreshService = ObjectManager::getInstance(WlsPanelPluginRefreshService::class);
        $result = $pluginRefreshService->refreshPanelCapabilities();

        $params = [
            'panel_plugin_refresh' => '1',
            'panel_plugin_refresh_registry_mode' => (string)($result['registry_mode'] ?? ''),
            'panel_plugin_refresh_registry_count' => \count((array)($result['registry_modules'] ?? [])),
            'panel_plugin_refresh_routes' => !empty($result['routes_refreshed']) ? '1' : '0',
            'panel_plugin_refresh_route_count' => \count((array)($result['route_modules'] ?? [])),
            'panel_plugin_refresh_plugin_count' => (int)($result['plugin_count'] ?? 0),
            'panel_plugin_refresh_contribution_count' => (int)($result['contribution_count'] ?? 0),
        ];
        $error = \trim((string)($result['error'] ?? ''));
        if ($error === '') {
            $params['panel_notice'] = 'plugins_refreshed';
        } else {
            $params['panel_error'] = $error;
        }

        $this->redirectToMarketplacePanel($params);
        return '';
    }

    #[Acl('Weline_Server::wls_panel_security', '查看 WLS Security', 'mdi-shield-outline', '查看 WLS Security', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurity(): string
    {
        return $this->renderPanel('security', (string)__('WLS Security'));
    }

    #[Acl('Weline_Server::wls_panel_security_logs', '查看 WLS Attack Logs', 'mdi-shield-search', '查看 WLS Attack Logs', 'Weline_Server::wls_panel_security', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurityLogs(): string
    {
        return $this->renderPanel('security_logs', (string)__('WLS Attack Logs'));
    }

    #[Acl('Weline_Server::wls_panel_security_rules', '查看 WLS Security Rules', 'mdi-shield-cog-outline', '查看 WLS Security Rules', 'Weline_Server::wls_panel_security', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurityRules(): string
    {
        return $this->renderPanel('security_rules', (string)__('WLS Security Rules'));
    }

    #[Acl('Weline_Server::wls_panel_security_policy', '查看 WLS Project Security Policy', 'mdi-shield-key-outline', '查看 WLS Project Security Policy', 'Weline_Server::wls_panel_security', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurityPolicy(): string
    {
        return $this->renderPanel('security_policy', (string)__('WLS Project Security Policy'));
    }

    #[Acl('Weline_Server::wls_panel_security_audit', '查看 WLS Security Policy Audit', 'mdi-shield-clock-outline', '查看 WLS Security Policy Audit', 'Weline_Server::wls_panel_security', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurityAudit(): string
    {
        return $this->renderPanel('security_audit', (string)__('WLS Security Policy Audit'));
    }

    #[Acl('Weline_Server::wls_panel_project_save', '保存 WLS Panel 项目', 'mdi-content-save-outline', '保存 WLS Panel 托管项目', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postProjectSave(): string
    {
        /** @var WlsPanelProjectRegistryService $projectRegistry */
        $projectRegistry = ObjectManager::getInstance(WlsPanelProjectRegistryService::class);
        $result = $projectRegistry->saveFromPanel((array)$this->request->getPost());

        $params = [];
        if (!empty($result['success'])) {
            $params['panel_notice'] = 'project_saved';
            $params['edit_project_id'] = (int)($result['project_id'] ?? 0);
            if (empty($result['gateway_applied']) && !empty($result['gateway_apply_message'])) {
                $params['panel_error'] = (string)$result['gateway_apply_message'];
            }
        } else {
            $params['panel_error'] = (string)($result['message'] ?? __('Managed project save failed.'));
            $projectId = (int)($result['project_id'] ?? 0);
            if ($projectId > 0) {
                $params['edit_project_id'] = $projectId;
            }
        }

        $this->redirectToProjectsPanel($params);
        return '';
    }

    #[Acl('Weline_Server::wls_panel_project_delete', '删除 WLS Panel 项目', 'mdi-delete-outline', '删除 WLS Panel 托管项目', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postProjectDelete(): string
    {
        /** @var WlsPanelProjectRegistryService $projectRegistry */
        $projectRegistry = ObjectManager::getInstance(WlsPanelProjectRegistryService::class);
        $result = $projectRegistry->deleteFromPanel(
            (int)$this->request->getPost('project_id', 0),
            (array)$this->request->getPost()
        );

        $params = !empty($result['success'])
            ? ['panel_notice' => 'project_removed']
            : ['panel_error' => (string)($result['message'] ?? __('Managed project delete failed.'))];
        if (!empty($result['success']) && empty($result['gateway_applied']) && !empty($result['gateway_apply_message'])) {
            $params['panel_error'] = (string)$result['gateway_apply_message'];
        }

        $this->redirectToProjectsPanel($params);
        return '';
    }

    #[Acl('Weline_Server::wls_panel_gateway_apply', '应用 WLS Gateway 路由', 'mdi-router-network', '应用 WLS Gateway 路由', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postGatewayApply(): string
    {
        /** @var WlsPanelGatewaySettingsService $gatewaySettings */
        $gatewaySettings = ObjectManager::getInstance(WlsPanelGatewaySettingsService::class);
        $result = $gatewaySettings->applyRoutes((array)$this->request->getPost());

        $params = [
            'gateway_instance' => (string)($result['selected_instance'] ?? $this->request->getPost('gateway_instance', '')),
        ];
        if (!empty($result['success'])) {
            $params['panel_notice'] = 'gateway_applied';
        } else {
            $params['panel_error'] = (string)($result['message'] ?? __('Gateway route apply failed.'));
        }

        $this->redirectToGatewayPanel($params);
        return '';
    }

    #[Acl('Weline_Server::wls_panel_gateway_save', '保存 WLS Gateway 配置', 'mdi-content-save-cog-outline', '保存 WLS Gateway 配置', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postGatewaySave(): string
    {
        /** @var WlsPanelGatewaySettingsService $gatewaySettings */
        $gatewaySettings = ObjectManager::getInstance(WlsPanelGatewaySettingsService::class);
        $result = $gatewaySettings->saveConfiguration((array)$this->request->getPost());

        $params = [
            'gateway_instance' => (string)($result['selected_instance'] ?? $this->request->getPost('gateway_instance', '')),
        ];
        if (!empty($result['success'])) {
            $params['panel_notice'] = 'gateway_saved';
            $warnings = [];
            if (!empty($result['gateway_apply_message']) && empty($result['gateway_applied'])) {
                $warnings[] = (string)$result['gateway_apply_message'];
            }
            if (!empty($result['runtime_action_message']) && empty($result['runtime_action_success'])) {
                $warnings[] = (string)$result['runtime_action_message'];
            }
            if ($warnings !== []) {
                $params['panel_error'] = \implode(' ', \array_unique($warnings));
            }
        } else {
            $params['panel_error'] = (string)($result['message'] ?? __('Gateway mode configuration save failed.'));
        }

        $this->redirectToGatewayPanel($params);
        return '';
    }

    #[Acl('Weline_Server::wls_panel_security_rules_save', '保存 WLS Security 规则', 'mdi-shield-check-outline', '保存 WLS Security 规则', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function postSecurityRulesSave(): string
    {
        /** @var WlsPanelSecurityDataService $securityDataService */
        $securityDataService = ObjectManager::getInstance(WlsPanelSecurityDataService::class);
        $post = (array)$this->request->getPost();
        $saveMode = \trim((string)($post['save_mode'] ?? ''));
        if ($saveMode === 'domain_override') {
            $result = $securityDataService->saveDomainOverrideFromPanel($post);
        } elseif (!empty($post['visual_rules'])) {
            $result = $securityDataService->saveRulesFromPanel($post);
        } else {
            $result = $securityDataService->saveRulesJson((string)$this->request->getPost('rules_json', ''), [
                'action' => 'rules_json_saved',
                'source' => 'advanced_json',
                'scope' => (string)($post['security_scope'] ?? 'all'),
                'domain' => (string)($post['security_domain'] ?? ''),
                'changed_sections' => ['rules_json'],
            ]);
        }

        $params = !empty($result['success'])
            ? ['panel_notice' => 'security_rules_saved']
            : ['panel_error' => (string)($result['message'] ?? __('Security rules save failed.'))];
        foreach (['security_scope', 'security_instance', 'security_ip', 'security_severity', 'security_type', 'security_blocked'] as $key) {
            $value = \trim((string)($post[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        if ($saveMode === 'domain_override') {
            $this->redirectToSecurityPolicyPanel($params);
        } else {
            $this->redirectToSecurityRulesPanel($params);
        }
        return '';
    }

    private function renderPanel(string $activePage, string $title): string
    {
        $this->useStandaloneLayout();
        /** @var WlsPanelPluginDiscoveryService $pluginDiscovery */
        $pluginDiscovery = ObjectManager::getInstance(WlsPanelPluginDiscoveryService::class);
        $pluginState = $pluginDiscovery->getInstalledPlugins();
        $operationCapabilities = $pluginDiscovery->getOperationCapabilities(null, $pluginState);
        $panelPluginContributions = $pluginDiscovery->getPanelContributions(null, $pluginState);
        $panelPluginViewerContext = $this->resolvePanelPluginViewerContext($panelPluginContributions);
        /** @var WlsPanelProjectConfigCenterService $projectConfigCenterService */
        $projectConfigCenterService = ObjectManager::getInstance(WlsPanelProjectConfigCenterService::class);
        /** @var WlsPanelDashboardDataService $dashboardDataService */
        $dashboardDataService = ObjectManager::getInstance(WlsPanelDashboardDataService::class);
        /** @var WlsPanelProjectRegistryService $projectRegistry */
        $projectRegistry = ObjectManager::getInstance(WlsPanelProjectRegistryService::class);
        /** @var WlsPanelGatewaySettingsService $gatewaySettings */
        $gatewaySettings = ObjectManager::getInstance(WlsPanelGatewaySettingsService::class);
        /** @var WlsPanelSecurityDataService $securityDataService */
        $securityDataService = ObjectManager::getInstance(WlsPanelSecurityDataService::class);
        $panelDashboardData = $dashboardDataService->getDashboardData();
        $panelProjects = \is_array($panelDashboardData['projects'] ?? null) ? $panelDashboardData['projects'] : [];

        $this->assign('activePage', $activePage);
        $this->assign('title', $title);
        $this->assign('panelDashboardData', $panelDashboardData);
        $this->assign(
            'panelGatewaySettings',
            $gatewaySettings->getSettingsData((string)$this->request->getGet('gateway_instance', ''))
        );
        $this->assign(
            'panelSecurityData',
            $securityDataService->getSecurityDataFromFilters([
                'scope' => (string)$this->request->getGet('security_scope', 'all'),
                'projects' => $panelProjects,
                'instance' => (string)$this->request->getGet('security_instance', ''),
                'ip' => (string)$this->request->getGet('security_ip', ''),
                'severity' => (string)$this->request->getGet('security_severity', ''),
                'type' => (string)$this->request->getGet('security_type', ''),
                'blocked' => (string)$this->request->getGet('security_blocked', ''),
                'page' => (int)$this->request->getGet('security_page', 1),
                'limit' => (int)$this->request->getGet('security_limit', 10),
                'policy_audit_action' => (string)$this->request->getGet('policy_audit_action', ''),
                'policy_audit_source' => (string)$this->request->getGet('policy_audit_source', ''),
                'policy_audit_domain' => (string)$this->request->getGet('policy_audit_domain', ''),
                'policy_audit_section' => (string)$this->request->getGet('policy_audit_section', ''),
                'policy_audit_keyword' => (string)$this->request->getGet('policy_audit_keyword', ''),
                'policy_audit_limit' => (int)$this->request->getGet('policy_audit_limit', 20),
            ])
        );
        $this->assign('panelProjectFormData', $projectRegistry->getFormData((int)$this->request->getGet('edit_project_id', 0)));
        $this->assign('panelNotice', $this->resolvePanelNotice((string)$this->request->getGet('panel_notice', '')));
        $this->assign('panelError', \trim((string)$this->request->getGet('panel_error', '')));
        $this->assign('panelAutoRefresh', $this->resolvePanelAutoRefresh($activePage, (string)$this->request->getGet('panel_auto_refresh', '')));
        $this->assign('panelPluginRefreshResult', $this->resolvePanelPluginRefreshResult());
        $this->assign('installedWlsPlugins', $pluginState['items'] ?? []);
        $this->assign('installedWlsPluginCount', (int)($pluginState['count'] ?? 0));
        $this->assign('installedWlsPluginError', (string)($pluginState['error'] ?? ''));
        $this->assign('panelOperationCapabilities', $operationCapabilities);
        $this->assign('panelPluginContributions', $panelPluginContributions);
        $this->assign('panelPluginViewerContext', $panelPluginViewerContext);
        $this->assign('panelProjectConfigCenter', $projectConfigCenterService->build($panelProjects, $operationCapabilities));
        $this->assign('appStorePlatformResolution', $this->resolveAppStorePlatformResolution());
        return $this->fetch('index');
    }

    /**
     * @param array{items?: array<int, array<string, mixed>>} $panelPluginContributions
     * @return array<string, mixed>
     */
    private function resolvePanelPluginViewerContext(array $panelPluginContributions): array
    {
        $items = \is_array($panelPluginContributions['items'] ?? null)
            ? \array_values($panelPluginContributions['items'])
            : [];
        $pluginKey = $this->normalizePanelPluginKey((string)$this->request->getGet('plugin_key', ''));
        $childKey = $this->normalizePanelPluginKey((string)$this->request->getGet('child_key', ''));
        $moduleName = \strtolower(\trim((string)$this->request->getGet('plugin_module', '')));

        $selectedParent = null;
        $selectedItem = null;
        foreach ($items as $item) {
            if (!\is_array($item) || !$this->panelPluginParentMatches($item, $pluginKey, $moduleName)) {
                continue;
            }

            $selectedParent = $item;
            $selectedItem = $this->selectPanelPluginChild($item, $childKey) ?? $this->selectDefaultPanelPluginItem($item);
            break;
        }

        if ($selectedItem === null && $childKey !== '') {
            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $child = $this->selectPanelPluginChild($item, $childKey);
                if ($child !== null) {
                    $selectedParent = $item;
                    $selectedItem = $child;
                    break;
                }
            }
        }

        if ($selectedItem === null) {
            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $selectedParent = $item;
                $selectedItem = $this->selectDefaultPanelPluginItem($item);
                if ($selectedItem !== null) {
                    break;
                }
            }
        }

        if (!\is_array($selectedParent) || !\is_array($selectedItem)) {
            return [
                'available' => false,
                'plugin_key' => '',
                'child_key' => '',
                'module_name' => '',
                'parent_label' => '',
                'label' => (string)__('Plugin Capability'),
                'description' => '',
                'url' => '',
                'forward_params' => [],
            ];
        }

        return [
            'available' => true,
            'plugin_key' => $this->normalizePanelPluginKey((string)($selectedParent['key'] ?? '')),
            'child_key' => $this->normalizePanelPluginKey((string)($selectedItem['key'] ?? '')),
            'module_name' => (string)($selectedItem['module_name'] ?? $selectedParent['module_name'] ?? ''),
            'parent_label' => (string)($selectedParent['label'] ?? ''),
            'label' => (string)($selectedItem['label'] ?? $selectedParent['label'] ?? __('Plugin Capability')),
            'description' => (string)($selectedItem['description'] ?? $selectedParent['description'] ?? ''),
            'url' => (string)($selectedItem['url'] ?? ''),
            'forward_params' => $this->resolvePanelPluginForwardParams(),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function panelPluginParentMatches(array $item, string $pluginKey, string $moduleName): bool
    {
        if ($pluginKey !== '' && $this->normalizePanelPluginKey((string)($item['key'] ?? '')) === $pluginKey) {
            return true;
        }

        return $moduleName !== ''
            && \strtolower(\trim((string)($item['module_name'] ?? ''))) === $moduleName;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function selectPanelPluginChild(array $item, string $childKey): ?array
    {
        if ($childKey === '') {
            return null;
        }

        $children = \is_array($item['children'] ?? null) ? \array_values($item['children']) : [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            if ($this->normalizePanelPluginKey((string)($child['key'] ?? '')) === $childKey) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function selectDefaultPanelPluginItem(array $item): ?array
    {
        if (\trim((string)($item['url'] ?? '')) !== '') {
            return $item;
        }

        $children = \is_array($item['children'] ?? null) ? \array_values($item['children']) : [];
        foreach ($children as $child) {
            if (\is_array($child) && \trim((string)($child['url'] ?? '')) !== '') {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function resolvePanelPluginForwardParams(): array
    {
        $forward = [];
        foreach ((array)$this->request->getGet() as $key => $value) {
            $key = \trim((string)$key);
            if ($key === '' || \in_array($key, ['plugin_key', 'child_key', 'plugin_module'], true)) {
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                $forward[$key] = $value;
            }
        }

        return $forward;
    }

    private function normalizePanelPluginKey(string $key): string
    {
        $key = \trim(\strtolower($key));
        if ($key === '') {
            return '';
        }

        $key = (string)\preg_replace('/[^a-z0-9:_-]+/', '-', $key);
        return \trim($key, '-_');
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function resolveAppStorePlatformResolution(): array
    {
        $resolverClass = '\\Weline\\AppStore\\Service\\AppStorePlatformUrlResolver';
        if (\class_exists($resolverClass)) {
            try {
                $resolution = (new $resolverClass())->resolve();
                if (\is_array($resolution) && \trim((string)($resolution['platform_url'] ?? '')) !== '') {
                    return [
                        'platform_url' => \rtrim(\trim((string)$resolution['platform_url']), '/'),
                        'source' => \trim((string)($resolution['source'] ?? '')),
                        'environment' => \trim((string)($resolution['environment'] ?? '')),
                    ];
                }
            } catch (\Throwable) {
                // Keep the standalone panel available even when AppStore is disabled or mid-upgrade.
            }
        }

        return $this->resolveFallbackAppStorePlatformResolution();
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function resolveFallbackAppStorePlatformResolution(): array
    {
        if ($this->hasExplicitLocalDeployMode()) {
            return $this->resolveFallbackLocalAppStorePlatformResolution();
        }

        $deployed = $this->readProductionDeployAppStorePlatformResolution();
        if ($deployed['platform_url'] !== '') {
            return $deployed;
        }

        return [
            'platform_url' => self::APPSTORE_PRODUCTION_PLATFORM_URL,
            'source' => 'default:production',
            'environment' => 'production',
        ];
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function resolveFallbackLocalAppStorePlatformResolution(): array
    {
        $envPlatformUrl = \getenv('WELINE_APPSTORE_PLATFORM_URL');
        $normalizedEnvPlatformUrl = \is_string($envPlatformUrl) ? $this->normalizeFallbackLocalAppStorePlatformUrl($envPlatformUrl) : '';
        if ($normalizedEnvPlatformUrl !== '') {
            return [
                'platform_url' => $normalizedEnvPlatformUrl,
                'source' => 'env:WELINE_APPSTORE_PLATFORM_URL',
                'environment' => 'local',
            ];
        }

        try {
            $configPlatformUrl = Env::get('appstore.platform_url');
            $normalizedConfigPlatformUrl = \is_string($configPlatformUrl) ? $this->normalizeFallbackLocalAppStorePlatformUrl($configPlatformUrl) : '';
            if ($normalizedConfigPlatformUrl !== '') {
                return [
                    'platform_url' => $normalizedConfigPlatformUrl,
                    'source' => 'config:appstore.platform_url',
                    'environment' => 'local',
                ];
            }
        } catch (\Throwable) {
            // Keep the WLS Panel usable even if the config layer is unavailable.
        }

        return [
            'platform_url' => self::APPSTORE_LOCAL_PLATFORM_URL,
            'source' => 'local_default',
            'environment' => 'local',
        ];
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function readProductionDeployAppStorePlatformResolution(): array
    {
        if (!\defined('BP')) {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $currentFile = BP . 'var' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'current.json';
        if (!\is_file($currentFile) || !\is_readable($currentFile)) {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $json = \ltrim((string)\file_get_contents($currentFile), "\xEF\xBB\xBF");
        $payload = \json_decode($json, true);
        if (!\is_array($payload)) {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $environment = \strtolower(\trim((string)($payload['appstore_environment'] ?? '')));
        if ($environment !== 'production') {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $platformUrl = $this->normalizeFallbackAppStorePlatformUrl((string)($payload['appstore_platform_url'] ?? ''));
        $platformUrlSource = \trim((string)($payload['appstore_platform_url_source'] ?? ''));
        if ($platformUrl !== self::APPSTORE_PRODUCTION_PLATFORM_URL || $platformUrlSource !== 'production_default') {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        return [
            'platform_url' => $platformUrl,
            'source' => 'deploy:var/deploy/current.json',
            'environment' => 'production',
        ];
    }

    private function normalizeFallbackAppStorePlatformUrl(string $url): string
    {
        $url = \rtrim(\trim($url), '/');
        if ($url === '' || $this->isOfficialWebsiteAppStorePlatformUrl($url)) {
            return '';
        }

        return $url;
    }

    private function normalizeFallbackLocalAppStorePlatformUrl(string $url): string
    {
        $url = $this->normalizeFallbackAppStorePlatformUrl($url);
        if ($url !== self::APPSTORE_LOCAL_PLATFORM_URL) {
            return '';
        }

        return $url;
    }

    private function isOfficialWebsiteAppStorePlatformUrl(string $url): bool
    {
        $host = \strtolower((string)\parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return \str_starts_with($host, 'www.')
            && (\str_ends_with($host, 'weline.test') || \str_ends_with($host, 'aiweline.com'));
    }

    private function hasExplicitLocalDeployMode(): bool
    {
        $envFile = $this->resolveEnvFilePath();
        if ($envFile === '' || !\is_file($envFile)) {
            return false;
        }

        try {
            $config = include $envFile;
        } catch (\Throwable) {
            return false;
        }

        if (!\is_array($config)) {
            return false;
        }

        $system = \is_array($config['system'] ?? null) ? $config['system'] : [];
        foreach ([$system['deploy'] ?? null, $config['deploy'] ?? null] as $mode) {
            $mode = \strtolower(\trim((string)$mode));
            if ($mode === 'dev' || $mode === 'local') {
                return true;
            }
        }

        return false;
    }

    private function resolveEnvFilePath(): string
    {
        if (\defined('APP_ETC_PATH')) {
            return APP_ETC_PATH . 'env.php';
        }

        if (\defined('BP')) {
            return BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        }

        return '';
    }

    private function useStandaloneLayout(): void
    {
        $this->layoutType = 'fullscreen.default';

        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showRightSidebar'] = false;
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['class'] = trim((string)($meta['class'] ?? '') . ' wls-panel-fullscreen');

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToProjectsPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/projects', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToGatewayPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/gateway', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToSecurityPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/security', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToSecurityRulesPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/security-rules', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToSecurityPolicyPanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/security-policy', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToMarketplacePanel(array $params): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/marketplace', $params);
        $this->request->getResponse()->redirect(\rtrim($url, '?&'));
    }

    private function resolvePanelNotice(string $code): string
    {
        return match ($code) {
            'project_saved' => (string)__('Managed project saved.'),
            'project_removed' => (string)__('Managed project removed.'),
            'gateway_applied' => (string)__('Gateway routes applied.'),
            'gateway_saved' => (string)__('Gateway mode configuration saved. Restart or reload the target WLS instance for listener changes to take effect.'),
            'security_rules_saved' => (string)__('Security rules saved.'),
            'plugins_refreshed' => (string)__('Panel plugin capabilities refreshed.'),
            default => '',
        };
    }

    private function resolvePanelAutoRefresh(string $activePage, string $code): string
    {
        if ($activePage !== 'marketplace') {
            return '';
        }

        return \trim($code) === 'plugins' ? 'plugins' : '';
    }

    /**
     * @return array{
     *     has_result: bool,
     *     registry_mode: string,
     *     registry_count: int,
     *     routes_refreshed: bool,
     *     route_count: int,
     *     plugin_count: int,
     *     contribution_count: int
     * }
     */
    private function resolvePanelPluginRefreshResult(): array
    {
        if (\trim((string)$this->request->getGet('panel_plugin_refresh', '')) !== '1') {
            return [
                'has_result' => false,
                'registry_mode' => '',
                'registry_count' => 0,
                'routes_refreshed' => false,
                'route_count' => 0,
                'plugin_count' => 0,
                'contribution_count' => 0,
            ];
        }

        return [
            'has_result' => true,
            'registry_mode' => \trim((string)$this->request->getGet('panel_plugin_refresh_registry_mode', 'unknown')),
            'registry_count' => \max(0, (int)$this->request->getGet('panel_plugin_refresh_registry_count', 0)),
            'routes_refreshed' => \trim((string)$this->request->getGet('panel_plugin_refresh_routes', '0')) === '1',
            'route_count' => \max(0, (int)$this->request->getGet('panel_plugin_refresh_route_count', 0)),
            'plugin_count' => \max(0, (int)$this->request->getGet('panel_plugin_refresh_plugin_count', 0)),
            'contribution_count' => \max(0, (int)$this->request->getGet('panel_plugin_refresh_contribution_count', 0)),
        ];
    }
}
