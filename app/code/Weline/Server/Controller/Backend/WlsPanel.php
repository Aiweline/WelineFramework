<?php
declare(strict_types=1);

namespace Weline\Server\Controller\Backend;

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
    #[Acl('Weline_Server::wls_panel_dashboard', '查看 WLS Panel Dashboard', 'mdi-view-dashboard-outline', '查看 WLS Panel Dashboard', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getIndex(): string
    {
        return $this->renderPanel('dashboard', (string)__('WLS Panel'));
    }

    #[Acl('Weline_Server::wls_panel_marketplace', '查看 WLS Plugin Marketplace', 'mdi-storefront-outline', '查看 WLS Plugin Marketplace', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
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

        $this->redirectToMarketplacePanel($params, '#installed-plugins');
        return '';
    }

    #[Acl('Weline_Server::wls_panel_security', '查看 WLS Security', 'mdi-shield-outline', '查看 WLS Security', 'Weline_Server::wls_panel', accessMode: AclModel::ACCESS_MODE_READ)]
    public function getSecurity(): string
    {
        return $this->renderPanel('security', (string)__('WLS Security'));
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

        $this->redirectToPanel($params);
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

        $this->redirectToPanel($params);
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

        $this->redirectToPanel($params, '#gateway-settings');
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

        $this->redirectToPanel($params, '#gateway-settings');
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

        $this->redirectToSecurityPanel($params, $saveMode === 'domain_override' ? '#project-security-policy' : '#security-rules');
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
        $this->assign('panelProjectConfigCenter', $projectConfigCenterService->build($panelProjects, $operationCapabilities));
        return $this->fetch('index');
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
    private function redirectToPanel(array $params, string $fragment = '#projects'): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel', $params);
        $this->request->getResponse()->redirect($this->appendCleanFragment($url, $fragment));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToSecurityPanel(array $params, string $fragment = '#security-rules'): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/security', $params);
        $this->request->getResponse()->redirect($this->appendCleanFragment($url, $fragment));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToMarketplacePanel(array $params, string $fragment = '#installed-plugins'): void
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl('*/backend/wls-panel/marketplace', $params);
        $this->request->getResponse()->redirect($this->appendCleanFragment($url, $fragment));
    }

    private function appendCleanFragment(string $url, string $fragment): string
    {
        $url = \rtrim($url, '?&');
        $fragment = \trim($fragment);
        if ($fragment === '') {
            return $url;
        }

        return $url . (\str_starts_with($fragment, '#') ? $fragment : '#' . $fragment);
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
