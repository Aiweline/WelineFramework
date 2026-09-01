<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Resumable;

use Weline\I18n\Service\TaglibLocalFormService;

/**
 * 冻结 local 标签一键批量翻译输入，并按字段构建可恢复步骤。
 */
class TaglibLocalBulkTranslationTaskProcessor
{
    public function __construct(private readonly TaglibLocalFormService $formService)
    {
    }

    /**
     * @param array<string, scalar|null> $fields
     * @return array<string, mixed>
     */
    public function freezeInput(
        string $modelName,
        int $recordId,
        array $fields,
        bool $retranslateAll,
        string $requestId,
    ): array {
        $modelName = trim($modelName);
        if ($modelName === '') {
            throw new \InvalidArgumentException((string)__('请设置local标签model属性！'));
        }
        if ($recordId <= 0) {
            throw new \InvalidArgumentException((string)__('请设置local标签id属性！'));
        }

        $requestId = trim($requestId);
        if ($requestId === '') {
            throw new \InvalidArgumentException((string)__('批量翻译请求标识无效'));
        }

        $steps = $this->formService->buildBulkTranslationSteps(
            $modelName,
            $recordId,
            $fields,
            $retranslateAll,
        );
        if ($steps === []) {
            throw new \InvalidArgumentException((string)__('没有可翻译的字段，请先填写文案'));
        }

        return [
            'model' => $modelName,
            'record_id' => $recordId,
            'retranslate_all' => $retranslateAll,
            'request_id' => $requestId,
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    public function translateStep(array $input, array $step): array
    {
        $field = trim((string)($step['field'] ?? ''));
        $value = trim((string)($step['value'] ?? ''));
        if ($field === '' || $value === '') {
            return [
                'success' => true,
                'message' => (string)__('字段为空，已跳过'),
                'data' => [
                    'status' => 'skipped_empty',
                    'translated' => 0,
                    'skipped' => 0,
                ],
            ];
        }

        return $this->formService->aiTranslate(
            (string)($input['model'] ?? ''),
            $field,
            (string)($input['record_id'] ?? '0'),
            $value,
            (bool)($input['retranslate_all'] ?? false),
        );
    }
}
