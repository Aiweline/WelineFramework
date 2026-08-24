<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Ai\Service\AiService;

/**
 * Role configuration assistant.
 *
 * - Provide readable role templates.
 * - Generate AI-based draft when model is available.
 * - Fallback to template draft when AI is unavailable.
 */
class RoleConfigAssistant
{
    public function __construct(
        private readonly AiService $aiService,
    ) {}

    /**
     * @param array<string> $availableSkillCodes
     * @return array<int, array<string, mixed>>
     */
    public function getTemplates(array $availableSkillCodes): array
    {
        $templates = [
            [
                'code' => 'general_assistant',
                'name' => 'General Assistant',
                'description' => 'Daily multi-project collaboration and lightweight execution.',
                'scenario_adapter_code' => 'bot_agent',
                'system_prompt' => 'You are a multi-project assistant. Clarify goals, propose a shortest executable path, and confirm before risky actions.',
                'skills' => ['filesystem.read', 'http.request'],
                'permissions' => ['fs.read:/app/*', 'http.request:*'],
                'model_config' => ['temperature' => 0.4, 'max_tokens' => 4096],
                'best_for' => ['coordination', 'summaries', 'light ops'],
            ],
            [
                'code' => 'coding_agent',
                'name' => 'Coding Assistant',
                'description' => 'Code reading, debugging, shell diagnostics, and patch planning.',
                'scenario_adapter_code' => 'bot_agent',
                'system_prompt' => 'You are a coding assistant. Prefer minimal safe changes, include validation steps, and avoid destructive actions.',
                'skills' => ['filesystem.read', 'filesystem.write', 'shell.execute', 'database.query', 'http.request'],
                'permissions' => ['fs.read:/app/*', 'fs.write:/var/*', 'shell.execute:*', 'db.read:*', 'http.request:*'],
                'model_config' => ['temperature' => 0.2, 'max_tokens' => 8192],
                'best_for' => ['debugging', 'automation', 'implementation support'],
            ],
            [
                'code' => 'it_ops_assistant',
                'name' => 'IT Ops Assistant',
                'description' => 'Monitoring, incident triage, and stability-first troubleshooting.',
                'scenario_adapter_code' => 'bot_it_ops',
                'system_prompt' => 'You are an IT operations assistant. Prioritize reliability and rollback safety before speed.',
                'skills' => ['shell.execute', 'filesystem.read', 'http.request'],
                'permissions' => ['shell.execute:*', 'fs.read:/var/*', 'http.request:*'],
                'model_config' => ['temperature' => 0.1, 'max_tokens' => 4096],
                'best_for' => ['health checks', 'log analysis', 'incident SOP'],
            ],
            [
                'code' => 'seo_assistant',
                'name' => 'SEO Assistant',
                'description' => 'Keyword strategy, optimization planning, and content structure guidance.',
                'scenario_adapter_code' => 'bot_seo',
                'system_prompt' => 'You are an SEO assistant. Keep output actionable and measurable with clear priorities.',
                'skills' => ['http.request', 'filesystem.read'],
                'permissions' => ['http.request:*', 'fs.read:/app/*'],
                'model_config' => ['temperature' => 0.6, 'max_tokens' => 4096],
                'best_for' => ['keywords', 'page optimization', 'content planning'],
            ],
        ];

        foreach ($templates as &$template) {
            $template['skills'] = $this->filterSkills((array) ($template['skills'] ?? []), $availableSkillCodes);
        }
        unset($template);

        return $templates;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $adapters
     * @param array<int, array<string, mixed>> $skills
     * @return array<string, mixed>
     */
    public function buildSuggestion(array $input, array $models, array $adapters, array $skills): array
    {
        $skillCodes = array_values(array_unique(array_filter(array_map(
            static fn(array $skill): string => (string) ($skill['code'] ?? ''),
            $skills
        ))));

        $templates = $this->getTemplates($skillCodes);
        $templateCode = (string) ($input['template_code'] ?? 'general_assistant');
        $template = $this->findTemplate($templateCode, $templates) ?? $templates[0];

        $fallback = $this->buildTemplateDraft($input, $template, $models, $adapters, $skillCodes);
        $warnings = [];
        $source = 'template';

        $aiDraft = $this->buildAiDraft($input, $template, $models, $adapters, $skills);
        if ($aiDraft !== null) {
            $fallback = $this->mergeDraft($fallback, $aiDraft);
            $source = 'ai';
        } elseif (!empty($models)) {
            $warnings[] = 'AI suggestion unavailable, applied template draft.';
        } else {
            $warnings[] = 'No active model found, applied template draft.';
        }

        $fallback = $this->applyInputStrategy($fallback, $input);
        $draft = $this->sanitizeDraft($fallback, $template, $models, $adapters, $skillCodes);

        return [
            'source' => $source,
            'draft' => $draft,
            'warnings' => $warnings,
            'template' => [
                'code' => (string) ($template['code'] ?? ''),
                'name' => (string) ($template['name'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $adapters
     * @param array<string> $skillCodes
     * @return array<string, mixed>
     */
    private function buildTemplateDraft(
        array $input,
        array $template,
        array $models,
        array $adapters,
        array $skillCodes
    ): array {
        $brief = trim((string) ($input['brief'] ?? ''));
        $projectCount = max(1, (int) ($input['project_count'] ?? 1));
        $profile = $this->normalizeProfile((string) ($input['bot_profile'] ?? 'multi_project'));
        $roleName = trim((string) ($input['role_name'] ?? ''));

        if ($roleName === '') {
            $roleName = (string) ($template['name'] ?? 'Bot Assistant');
            if ($profile === 'enterprise') {
                $roleName .= ' (Governance)';
            } elseif ($projectCount > 1) {
                $roleName .= ' (Multi-Project)';
            }
        }

        $adapterCode = (string) ($template['scenario_adapter_code'] ?? 'bot_agent');
        if (!$this->containsAdapter($adapterCode, $adapters)) {
            $adapterCode = (string) ($adapters[0]['code'] ?? '');
        }

        $defaultModel = $this->resolvePreferredModel($models, (int) ($input['model_id'] ?? 0));

        $systemPrompt = (string) ($template['system_prompt'] ?? '');
        if ($brief !== '') {
            $systemPrompt .= "\n\nBusiness context: {$brief}";
        }
        if ($projectCount > 1) {
            $systemPrompt .= "\n\nHandle {$projectCount} projects by priority before execution.";
        }

        $skills = $this->filterSkills((array) ($template['skills'] ?? []), $skillCodes);
        if (empty($skills) && !empty($skillCodes)) {
            $skills = array_slice($skillCodes, 0, 2);
        }

        $permissions = (array) ($template['permissions'] ?? []);
        if (empty($permissions)) {
            $permissions = $this->inferPermissionsBySkills($skills);
        }

        return [
            'code' => $this->slugify((string) ($input['role_code'] ?? (string) ($template['code'] ?? 'bot_role'))),
            'name' => $roleName,
            'description' => (string) ($template['description'] ?? ''),
            'system_prompt' => trim($systemPrompt),
            'scenario_adapter_code' => $adapterCode,
            'model_id' => (int) ($defaultModel['id'] ?? 0),
            'skills' => $skills,
            'permissions' => array_values(array_unique(array_filter(array_map('trim', $permissions)))),
            'model_config' => (array) ($template['model_config'] ?? ['temperature' => 0.4, 'max_tokens' => 4096]),
            'status' => 'enabled',
            'icon' => (string) ($input['icon'] ?? 'mdi-robot'),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyInputStrategy(array $draft, array $input): array
    {
        $profile = $this->normalizeProfile((string) ($input['bot_profile'] ?? 'multi_project'));
        $workflowStyle = $this->normalizeWorkflowStyle((string) ($input['workflow_style'] ?? 'balanced'));
        $riskLevel = $this->normalizeRiskLevel((string) ($input['risk_level'] ?? 'safe'));
        $projectCount = max(1, (int) ($input['project_count'] ?? 1));
        $brief = trim((string) ($input['brief'] ?? ''));
        $targetOutcome = trim((string) ($input['target_outcome'] ?? ''));

        $profileMeta = $this->getProfileMeta($profile);
        $description = trim((string) ($draft['description'] ?? ''));
        if ($description === '') {
            $description = (string) $profileMeta['description'];
        } else {
            $description .= ' ' . (string) $profileMeta['description_suffix'];
        }
        $draft['description'] = trim($description);

        $systemPrompt = trim((string) ($draft['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = (string) $profileMeta['prompt'];
        }

        $systemPrompt .= "\n\nProfile: " . (string) $profileMeta['name'] . '.';
        if ($brief !== '') {
            $systemPrompt .= "\nBusiness context: {$brief}";
        }
        if ($targetOutcome !== '') {
            $systemPrompt .= "\nTarget outcome: {$targetOutcome}";
        }
        if ($projectCount > 1) {
            $systemPrompt .= "\nProject count: {$projectCount}. Use a queue and deliver prioritized batches.";
        } else {
            $systemPrompt .= "\nProject count: 1. Keep communication short and focused.";
        }

        $systemPrompt .= "\nWorkflow style: {$workflowStyle}.";
        $systemPrompt .= match ($workflowStyle) {
            'fast' => "\nOptimize for response speed while preserving critical checks.",
            'careful' => "\nFavor stable execution plans and explicit validation before changes.",
            default => "\nBalance speed and reliability for day-to-day operation.",
        };

        $systemPrompt .= "\nRisk level: {$riskLevel}.";
        $systemPrompt .= match ($riskLevel) {
            'aggressive' => "\nYou may propose bold optimizations, but still list rollback options.",
            'normal' => "\nUse standard safety checks before write actions.",
            default => "\nAlways request explicit confirmation before sensitive actions.",
        };

        if ($profile === 'enterprise') {
            $systemPrompt .= "\nFor enterprise governance, keep clear audit notes and decision rationale.";
        }
        $draft['system_prompt'] = trim($systemPrompt);

        $modelConfig = is_array($draft['model_config'] ?? null) ? $draft['model_config'] : [];
        $temperature = (float) ($modelConfig['temperature'] ?? $profileMeta['temperature']);
        $maxTokens = (int) ($modelConfig['max_tokens'] ?? $profileMeta['max_tokens']);

        if ($profile === 'single_project') {
            $maxTokens = min($maxTokens, 4096);
        } elseif ($profile === 'multi_project') {
            $maxTokens = max($maxTokens, 6144);
        } else {
            $maxTokens = max($maxTokens, 8192);
            $temperature = min($temperature, 0.35);
        }

        if ($projectCount >= 20) {
            $maxTokens = max($maxTokens, 8192);
        }
        if ($projectCount >= 80) {
            $maxTokens = max($maxTokens, 12288);
        }

        if ($workflowStyle === 'fast') {
            $temperature = max($temperature, 0.45);
            $maxTokens = min($maxTokens, 4096);
        } elseif ($workflowStyle === 'careful') {
            $temperature = min($temperature, 0.35);
            $maxTokens = max($maxTokens, 6144);
        }

        if ($riskLevel === 'safe') {
            $temperature = min($temperature, 0.30);
        } elseif ($riskLevel === 'aggressive') {
            $temperature = max($temperature, 0.65);
        }

        $draft['model_config'] = [
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        return $draft;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $adapters
     * @param array<int, array<string, mixed>> $skills
     * @return array<string, mixed>|null
     */
    private function buildAiDraft(array $input, array $template, array $models, array $adapters, array $skills): ?array
    {
        $model = $this->resolvePreferredModel($models, (int) ($input['model_id'] ?? 0));
        $modelCode = (string) ($model['model_code'] ?? '');
        if ($modelCode === '') {
            return null;
        }

        $skillPayload = array_map(static function (array $skill): array {
            return [
                'code' => (string) ($skill['code'] ?? ''),
                'name' => (string) ($skill['name'] ?? ''),
                'category' => (string) ($skill['category'] ?? ''),
                'is_dangerous' => (int) ($skill['is_dangerous'] ?? 0),
            ];
        }, $skills);

        $prompt = [
            'You configure Weline_Bot roles.',
            'Return a JSON object only (no markdown).',
            'Required fields: code,name,description,system_prompt,scenario_adapter_code,model_id,skills,permissions,model_config,icon,status.',
            'Rules:',
            '- skills must exist in available_skills.code',
            '- scenario_adapter_code must exist in available_adapters.code',
            '- model_id must be one of available_models.id (0 if unknown)',
            '- permissions is a string array, e.g. fs.read:/app/*',
            '- model_config contains at least temperature and max_tokens',
            '- status is enabled or disabled',
            '',
            'input=' . json_encode($input, JSON_UNESCAPED_UNICODE),
            'template=' . json_encode($template, JSON_UNESCAPED_UNICODE),
            'available_models=' . json_encode($models, JSON_UNESCAPED_UNICODE),
            'available_adapters=' . json_encode($adapters, JSON_UNESCAPED_UNICODE),
            'available_skills=' . json_encode($skillPayload, JSON_UNESCAPED_UNICODE),
        ];

        try {
            $result = $this->aiService->generate(
                prompt: implode("\n", $prompt),
                modelCode: $modelCode,
                scenarioCode: 'bot_agent',
                locale: 'en-US',
                params: ['temperature' => 0.2, 'max_tokens' => 1800]
            );

            return $this->extractJsonObject($result);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeDraft(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (in_array($key, ['skills', 'permissions'], true)) {
                if (is_array($value) && !empty($value)) {
                    $base[$key] = array_values(array_unique(array_map('strval', $value)));
                }
                continue;
            }

            if ($key === 'model_config') {
                if (is_array($value) && !empty($value)) {
                    $base[$key] = array_replace((array) ($base[$key] ?? []), $value);
                }
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $base[$key] = $trimmed;
                }
                continue;
            }

            if ($value !== null) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $adapters
     * @param array<string> $skillCodes
     * @return array<string, mixed>
     */
    private function sanitizeDraft(array $draft, array $template, array $models, array $adapters, array $skillCodes): array
    {
        $name = trim((string) ($draft['name'] ?? ''));
        if ($name === '') {
            $name = (string) ($template['name'] ?? 'Bot Assistant');
        }

        $code = $this->slugify((string) ($draft['code'] ?? ''));
        if ($code === '') {
            $code = $this->slugify((string) ($template['code'] ?? 'bot_role'));
        }

        $description = trim((string) ($draft['description'] ?? (string) ($template['description'] ?? '')));
        $systemPrompt = trim((string) ($draft['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = (string) ($template['system_prompt'] ?? '');
        }

        $adapterCode = trim((string) ($draft['scenario_adapter_code'] ?? ''));
        if (!$this->containsAdapter($adapterCode, $adapters)) {
            $fallbackAdapter = (string) ($template['scenario_adapter_code'] ?? '');
            $adapterCode = $this->containsAdapter($fallbackAdapter, $adapters) ? $fallbackAdapter : (string) ($adapters[0]['code'] ?? '');
        }

        $modelId = (int) ($draft['model_id'] ?? 0);
        if (!$this->containsModel($modelId, $models)) {
            $modelId = 0;
        }

        $skills = $this->filterSkills((array) ($draft['skills'] ?? []), $skillCodes);
        if (empty($skills)) {
            $skills = $this->filterSkills((array) ($template['skills'] ?? []), $skillCodes);
        }

        $permissions = $this->sanitizePermissions((array) ($draft['permissions'] ?? []));
        if (empty($permissions)) {
            $permissions = $this->sanitizePermissions((array) ($template['permissions'] ?? []));
        }
        if (empty($permissions)) {
            $permissions = $this->sanitizePermissions($this->inferPermissionsBySkills($skills));
        }

        $icon = trim((string) ($draft['icon'] ?? 'mdi-robot'));
        if ($icon === '') {
            $icon = 'mdi-robot';
        }

        $status = trim((string) ($draft['status'] ?? 'enabled'));
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            $status = 'enabled';
        }

        $modelConfig = $this->sanitizeModelConfig((array) ($draft['model_config'] ?? []), (array) ($template['model_config'] ?? []));

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'system_prompt' => $systemPrompt,
            'scenario_adapter_code' => $adapterCode,
            'model_id' => $modelId,
            'skills' => $skills,
            'permissions' => $permissions,
            'model_config' => $modelConfig,
            'status' => $status,
            'icon' => $icon,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $models
     * @return array<string, mixed>
     */
    private function resolvePreferredModel(array $models, int $preferredId): array
    {
        if ($preferredId > 0) {
            foreach ($models as $model) {
                if ((int) ($model['id'] ?? 0) === $preferredId) {
                    return $model;
                }
            }
        }

        foreach ($models as $model) {
            if ((int) ($model['is_default'] ?? 0) === 1) {
                return $model;
            }
        }

        return $models[0] ?? [];
    }

    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $templates
     * @return array<string, mixed>|null
     */
    private function findTemplate(string $templateCode, array $templates): ?array
    {
        foreach ($templates as $template) {
            if ((string) ($template['code'] ?? '') === $templateCode) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param array<string> $skills
     * @param array<string> $availableCodes
     * @return array<string>
     */
    private function filterSkills(array $skills, array $availableCodes): array
    {
        $allowedMap = array_fill_keys($availableCodes, true);
        $filtered = [];

        foreach ($skills as $skill) {
            $code = trim((string) $skill);
            if ($code !== '' && isset($allowedMap[$code])) {
                $filtered[] = $code;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param array<string> $permissions
     * @return array<string>
     */
    private function sanitizePermissions(array $permissions): array
    {
        $cleaned = [];

        foreach ($permissions as $permission) {
            $value = trim((string) $permission);
            if ($value === '' || strlen($value) > 220) {
                continue;
            }
            $cleaned[] = $value;
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * @param array<string> $skills
     * @return array<string>
     */
    private function inferPermissionsBySkills(array $skills): array
    {
        $permissions = [];

        foreach ($skills as $skillCode) {
            if (str_starts_with($skillCode, 'filesystem.read')) {
                $permissions[] = 'fs.read:/app/*';
            }
            if (str_starts_with($skillCode, 'filesystem.write')) {
                $permissions[] = 'fs.write:/var/*';
            }
            if (str_starts_with($skillCode, 'shell.execute')) {
                $permissions[] = 'shell.execute:*';
            }
            if (str_starts_with($skillCode, 'http.request')) {
                $permissions[] = 'http.request:*';
            }
            if (str_starts_with($skillCode, 'database.query')) {
                $permissions[] = 'db.read:*';
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * @param array<string, mixed> $modelConfig
     * @param array<string, mixed> $templateModelConfig
     * @return array<string, mixed>
     */
    private function sanitizeModelConfig(array $modelConfig, array $templateModelConfig): array
    {
        $config = array_replace([
            'temperature' => 0.4,
            'max_tokens' => 4096,
        ], $templateModelConfig, $modelConfig);

        $temperature = (float) ($config['temperature'] ?? 0.4);
        $temperature = max(0.0, min(2.0, $temperature));

        $maxTokens = (int) ($config['max_tokens'] ?? 4096);
        $maxTokens = max(128, min(65536, $maxTokens));

        return [
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
    }

    private function normalizeProfile(string $profile): string
    {
        $profile = trim(strtolower($profile));
        return in_array($profile, ['single_project', 'multi_project', 'enterprise'], true)
            ? $profile
            : 'multi_project';
    }

    private function normalizeWorkflowStyle(string $workflowStyle): string
    {
        $workflowStyle = trim(strtolower($workflowStyle));
        return in_array($workflowStyle, ['fast', 'balanced', 'careful'], true)
            ? $workflowStyle
            : 'balanced';
    }

    private function normalizeRiskLevel(string $riskLevel): string
    {
        $riskLevel = trim(strtolower($riskLevel));
        return in_array($riskLevel, ['safe', 'normal', 'aggressive'], true)
            ? $riskLevel
            : 'safe';
    }

    /**
     * @return array<string, mixed>
     */
    private function getProfileMeta(string $profile): array
    {
        return match ($profile) {
            'single_project' => [
                'name' => 'Single Project',
                'description' => 'Focused assistant for one project with low overhead coordination.',
                'description_suffix' => 'Optimized for single-project focus.',
                'prompt' => 'Work on one project at a time and keep context concise.',
                'temperature' => 0.45,
                'max_tokens' => 4096,
            ],
            'enterprise' => [
                'name' => 'Enterprise Governance',
                'description' => 'Governance-first assistant with predictable and auditable execution.',
                'description_suffix' => 'Optimized for governance and compliance.',
                'prompt' => 'Prioritize governance, approvals, and clear operational traceability.',
                'temperature' => 0.25,
                'max_tokens' => 8192,
            ],
            default => [
                'name' => 'Multi Project',
                'description' => 'Coordinator assistant for many parallel projects with priority routing.',
                'description_suffix' => 'Optimized for multi-project routing.',
                'prompt' => 'Manage multiple projects with queue-based priorities and clear status updates.',
                'temperature' => 0.35,
                'max_tokens' => 6144,
            ],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $models
     */
    private function containsModel(int $modelId, array $models): bool
    {
        if ($modelId <= 0) {
            return false;
        }

        foreach ($models as $model) {
            if ((int) ($model['id'] ?? 0) === $modelId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $adapters
     */
    private function containsAdapter(string $adapterCode, array $adapters): bool
    {
        if ($adapterCode === '') {
            return false;
        }

        foreach ($adapters as $adapter) {
            if ((string) ($adapter['code'] ?? '') === $adapterCode) {
                return true;
            }
        }

        return false;
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($normalized) && $normalized !== '') {
            $value = $normalized;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        if ($value === '') {
            return 'bot_role_' . date('His');
        }

        return substr($value, 0, 100);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonObject(string $raw): ?array
    {
        $content = trim($raw);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
