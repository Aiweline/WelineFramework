<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;
use Weline\Bot\Service\RoleConfigAssistant;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;

/**
 * Backend role management.
 */
#[Acl('Weline_Bot::role', 'Role Management', 'Manage AI bot roles', '')]
class Role extends BackendController
{
    public function __construct(
        private readonly BotRole $roleModel,
        private readonly BotSkill $skillModel,
        private readonly RoleConfigAssistant $roleConfigAssistant,
    ) {}

    #[Acl('Weline_Bot::role_list', 'Role List', '', 'View role list')]
    public function getList()
    {
        $roles = $this->roleModel->reset()
            ->order(BotRole::schema_fields_ROLE_ID, 'DESC')
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

    #[Acl('Weline_Bot::role_listing', 'Role List', '', 'View role list')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Bot::role_add', 'Add Role', '', 'Add role')]
    public function getAdd()
    {
        return $this->renderForm(null);
    }

    #[Acl('Weline_Bot::role_edit', 'Edit Role', '', 'Edit role')]
    public function getEdit()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            $this->getSession()->addError(__('Role not found'));
            return $this->redirect('*/*/listing');
        }

        return $this->renderForm($role);
    }

    #[Acl('Weline_Bot::role_save', 'Save Role', '', 'Save role')]
    public function postSave()
    {
        $id = (int) $this->request->getParam('id', 0);
        $code = trim((string) $this->request->getParam('code', ''));
        $name = trim((string) $this->request->getParam('name', ''));
        $systemPrompt = (string) $this->request->getParam('system_prompt', '');
        $modelId = (int) $this->request->getParam('model_id', 0);
        $scenarioAdapterCode = trim((string) $this->request->getParam('scenario_adapter_code', ''));
        $status = (string) $this->request->getParam('status', BotRole::STATUS_ENABLED);
        $description = (string) $this->request->getParam('description', '');
        $icon = (string) $this->request->getParam('icon', 'mdi-robot');

        $permissions = $this->parseStringList(
            $this->request->getParam('permissions', []),
            (string) $this->request->getParam('permissions_text', '')
        );
        $selectedSkills = $this->parseStringList($this->request->getParam('skills', []));
        $modelConfig = $this->parseJsonArray(
            $this->request->getParam('model_config', null),
            (string) $this->request->getParam('model_config_text', '')
        );

        if ($code === '' || $name === '') {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('Role code and name are required'),
            ]);
        }

        $existingRole = $this->roleModel->reset()
            ->where(BotRole::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existingRole->getId() && $existingRole->getId() !== $id) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('Role code already exists'),
            ]);
        }

        $role = $id > 0 ? $this->roleModel->load($id) : clone $this->roleModel;
        $role->setData(BotRole::schema_fields_CODE, $code);
        $role->setData(BotRole::schema_fields_NAME, $name);
        $role->setData(BotRole::schema_fields_SYSTEM_PROMPT, $systemPrompt);
        $role->setData(BotRole::schema_fields_STATUS, $status);
        $role->setData(BotRole::schema_fields_DESCRIPTION, $description);
        $role->setData(BotRole::schema_fields_ICON, $icon);
        $role->setData(BotRole::schema_fields_MODEL_ID, $modelId > 0 ? $modelId : null);
        $role->setData(BotRole::schema_fields_SCENARIO_ADAPTER_CODE, $scenarioAdapterCode !== '' ? $scenarioAdapterCode : null);
        $role->setPermissions($permissions);
        $role->setSkills($selectedSkills);
        $role->setModelConfig($modelConfig);
        $role->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('Saved successfully'),
            'data' => ['id' => $role->getId()],
        ]);
    }

    #[Acl('Weline_Bot::role_suggest', 'AI Suggestion', '', 'Generate AI role suggestion')]
    public function postSuggest()
    {
        $payload = $this->getRequestPayload();
        $guideConfig = $this->getGuideConfig();
        $maxProjectCount = max(1, (int) ($guideConfig['max_project_count'] ?? 200));

        $models = $this->getAvailableModels();
        $adapters = $this->getAvailableAdapters();
        $skills = $this->getAvailableSkills();

        $input = [
            'template_code' => (string) ($payload['template_code'] ?? $this->request->getParam('template_code', $guideConfig['default_template'] ?? 'general_assistant')),
            'brief' => (string) ($payload['brief'] ?? $this->request->getParam('brief', '')),
            'project_count' => $this->clampProjectCount((int) ($payload['project_count'] ?? $this->request->getParam('project_count', 1)), $maxProjectCount),
            'target_outcome' => (string) ($payload['target_outcome'] ?? $this->request->getParam('target_outcome', '')),
            'workflow_style' => (string) ($payload['workflow_style'] ?? $this->request->getParam('workflow_style', 'balanced')),
            'risk_level' => (string) ($payload['risk_level'] ?? $this->request->getParam('risk_level', 'safe')),
            'bot_profile' => (string) ($payload['bot_profile'] ?? $this->request->getParam('bot_profile', $guideConfig['default_profile'] ?? 'multi_project')),
            'role_name' => (string) ($payload['role_name'] ?? $this->request->getParam('name', '')),
            'role_code' => (string) ($payload['role_code'] ?? $this->request->getParam('code', '')),
            'model_id' => (int) ($payload['model_id'] ?? $this->request->getParam('model_id', 0)),
            'icon' => (string) ($payload['icon'] ?? $this->request->getParam('icon', 'mdi-robot')),
        ];

        $suggestion = $this->roleConfigAssistant->buildSuggestion($input, $models, $adapters, $skills);

        return $this->fetchJson([
            'success' => true,
            'msg' => __('Suggestion generated'),
            'data' => $suggestion,
        ]);
    }

    #[Acl('Weline_Bot::role_suggest', 'AI Suggestion', '', 'Generate AI role suggestion')]
    public function getSuggest()
    {
        return $this->postSuggest();
    }

    #[Acl('Weline_Bot::role_delete', 'Delete Role', '', 'Delete role')]
    public function postDelete()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('Role not found'),
            ]);
        }

        if ($role->isDefault()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('Default role cannot be deleted'),
            ]);
        }

        $role->delete();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('Deleted successfully'),
        ]);
    }

    #[Acl('Weline_Bot::role_toggle', 'Toggle Role', '', 'Toggle role status')]
    public function postToggle()
    {
        $id = (int) $this->request->getParam('id', 0);
        $role = $this->roleModel->load($id);

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('Role not found'),
            ]);
        }

        $newStatus = $role->isEnabled() ? BotRole::STATUS_DISABLED : BotRole::STATUS_ENABLED;
        $role->setData(BotRole::schema_fields_STATUS, $newStatus);
        $role->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('Status updated'),
            'data' => ['status' => $newStatus],
        ]);
    }

    public function getModels()
    {
        return $this->fetchJson([
            'success' => true,
            'data' => $this->getAvailableModels(),
        ]);
    }

    public function getAdapters()
    {
        return $this->fetchJson([
            'success' => true,
            'data' => $this->getAvailableAdapters(),
        ]);
    }

    private function renderForm(?BotRole $role)
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

            if (($roleData[BotRole::schema_fields_STATUS] ?? '') === '') {
                $roleData[BotRole::schema_fields_STATUS] = BotRole::STATUS_ENABLED;
            }
            if (($roleData[BotRole::schema_fields_ICON] ?? '') === '') {
                $roleData[BotRole::schema_fields_ICON] = 'mdi-robot';
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
        if (($roleData[BotRole::schema_fields_CODE] ?? '') === '') {
            $roleData[BotRole::schema_fields_CODE] = (string) ($template['code'] ?? 'bot_role');
        }
        if (($roleData[BotRole::schema_fields_NAME] ?? '') === '') {
            $roleData[BotRole::schema_fields_NAME] = (string) ($template['name'] ?? 'Bot Assistant');
        }
        if (($roleData[BotRole::schema_fields_DESCRIPTION] ?? '') === '') {
            $roleData[BotRole::schema_fields_DESCRIPTION] = (string) ($template['description'] ?? '');
        }
        if (($roleData[BotRole::schema_fields_SYSTEM_PROMPT] ?? '') === '') {
            $roleData[BotRole::schema_fields_SYSTEM_PROMPT] = (string) ($template['system_prompt'] ?? '');
        }
        if (($roleData[BotRole::schema_fields_SCENARIO_ADAPTER_CODE] ?? '') === '') {
            $roleData[BotRole::schema_fields_SCENARIO_ADAPTER_CODE] = (string) ($template['scenario_adapter_code'] ?? '');
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
            'bot_profile' => (string) $this->request->getParam('bot_profile', (string) ($guideConfig['default_profile'] ?? 'multi_project')),
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
                ['code' => 'single_project', 'name' => 'Single Project', 'description' => 'Simple workflow for one project.'],
                ['code' => 'multi_project', 'name' => 'Multi Project', 'description' => 'Prioritized workflow for many projects.'],
                ['code' => 'enterprise', 'name' => 'Enterprise Governance', 'description' => 'Strict safety and governance mode.'],
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
                'name' => trim((string) ($profile['name'] ?? $code)),
                'description' => trim((string) ($profile['description'] ?? '')),
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
            ->where(BotSkill::schema_fields_IS_ACTIVE, 1)
            ->order(BotSkill::schema_fields_CATEGORY, 'ASC')
            ->select()
            ->fetch();

        $data = [];
        foreach ($skills->getItems() as $skill) {
            $data[] = [
                'id' => (int) $skill->getId(),
                'code' => (string) $skill->getData(BotSkill::schema_fields_CODE),
                'name' => (string) $skill->getData(BotSkill::schema_fields_NAME),
                'description' => (string) $skill->getData(BotSkill::schema_fields_DESCRIPTION),
                'category' => (string) $skill->getData(BotSkill::schema_fields_CATEGORY),
                'is_dangerous' => (int) $skill->getData(BotSkill::schema_fields_IS_DANGEROUS),
                'requires_confirmation' => (int) $skill->getData(BotSkill::schema_fields_REQUIRES_CONFIRMATION),
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
        $config = Env::getInstance()->getModuleConfig('Weline_Bot');
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
                (string) __('Choose role template'),
                (string) __('Describe business context and project count'),
                (string) __('Generate AI suggestion'),
                (string) __('Review and save'),
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
