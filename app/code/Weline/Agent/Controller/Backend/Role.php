<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Service\AgentAdminService;
use Weline\Agent\Service\RoleConfigAssistant;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;

/**
 * Backend role management.
 */
#[Acl('Weline_Agent::role', '角色管理', '管理智能体角色', '')]
class Role extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentRole $roleModel,
        private readonly AgentSkill $skillModel,
        private readonly RoleConfigAssistant $roleConfigAssistant,
        private readonly AgentAdminService $adminService,
    ) {}

    #[Acl('Weline_Agent::role_list', '角色列表', '', '查看角色列表')]
    public function getList()
    {
        $roles = $this->roleModel->reset()
            ->order(AgentRole::schema_fields_ROLE_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $guideConfig = $this->getGuideConfig();
        $guideProfiles = $this->normalizeGuideProfiles($guideConfig);
        $initialGuide = $this->buildInitialGuide($guideConfig);
        $maxProjectCount = max(1, (int) ($guideConfig['max_project_count'] ?? 200));
        $skills = $this->getAvailableSkills();
        $templates = $this->roleConfigAssistant->getTemplates(
            array_map(static fn(array $skill): string => (string) ($skill['code'] ?? ''), $skills)
        );

        $this->assign('roles', $roles->getItems());
        $this->assign('pagination', $roles->getPagination());
        $this->assign('guide_enabled', (bool) ($guideConfig['enabled'] ?? true));
        $this->assign('guide_config', $guideConfig);
        $this->assign('guide_profiles', $guideProfiles);
        $this->assign('initial_guide', $initialGuide);
        $this->assign('max_project_count', $maxProjectCount);
        $this->assign('role_templates', $templates);

        return $this->fetch();
    }

    #[Acl('Weline_Agent::role_listing', '角色列表', '', '查看角色列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::role_add', '添加角色', '', '添加角色')]
    public function getAdd()
    {
        return $this->renderForm(null);
    }

    #[Acl('Weline_Agent::role_edit', '编辑角色', '', '编辑角色')]
    public function getEdit()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            $this->getMessageManager()->addError(__('角色不存在'));
            return $this->redirect('*/backend/role/listing');
        }

        return $this->renderForm($role);
    }

    #[Acl('Weline_Agent::role_save', '保存角色', '', '保存角色')]
    public function postSave()
    {
        $result = $this->adminService->saveRole([
            'id' => (int) $this->request->getParam('id', 0),
            'code' => trim((string) $this->request->getParam('code', '')),
            'name' => trim((string) $this->request->getParam('name', '')),
            'system_prompt' => (string) $this->request->getParam('system_prompt', ''),
            'model_id' => (int) $this->request->getParam('model_id', 0),
            'scenario_adapter_code' => trim((string) $this->request->getParam('scenario_adapter_code', '')),
            'status' => (string) $this->request->getParam('status', AgentRole::STATUS_ENABLED),
            'description' => (string) $this->request->getParam('description', ''),
            'icon' => (string) $this->request->getParam('icon', 'mdi-robot'),
            'permissions' => $this->request->getParam('permissions', []),
            'permissions_text' => (string) $this->request->getParam('permissions_text', ''),
            'skills' => $this->request->getParam('skills', []),
            'model_config' => $this->request->getParam('model_config', null),
            'model_config_text' => (string) $this->request->getParam('model_config_text', ''),
        ]);

        return $this->respondMutation($result, '*/backend/role/listing');
    }

    #[Acl('Weline_Agent::role_suggest', 'AI 建议', '', '生成 AI 角色建议')]
    public function postSuggest()
    {
        $payload = $this->getRequestPayload();
        $guideConfig = $this->getGuideConfig();
        $maxProjectCount = max(1, (int) ($guideConfig['max_project_count'] ?? 200));

        $input = [
            'template_code' => (string) ($payload['template_code'] ?? $this->request->getParam('template_code', $guideConfig['default_template'] ?? 'general_assistant')),
            'brief' => (string) ($payload['brief'] ?? $this->request->getParam('brief', '')),
            'project_count' => $this->clampProjectCount((int) ($payload['project_count'] ?? $this->request->getParam('project_count', 1)), $maxProjectCount),
            'target_outcome' => (string) ($payload['target_outcome'] ?? $this->request->getParam('target_outcome', '')),
            'workflow_style' => (string) ($payload['workflow_style'] ?? $this->request->getParam('workflow_style', 'balanced')),
            'risk_level' => (string) ($payload['risk_level'] ?? $this->request->getParam('risk_level', 'safe')),
            'agent_profile' => (string) ($payload['agent_profile'] ?? $this->request->getParam('agent_profile', $guideConfig['default_profile'] ?? 'multi_project')),
            'role_name' => (string) ($payload['role_name'] ?? $this->request->getParam('name', '')),
            'role_code' => (string) ($payload['role_code'] ?? $this->request->getParam('code', '')),
            'model_id' => (int) ($payload['model_id'] ?? $this->request->getParam('model_id', 0)),
            'icon' => (string) ($payload['icon'] ?? $this->request->getParam('icon', 'mdi-robot')),
        ];

        $result = $this->adminService->suggestRole($input);
        return $this->respondMutation($result, '*/backend/role/listing');
    }

    #[Acl('Weline_Agent::role_suggest', 'AI 建议', '', '生成 AI 角色建议')]
    public function getSuggest()
    {
        if (!$this->wantsJsonMutationResponse()) {
            return $this->redirect('*/backend/role/listing');
        }
        return $this->postSuggest();
    }

    #[Acl('Weline_Agent::role_delete', '删除角色', '', '删除角色')]
    public function postDelete()
    {
        $result = $this->adminService->deleteRole((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/role/listing');
    }

    #[Acl('Weline_Agent::role_toggle', '切换角色', '', '切换角色状态')]
    public function postToggle()
    {
        $result = $this->adminService->toggleRole((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/role/listing');
    }

    public function getModels()
    {
        if (!$this->wantsJsonMutationResponse()) {
            return $this->redirect('*/backend/role/listing');
        }
        return $this->fetchJson([
            'success' => true,
            'data' => $this->getAvailableModels(),
        ]);
    }

    public function getAdapters()
    {
        if (!$this->wantsJsonMutationResponse()) {
            return $this->redirect('*/backend/role/listing');
        }
        return $this->fetchJson([
            'success' => true,
            'data' => $this->getAvailableAdapters(),
        ]);
    }

    private function renderForm(?AgentRole $role)
    {
        $models = $this->getAvailableModels();
        $adapters = $this->getAvailableAdapters();
        $skills = $this->getAvailableSkills();

        $templates = $this->roleConfigAssistant->getTemplates(
            array_map(static fn(array $skill): string => (string) ($skill['code'] ?? ''), $skills)
        );

        $guideConfig = $this->getGuideConfig();
        $initialGuide = $this->buildInitialGuide($guideConfig);
        $guideProfiles = $this->normalizeGuideProfiles($guideConfig);
        $maxProjectCount = max(1, (int) ($guideConfig['max_project_count'] ?? 200));

        $roleData = $role?->getData() ?? [];
        $selectedSkills = $role?->getSkills() ?? [];
        $selectedPermissions = $role?->getPermissions() ?? [];
        $selectedModelConfig = $role?->getModelConfig() ?? [];

        if ($role === null) {
            $template = $this->findTemplateByCode((string) ($initialGuide['template_code'] ?? ''), $templates);
            if ($template !== null) {
                $roleData = $this->applyTemplatePrefill($roleData, $template);
                if (empty($selectedSkills)) {
                    $selectedSkills = (array) ($template['skills'] ?? []);
                }
                if (empty($selectedPermissions)) {
                    $selectedPermissions = (array) ($template['permissions'] ?? []);
                }
                if (empty($selectedModelConfig)) {
                    $selectedModelConfig = (array) ($template['model_config'] ?? []);
                }
            }

            if (($roleData[AgentRole::schema_fields_STATUS] ?? '') === '') {
                $roleData[AgentRole::schema_fields_STATUS] = AgentRole::STATUS_ENABLED;
            }
            if (($roleData[AgentRole::schema_fields_ICON] ?? '') === '') {
                $roleData[AgentRole::schema_fields_ICON] = 'mdi-robot';
            }
        }

        if (empty($selectedModelConfig) && isset($guideConfig['default_model_config']) && is_array($guideConfig['default_model_config'])) {
            $selectedModelConfig = $guideConfig['default_model_config'];
        }

        $this->assign('role', $role);
        $this->assign('role_data', $roleData);
        $this->assign('models', $models);
        $this->assign('adapters', $adapters);
        $this->assign('skills', $skills);
        $this->assign('role_templates', $templates);
        $this->assign('selected_skills', $selectedSkills);
        $this->assign('selected_permissions', $selectedPermissions);
        $this->assign('selected_model_config', $selectedModelConfig);
        $this->assign('guide_config', $guideConfig);
        $this->assign('guide_profiles', $guideProfiles);
        $this->assign('initial_guide', $initialGuide);
        $this->assign('max_project_count', $maxProjectCount);

        return $this->fetch('form');
    }

    /**
     * @param array<string, mixed> $roleData
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function applyTemplatePrefill(array $roleData, array $template): array
    {
        if (($roleData[AgentRole::schema_fields_CODE] ?? '') === '') {
            $roleData[AgentRole::schema_fields_CODE] = (string) ($template['code'] ?? 'agent_role');
        }
        if (($roleData[AgentRole::schema_fields_NAME] ?? '') === '') {
            $roleData[AgentRole::schema_fields_NAME] = (string) ($template['name'] ?? 'Agent Assistant');
        }
        if (($roleData[AgentRole::schema_fields_DESCRIPTION] ?? '') === '') {
            $roleData[AgentRole::schema_fields_DESCRIPTION] = (string) ($template['description'] ?? '');
        }
        if (($roleData[AgentRole::schema_fields_SYSTEM_PROMPT] ?? '') === '') {
            $roleData[AgentRole::schema_fields_SYSTEM_PROMPT] = (string) ($template['system_prompt'] ?? '');
        }
        if (($roleData[AgentRole::schema_fields_SCENARIO_ADAPTER_CODE] ?? '') === '') {
            $roleData[AgentRole::schema_fields_SCENARIO_ADAPTER_CODE] = (string) ($template['scenario_adapter_code'] ?? '');
        }

        return $roleData;
    }

    /**
     * @param string $code
     * @param array<int, array<string, mixed>> $templates
     * @return array<string, mixed>|null
     */
    private function findTemplateByCode(string $code, array $templates): ?array
    {
        foreach ($templates as $template) {
            if ((string) ($template['code'] ?? '') === $code) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $guideConfig
     * @return array<string, mixed>
     */
    private function buildInitialGuide(array $guideConfig): array
    {
        $maxProjectCount = max(1, (int) ($guideConfig['max_project_count'] ?? 200));
        $projectCount = $this->clampProjectCount((int) $this->request->getParam('project_count', 1), $maxProjectCount);

        return [
            'template_code' => (string) $this->request->getParam('template_code', (string) ($guideConfig['default_template'] ?? 'general_assistant')),
            'brief' => (string) $this->request->getParam('brief', ''),
            'project_count' => $projectCount,
            'target_outcome' => (string) $this->request->getParam('target_outcome', ''),
            'workflow_style' => (string) $this->request->getParam('workflow_style', 'balanced'),
            'risk_level' => (string) $this->request->getParam('risk_level', 'safe'),
            'agent_profile' => (string) $this->request->getParam('agent_profile', (string) ($guideConfig['default_profile'] ?? 'multi_project')),
        ];
    }

    /**
     * @param array<string, mixed> $guideConfig
     * @return array<int, array<string, string>>
     */
    private function normalizeGuideProfiles(array $guideConfig): array
    {
        $profiles = $guideConfig['profiles'] ?? [];
        if (!is_array($profiles) || empty($profiles)) {
            $profiles = [
                ['code' => 'single_project', 'name' => '单项目', 'description' => '适合单个项目的轻量流程。'],
                ['code' => 'multi_project', 'name' => '多项目', 'description' => '适合多项目并行的优先级流程。'],
                ['code' => 'enterprise', 'name' => '企业治理', 'description' => '严格安全与治理模式。'],
            ];
        }

        $normalized = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $code = trim((string) ($profile['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $normalized[] = [
                'code' => $code,
                'name' => (string) __(trim((string) ($profile['name'] ?? $code))),
                'description' => (string) __(trim((string) ($profile['description'] ?? ''))),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailableModels(): array
    {
        try {
            $raw = w_query('ai', 'getActiveModels', []) ?? [];
        } catch (\Throwable) {
            return [];
        }
        $models = [];

        foreach ((array) $raw as $item) {
            if ($item instanceof \Weline\Ai\Model\AiModel) {
                $item = $item->getData();
            }
            if (!is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? $item['model_id'] ?? 0);
            $modelCode = (string) ($item['model_code'] ?? $item['code'] ?? '');
            if ($id <= 0 || $modelCode === '') {
                continue;
            }

            $models[] = [
                'id' => $id,
                'model_code' => $modelCode,
                'name' => (string) ($item['name'] ?? $item['model_name'] ?? $modelCode),
                'provider' => (string) ($item['provider'] ?? $item['provider_code'] ?? ''),
                'is_default' => (int) ($item['is_default'] ?? 0),
            ];
        }

        return $models;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailableAdapters(): array
    {
        try {
            $raw = w_query('ai', 'getActiveAdapters', []) ?? [];
        } catch (\Throwable) {
            return [];
        }
        $adapters = [];

        foreach ((array) $raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = (string) ($item['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $adapters[] = [
                'code' => $code,
                'name' => (string) ($item['name'] ?? $code),
                'description' => (string) ($item['description'] ?? ''),
            ];
        }

        return $adapters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailableSkills(): array
    {
        $skills = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->order(AgentSkill::schema_fields_CATEGORY, 'ASC')
            ->select()
            ->fetch();

        $data = [];
        foreach ($skills->getItems() as $skill) {
            $data[] = [
                'id' => (int) $skill->getId(),
                'code' => (string) $skill->getData(AgentSkill::schema_fields_CODE),
                'name' => (string) $skill->getData(AgentSkill::schema_fields_NAME),
                'description' => (string) $skill->getData(AgentSkill::schema_fields_DESCRIPTION),
                'category' => (string) $skill->getData(AgentSkill::schema_fields_CATEGORY),
                'is_dangerous' => (int) $skill->getData(AgentSkill::schema_fields_IS_DANGEROUS),
                'requires_confirmation' => (int) $skill->getData(AgentSkill::schema_fields_REQUIRES_CONFIRMATION),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestPayload(): array
    {
        $body = $this->request->getBodyParams();

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param mixed $raw
     * @return array<string>
     */
    private function parseStringList(mixed $raw, string $textFallback = ''): array
    {
        $values = [];

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $values = $decoded;
            } else {
                $values = preg_split('/[\r\n,]+/', $raw) ?: [];
            }
        } elseif (is_array($raw)) {
            $values = $raw;
        }

        if (empty($values) && $textFallback !== '') {
            $values = preg_split('/[\r\n,]+/', $textFallback) ?: [];
        }

        $result = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function parseJsonArray(mixed $raw, string $textFallback = ''): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $candidate = '';
        if (is_string($raw) && trim($raw) !== '') {
            $candidate = $raw;
        } elseif ($textFallback !== '') {
            $candidate = $textFallback;
        }

        if ($candidate === '') {
            return [];
        }

        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getGuideConfig(): array
    {
        $config = Env::getInstance()->getModuleConfig('Weline_Agent');
        $guide = is_array($config['guided_setup'] ?? null) ? $config['guided_setup'] : [];

        if (!isset($guide['enabled'])) {
            $guide['enabled'] = true;
        }
        if (!isset($guide['default_template'])) {
            $guide['default_template'] = 'general_assistant';
        }
        if (!isset($guide['default_profile'])) {
            $guide['default_profile'] = 'multi_project';
        }
        if (!isset($guide['max_project_count'])) {
            $guide['max_project_count'] = 200;
        }
        if (!isset($guide['default_model_config']) || !is_array($guide['default_model_config'])) {
            $guide['default_model_config'] = [
                'temperature' => 0.4,
                'max_tokens' => 4096,
            ];
        }
        if (!isset($guide['steps']) || !is_array($guide['steps'])) {
            $guide['steps'] = [
                (string) __('选择角色模板'),
                (string) __('描述业务场景与项目数量'),
                (string) __('生成 AI 建议'),
                (string) __('检查并保存'),
            ];
        }

        return $guide;
    }

    private function clampProjectCount(int $projectCount, int $maxProjectCount): int
    {
        $projectCount = max(1, $projectCount);
        return min($projectCount, max(1, $maxProjectCount));
    }

    private function isGuideEnabled(): bool
    {
        $guide = $this->getGuideConfig();
        return (bool) ($guide['enabled'] ?? true);
    }
}
