<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Ai\AiJob;

/**
 * Resolves a Product AI task to its AI scenario and concrete model.
 *
 * Copy and image tasks are hard-separated: an image job never falls back to a
 * text2text model, and an explicit model override is validated directly instead
 * of relying on AiService's scenario fallback chain.
 */
final class ProductAiModelResolver
{
    /**
     * Modality literals mirror AiModel::PRIMARY_MODALITY_*; declared without
     * referencing the Ai classes so Weline_Ai stays an optional dependency.
     *
     * @var array<string, array{scenario_code:string,primary_modality:string}>
     */
    private const SCENARIOS = [
        AiJob::TASK_COPY => [
            'scenario_code' => 'product_copy_optimize',
            'primary_modality' => 'text2text',
        ],
        AiJob::TASK_IMAGE => [
            'scenario_code' => 'product_image_optimize',
            'primary_modality' => 'text2image',
        ],
    ];

    /** @return list<array{task_type:string,scenario_code:string,primary_modality:string}> */
    public function supportedTasks(): array
    {
        $tasks = [];
        foreach (self::SCENARIOS as $taskType => $definition) {
            $tasks[] = [
                'task_type' => $taskType,
                'scenario_code' => $definition['scenario_code'],
                'primary_modality' => $definition['primary_modality'],
            ];
        }

        return $tasks;
    }

    /**
     * @return array{
     *   task_type:string,
     *   scenario_code:string,
     *   primary_modality:string,
     *   model_code:string,
     *   model:array<string,mixed>
     * }
     */
    public function resolve(string $taskType, ?string $modelCode = null): array
    {
        $taskType = \strtolower(\trim($taskType));
        $definition = self::SCENARIOS[$taskType] ?? null;
        if ($definition === null) {
            throw new ProductAiException(
                'product_ai_task_unsupported',
                (string)__('不支持的 AI 优化任务类型'),
                422,
                ['task_type' => $taskType],
            );
        }
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            throw new ProductAiException(
                'product_ai_ai_unavailable',
                (string)__('AI 模块未安装或未启用'),
                503,
                ['scenario_code' => $definition['scenario_code']],
            );
        }

        $scenarioCode = $definition['scenario_code'];
        $modality = $definition['primary_modality'];
        $requested = \trim((string)$modelCode);

        if ($requested !== '') {
            $model = $this->loadActiveModel($requested);
            if ($model === null) {
                throw new ProductAiException(
                    'product_ai_model_unbound',
                    (string)__('指定的 AI 模型不存在或未启用'),
                    422,
                    [
                        'scenario_code' => $scenarioCode,
                        'model_code' => $requested,
                        'reason' => 'model_inactive_or_missing',
                    ],
                );
            }
            if (!$model->supportsPrimaryModality($modality)) {
                throw new ProductAiException(
                    'product_ai_model_unbound',
                    (string)__('指定的 AI 模型模态与任务不匹配'),
                    422,
                    [
                        'scenario_code' => $scenarioCode,
                        'model_code' => $requested,
                        'required_modality' => $modality,
                        'actual_modality' => $model->getPrimaryModality(),
                        'reason' => 'modality_mismatch',
                    ],
                );
            }

            return [
                'task_type' => $taskType,
                'scenario_code' => $scenarioCode,
                'primary_modality' => $modality,
                'model_code' => $requested,
                'model' => $this->modelSummary($model),
            ];
        }

        $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
        $readiness = $aiService->inspectScenarioReadiness($scenarioCode, $modality);
        $resolvedModel = \is_array($readiness['model'] ?? null) ? $readiness['model'] : [];
        $resolvedCode = \trim((string)($resolvedModel['model_code'] ?? ''));
        if (empty($readiness['ready']) || $resolvedCode === '') {
            throw new ProductAiException(
                'product_ai_model_unbound',
                (string)__('AI 场景未绑定可用模型'),
                422,
                [
                    'scenario_code' => $scenarioCode,
                    'required_modality' => $modality,
                    'reason' => (string)($readiness['code'] ?? 'MODEL_UNAVAILABLE'),
                ],
            );
        }

        return [
            'task_type' => $taskType,
            'scenario_code' => $scenarioCode,
            'primary_modality' => $modality,
            'model_code' => $resolvedCode,
            'model' => $resolvedModel,
        ];
    }

    private function loadActiveModel(string $modelCode): ?object
    {
        if (!\class_exists(\Weline\Ai\Model\AiModel::class)) {
            return null;
        }
        $model = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class)
            ->reset()
            ->where(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->where(\Weline\Ai\Model\AiModel::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /** @return array<string,mixed> */
    private function modelSummary(object $model): array
    {
        return [
            'id' => (int)$model->getId(),
            'model_code' => (string)$model->getModelCode(),
            'name' => (string)$model->getName(),
            'supplier' => (string)$model->getSupplier(),
            'primary_modality' => (string)$model->getPrimaryModality(),
        ];
    }
}
