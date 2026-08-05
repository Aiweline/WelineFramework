<?php

declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\AdminControllerBridge;

/**
 * First-class AI style/skill backend query ops for binquery / Frontend worker.
 * Delegates to existing Backend controllers via AdminControllerBridge so JSON
 * bodies are returned instead of ResponseTerminateException → Internal server error.
 */
final class AiStyleSkillQuerySupport
{
    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $operation, array $params): mixed
    {
        return match ($operation) {
            'styleCatalog' => $this->invoke('Style', ['postCatalog', 'getCatalog'], $params),
            'styleSave' => $this->invoke('Style', ['postSave'], $params),
            'styleDelete' => $this->invoke('Style', ['postDelete'], $params),
            'styleCloneBuiltin' => $this->invoke('Style', ['postCloneBuiltin'], $params),
            'styleBindAdapter' => $this->invoke('Style', ['postBindAdapterStyle'], $params),
            'styleUnbindAdapter' => $this->invoke('Style', ['postUnbindAdapterStyle'], $params),
            'skillCatalog' => $this->invoke('Skill', ['postCatalog', 'getCatalog'], $params),
            'skillSave' => $this->invoke('Skill', ['postSave'], $params),
            'skillImportUrl' => $this->invoke('Skill', ['postImportUrl'], $params),
            'skillBindAdapter' => $this->invoke('Skill', ['postBindAdapterSkill'], $params),
            'skillUnbindAdapter' => $this->invoke('Skill', ['postUnbindAdapterSkill'], $params),
            default => throw new \InvalidArgumentException('Unsupported AI style/skill operation: ' . $operation),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOperationDescriptors(): array
    {
        $read = static fn(string $name, string $summary, array $params): array => [
            'name' => $name,
            'frontend' => true,
            'mode' => 'read',
            'graph' => false,
            'cost' => 1,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
        $write = static fn(string $name, string $summary, array $params, int $cost = 2): array => [
            'name' => $name,
            'frontend' => true,
            'mode' => 'write',
            'graph' => false,
            'cost' => $cost,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];

        $adapterCode = ['adapter_code' => ['type' => 'string', 'required' => false, 'max_length' => 128]];
        $includeInactive = ['include_inactive' => ['type' => 'bool', 'required' => false]];
        $code = ['code' => ['type' => 'string', 'required' => true, 'max_length' => 128]];

        return [
            $read('styleCatalog', 'List AI style catalog for an adapter', $adapterCode + $includeInactive + [
                'temporary_style_codes' => ['type' => 'array', 'required' => false],
                'selected_style_codes' => ['type' => 'array', 'required' => false],
                'include_disabled' => ['type' => 'bool', 'required' => false],
            ]),
            $write('styleSave', 'Save a custom AI style', [
                'code' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                'name' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                'description' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                'cta_style' => ['type' => 'string', 'required' => false],
                'supplemental_prompt' => ['type' => 'string', 'required' => false],
                'industry_tags' => ['type' => 'array', 'required' => false],
                'match_keywords' => ['type' => 'array', 'required' => false],
                'visual_keywords' => ['type' => 'array', 'required' => false],
                'color_system' => ['type' => 'map', 'required' => false],
                'layout_patterns' => ['type' => 'array', 'required' => false],
                'image_strategy' => ['type' => 'array', 'required' => false],
                'forbidden_patterns' => ['type' => 'array', 'required' => false],
                'block_rules' => ['type' => 'map', 'required' => false],
                'qa_rules' => ['type' => 'array', 'required' => false],
                'example_refs' => ['type' => 'map', 'required' => false],
            ]),
            $write('styleDelete', 'Delete a custom AI style', $code),
            $write('styleCloneBuiltin', 'Clone a builtin/module AI style', $code),
            $write('styleBindAdapter', 'Bind a style to an adapter', [
                'adapter_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                'style_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
            ]),
            $write('styleUnbindAdapter', 'Unbind a style from an adapter', [
                'adapter_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                'style_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
            ]),
            $read('skillCatalog', 'List AI skill catalog for an adapter', $adapterCode + $includeInactive + [
                'temporary_skill_codes' => ['type' => 'array', 'required' => false],
                'selected_skill_codes' => ['type' => 'array', 'required' => false],
            ]),
            $write('skillSave', 'Save or update an AI skill', [
                'code' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                'name' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                'description' => ['type' => 'string', 'required' => false],
                'body' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                'version' => ['type' => 'string', 'required' => false, 'max_length' => 64],
            ]),
            $write('skillImportUrl', 'Import an AI skill from URL', [
                'url' => ['type' => 'string', 'required' => true, 'max_length' => 2048],
            ]),
            $write('skillBindAdapter', 'Bind a skill to an adapter', [
                'adapter_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                'skill_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
            ]),
            $write('skillUnbindAdapter', 'Unbind a skill from an adapter', [
                'adapter_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                'skill_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
            ]),
        ];
    }

    public function backendAclSourceId(string $operation): string
    {
        return match ($operation) {
            'styleCatalog' => 'Weline_Ai::ai_style_view',
            'styleSave' => 'Weline_Ai::ai_style_save',
            'styleDelete' => 'Weline_Ai::ai_style_delete',
            'styleCloneBuiltin' => 'Weline_Ai::ai_style_clone',
            'styleBindAdapter', 'styleUnbindAdapter' => 'Weline_Ai::ai_adapter_style_manage',
            'skillCatalog' => 'Weline_Ai::ai_skill_view',
            'skillSave' => 'Weline_Ai::ai_skill_save',
            'skillImportUrl' => 'Weline_Ai::ai_skill_import',
            'skillBindAdapter', 'skillUnbindAdapter' => 'Weline_Ai::ai_adapter_skill_manage',
            default => throw new \LogicException('Backend ACL is not mapped for AI style/skill operation: ' . $operation),
        };
    }

    /**
     * @param list<string> $methodCandidates
     * @param array<string, mixed> $params
     */
    private function invoke(string $controllerShortName, array $methodCandidates, array $params): mixed
    {
        $class = 'Weline\\Ai\\Controller\\Backend\\' . $controllerShortName;
        $bodyParams = $this->normalizeBodyParams($params);

        return AdminControllerBridge::invoke(
            $class,
            $methodCandidates,
            [],
            $bodyParams,
            'POST',
            ''
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeBodyParams(array $params): array
    {
        // Frontend may wrap write payloads under "payload".
        if (isset($params['payload']) && is_array($params['payload'])) {
            $params = array_merge($params['payload'], $params);
            unset($params['payload']);
        }

        return $params;
    }
}
