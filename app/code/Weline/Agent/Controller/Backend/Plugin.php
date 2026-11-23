<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Agent\Model\AgentPlugin;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Service\PluginScanner;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * Backend plugin management（扫描/启停/角色绑定查看）
 */
#[Acl('Weline_Agent::plugin', '插件管理', '管理智能体插件', '')]
class Plugin extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentPlugin $pluginModel,
        private readonly AgentRole $roleModel,
        private readonly PluginScanner $scanner,
    ) {}

    #[Acl('Weline_Agent::plugin_list', '插件列表', '', '查看插件列表')]
    public function getList()
    {
        $plugins = $this->pluginModel->reset()
            ->order(AgentPlugin::schema_fields_PLUGIN_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetchArray();

        // 角色 → 插件绑定反查（role.plugins JSON 数组）
        $roleBindings = [];
        try {
            $roles = $this->roleModel->reset()
                ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
                ->select()
                ->fetchArray();
            foreach ($roles as $role) {
                $bound = $role[AgentRole::schema_fields_PLUGINS] ?? '';
                $codes = is_string($bound) ? (json_decode($bound, true) ?: []) : (is_array($bound) ? $bound : []);
                foreach ((array)$codes as $code) {
                    $roleBindings[(string)$code][] = (string)($role[AgentRole::schema_fields_NAME] ?? $role[AgentRole::schema_fields_CODE] ?? '');
                }
            }
        } catch (\Throwable) {
        }

        $this->assign('plugins', $plugins);
        $this->assign('pagination', $this->pluginModel->getPagination());
        $this->assign('role_bindings', $roleBindings);
        return $this->fetch();
    }

    #[Acl('Weline_Agent::plugin_list', '插件列表', '', '查看插件列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::plugin_scan', '扫描插件', '', '扫描并登记插件')]
    public function postScan()
    {
        try {
            $result = $this->scanner->scan();
            return $this->respondMutation([
                'success' => true,
                'msg' => sprintf(
                    __('插件扫描完成：扫描 %d，登记 %d，缺失 %d'),
                    (int)($result['scanned'] ?? 0),
                    (int)($result['upserted'] ?? 0),
                    (int)($result['missing'] ?? 0)
                ),
            ], '*/backend/plugin/listing');
        } catch (\Throwable $e) {
            return $this->respondMutation(['success' => false, 'msg' => $e->getMessage()], '*/backend/plugin/listing');
        }
    }

    #[Acl('Weline_Agent::plugin_toggle', '切换状态', '', '启用/禁用插件')]
    public function postToggle()
    {
        $id = (int)$this->request->getParam('id', 0);
        try {
            $plugin = $this->pluginModel->clearData()->load($id)->fetch();
            if (!$plugin->getId()) {
                return $this->respondMutation(['success' => false, 'msg' => __('插件不存在')], '*/backend/plugin/listing');
            }
            $status = (string)$plugin->getData(AgentPlugin::schema_fields_STATUS);
            $next = match ($status) {
                AgentPlugin::STATUS_ENABLED => AgentPlugin::STATUS_DISABLED,
                AgentPlugin::STATUS_DISABLED, AgentPlugin::STATUS_INSTALLED => AgentPlugin::STATUS_ENABLED,
                default => $status,
            };
            if ($next === $status) {
                return $this->respondMutation(['success' => false, 'msg' => __('当前状态不可切换')], '*/backend/plugin/listing');
            }
            $plugin->setData(AgentPlugin::schema_fields_STATUS, $next);
            $plugin->save();
            return $this->respondMutation(['success' => true, 'msg' => __('状态已更新')], '*/backend/plugin/listing');
        } catch (\Throwable $e) {
            return $this->respondMutation(['success' => false, 'msg' => $e->getMessage()], '*/backend/plugin/listing');
        }
    }
}
