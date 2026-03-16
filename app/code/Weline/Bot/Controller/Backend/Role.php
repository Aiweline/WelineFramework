<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;

/**
 * 角色管理控制器
 */
#[Acl('Weline_Bot::role', '角色管理', '管理 AI 智能体角色', '')]
class Role extends BackendController
{
    public function __construct(
        private readonly BotRole $roleModel,
        private readonly BotSkill $skillModel,
    ) {}

    #[Acl('Weline_Bot::role_list', '角色列表', '', '查看角色列表')]
    public function getList()
    {
        $roles = $this->roleModel->reset()
            ->order(BotRole::schema_fields_ROLE_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('roles', $roles->getItems());
        $this->assign('pagination', $roles->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Bot::role_listing', '角色列表', '', '查看角色列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Bot::role_add', '添加角色', '', '添加新角色')]
    public function getAdd()
    {
        // 获取可用技能列表
        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        // 通过 w_query 获取可用模型列表
        $models = w_query('ai', 'getActiveModels', []) ?? [];

        // 通过 w_query 获取可用场景适配器列表
        $adapters = w_query('ai', 'getActiveAdapters', []) ?? [];

        $this->assign('skills', $skills->getItems());
        $this->assign('models', $models);
        $this->assign('adapters', $adapters);
        $this->assign('role', null);
        return $this->fetch('form');
    }

    #[Acl('Weline_Bot::role_edit', '编辑角色', '', '编辑角色')]
    public function getEdit()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            $this->getSession()->addError(__('角色不存在'));
            return $this->redirect('*/*/listing');
        }

        // 获取可用技能列表
        $skills = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        // 通过 w_query 获取可用模型列表
        $models = w_query('ai', 'getActiveModels', []) ?? [];

        // 通过 w_query 获取可用场景适配器列表
        $adapters = w_query('ai', 'getActiveAdapters', []) ?? [];

        $this->assign('role', $role);
        $this->assign('skills', $skills->getItems());
        $this->assign('models', $models);
        $this->assign('adapters', $adapters);
        return $this->fetch('form');
    }

    #[Acl('Weline_Bot::role_save', '保存角色', '', '保存角色')]
    public function postSave()
    {
        $id = (int) $this->request->getParam('id', 0);
        $code = $this->request->getParam('code', '');
        $name = $this->request->getParam('name', '');
        $systemPrompt = $this->request->getParam('system_prompt', '');
        $permissions = $this->request->getParam('permissions', []);
        $selectedSkills = $this->request->getParam('skills', []);
        $modelId = (int) $this->request->getParam('model_id', 0);
        $scenarioAdapterCode = $this->request->getParam('scenario_adapter_code', '');
        $modelConfig = $this->request->getParam('model_config', []);
        $status = $this->request->getParam('status', BotRole::STATUS_ENABLED);
        $description = $this->request->getParam('description', '');
        $icon = $this->request->getParam('icon', 'mdi-robot');

        if (empty($code) || empty($name)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色代码和名称不能为空'),
            ]);
        }

        // 检查代码是否重复
        $existingRole = $this->roleModel->reset()
            ->where(BotRole::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existingRole->getId() && $existingRole->getId() != $id) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色代码已存在'),
            ]);
        }

        $role = $id > 0 ? $this->roleModel->load($id) : $this->roleModel;
        $role->setData(BotRole::schema_fields_CODE, $code);
        $role->setData(BotRole::schema_fields_NAME, $name);
        $role->setData(BotRole::schema_fields_SYSTEM_PROMPT, $systemPrompt);
        $role->setData(BotRole::schema_fields_STATUS, $status);
        $role->setData(BotRole::schema_fields_DESCRIPTION, $description);
        $role->setData(BotRole::schema_fields_ICON, $icon);

        // 设置模型 ID
        if ($modelId > 0) {
            $role->setData(BotRole::schema_fields_MODEL_ID, $modelId);
        }

        // 设置场景适配器
        if (!empty($scenarioAdapterCode)) {
            $role->setData(BotRole::schema_fields_SCENARIO_ADAPTER_CODE, $scenarioAdapterCode);
        }

        // 处理权限
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?: [];
        }
        $role->setPermissions($permissions);

        // 处理技能
        if (is_string($selectedSkills)) {
            $selectedSkills = json_decode($selectedSkills, true) ?: [];
        }
        $role->setSkills($selectedSkills);

        // 处理模型配置
        if (is_string($modelConfig)) {
            $modelConfig = json_decode($modelConfig, true) ?: [];
        }
        $role->setModelConfig($modelConfig);

        $role->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('保存成功'),
            'data' => ['id' => $role->getId()],
        ]);
    }

    #[Acl('Weline_Bot::role_delete', '删除角色', '', '删除角色')]
    public function postDelete()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色不存在'),
            ]);
        }

        // 禁止删除默认角色
        if ($role->isDefault()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('不能删除默认角色'),
            ]);
        }

        $role->delete();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('删除成功'),
        ]);
    }

    #[Acl('Weline_Bot::role_toggle', '切换状态', '', '切换角色状态')]
    public function postToggle()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色不存在'),
            ]);
        }

        $newStatus = $role->isEnabled() ? BotRole::STATUS_DISABLED : BotRole::STATUS_ENABLED;
        $role->setData(BotRole::schema_fields_STATUS, $newStatus);
        $role->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('状态已更新'),
            'data' => ['status' => $newStatus],
        ]);
    }

    /**
     * 获取模型列表（API）
     */
    public function getModels()
    {
        $models = w_query('ai', 'getActiveModels', []) ?? [];
        return $this->fetchJson([
            'success' => true,
            'data' => $models,
        ]);
    }

    /**
     * 获取适配器列表（API）
     */
    public function getAdapters()
    {
        $adapters = w_query('ai', 'getActiveAdapters', []) ?? [];
        return $this->fetchJson([
            'success' => true,
            'data' => $adapters,
        ]);
    }
}
