<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Resumable;

use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;

final class TaglibLocalBulkTranslationTaskHandler implements ResumableTaskStartHandlerInterface
{
    public const TYPE_CODE = 'i18n.taglib_local_bulk';

    public function __construct(private readonly TaglibLocalBulkTranslationTaskProcessor $processor)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);

        $fields = $input['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \InvalidArgumentException((string)__('I18n 后台操作参数必须是对象'));
        }

        $retranslateAll = ($input['retranslate_all'] ?? false) === true
            || ($input['retranslate_all'] ?? '') === '1'
            || ($input['retranslate'] ?? false) === true
            || ($input['retranslate'] ?? '') === '1';

        $frozen = $this->processor->freezeInput(
            trim((string)($input['model'] ?? '')),
            (int)($input['record_id'] ?? $input['id'] ?? 0),
            $fields,
            $retranslateAll,
            trim((string)($input['request_id'] ?? '')),
        );

        return new TaskStartRequest(
            input: $frozen,
            businessKey: self::TYPE_CODE
                . ':' . $owner->principal
                . ':' . $frozen['record_id']
                . ':' . $frozen['request_id'],
            policy: TaskPolicy::defaults(),
        );
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        if ($checkpoint?->cursor === 'bulk_completed') {
            return TaskResult::completed($checkpoint->state);
        }

        $context->heartbeat();
        $context->throwIfStopRequested();

        $steps = array_values((array)($input['steps'] ?? []));
        $total = count($steps);
        if ($total === 0) {
            return TaskResult::failed(
                'no_steps',
                (string)__('没有可翻译的字段，请先填写文案'),
                ['steps' => []],
            );
        }

        if ($checkpoint === null) {
            $context->saveCheckpoint('bulk_started', $this->state($input, [], 0));
            $context->emit('start', [
                'message' => (string)__('开始一键 AI 批量翻译'),
                'record_id' => (int)($input['record_id'] ?? 0),
                'total' => $total,
                'attempt' => $context->attempt(),
            ]);
        }

        $state = is_array($context->checkpoint()?->state) ? $context->checkpoint()->state : [];
        $results = is_array($state['results'] ?? null) ? $state['results'] : [];
        $nextIndex = max(0, (int)($state['next_index'] ?? 0));

        for ($index = $nextIndex; $index < $total; $index++) {
            $context->heartbeat();
            $context->throwIfStopRequested();

            $step = is_array($steps[$index] ?? null) ? $steps[$index] : [];
            $field = trim((string)($step['field'] ?? ''));
            $label = trim((string)($step['label'] ?? $field));
            $plannedStatus = trim((string)($step['status'] ?? 'pending'));

            $context->saveCheckpoint('before_field', $this->state($input, $results, $index, $field, $label));

            if ($plannedStatus === 'skipped_empty') {
                $results[$field] = ['status' => 'skipped_empty', 'translated' => 0, 'skipped' => 0];
                $context->saveCheckpoint('field_completed', $this->state($input, $results, $index + 1));
                $context->emit('progress', [
                    'message' => (string)__('字段 %{1} 为空，已跳过', [$label !== '' ? $label : $field]),
                    'field' => $field,
                    'label' => $label,
                    'status' => 'skipped_empty',
                    'completed' => $index + 1,
                    'total' => $total,
                ], 'i18n-taglib-local-bulk-progress');
                continue;
            }

            if ($plannedStatus === 'skipped_done') {
                $results[$field] = ['status' => 'skipped_done', 'translated' => 0, 'skipped' => 0];
                $context->saveCheckpoint('field_completed', $this->state($input, $results, $index + 1));
                $context->emit('progress', [
                    'message' => (string)__('字段 %{1} 已翻译，已跳过', [$label !== '' ? $label : $field]),
                    'field' => $field,
                    'label' => $label,
                    'status' => 'skipped_done',
                    'completed' => $index + 1,
                    'total' => $total,
                ], 'i18n-taglib-local-bulk-progress');
                continue;
            }

            $context->emit('progress', [
                'message' => (string)__('正在翻译字段 %{1}（%{2}/%{3}）', [$label !== '' ? $label : $field, $index + 1, $total]),
                'field' => $field,
                'label' => $label,
                'status' => 'running',
                'completed' => $index,
                'total' => $total,
            ], 'i18n-taglib-local-bulk-progress');

            try {
                $translateResult = $this->processor->translateStep($input, $step);
            } catch (\Throwable $throwable) {
                return $this->failed($context, $input, $field, $label, $results, $throwable);
            }

            if (($translateResult['success'] ?? false) !== true) {
                $message = trim((string)($translateResult['message'] ?? ''));
                return TaskResult::failed(
                    'translation_failed',
                    $message === '' ? (string)__('AI 批量翻译失败') : mb_strimwidth($message, 0, 1_500, '…'),
                    $this->state($input, $results, $index, $field, $label) + [
                        'failed_field' => $field,
                    ],
                );
            }

            $data = is_array($translateResult['data'] ?? null) ? $translateResult['data'] : [];
            $translated = (int)($data['translated'] ?? 0);
            $skipped = (int)($data['skipped'] ?? 0);
            $status = $translated > 0 ? 'translated' : 'skipped_done';
            $results[$field] = [
                'status' => $status,
                'translated' => $translated,
                'skipped' => $skipped,
                'message' => (string)($translateResult['message'] ?? ''),
            ];

            $context->saveCheckpoint('field_completed', $this->state($input, $results, $index + 1));
            $context->emit('progress', [
                'message' => (string)($translateResult['message'] ?? __('字段 %{1} 翻译完成', [$label !== '' ? $label : $field])),
                'field' => $field,
                'label' => $label,
                'status' => $status,
                'completed' => $index + 1,
                'total' => $total,
                'translated' => $translated,
                'skipped' => $skipped,
            ], 'i18n-taglib-local-bulk-progress');
        }

        $completed = $this->state($input, $results, $total) + [
            'message' => (string)__('AI 批量翻译完成'),
        ];
        $context->saveCheckpoint('bulk_completed', $completed);
        $context->emit('completed', [
            'message' => $completed['message'],
            'record_id' => (int)($input['record_id'] ?? 0),
            'results' => $results,
            'total' => $total,
        ]);

        return TaskResult::completed($completed);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $results @return array<string,mixed> */
    private function state(
        array $input,
        array $results = [],
        int $nextIndex = 0,
        string $currentField = '',
        string $currentLabel = '',
    ): array {
        return [
            'model' => (string)($input['model'] ?? ''),
            'record_id' => (int)($input['record_id'] ?? 0),
            'retranslate_all' => (bool)($input['retranslate_all'] ?? false),
            'request_id' => (string)($input['request_id'] ?? ''),
            'steps' => array_values((array)($input['steps'] ?? [])),
            'next_index' => $nextIndex,
            'current_field' => $currentField,
            'current_label' => $currentLabel,
            'results' => $results,
            'total' => count((array)($input['steps'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $results */
    private function failed(
        ResumableTaskContextInterface $context,
        array $input,
        string $field,
        string $label,
        array $results,
        \Throwable $throwable,
    ): TaskResult {
        $message = trim($throwable->getMessage());
        $message = $message === '' ? (string)__('AI 批量翻译失败') : mb_strimwidth($message, 0, 1_500, '…');
        $data = $this->state($input, $results, max(0, (int)($context->checkpoint()?->state['next_index'] ?? 0)), $field, $label) + [
            'failed_field' => $field,
            'error_code' => 'translation_failed',
        ];
        $context->saveCheckpoint('bulk_failed', $data);
        $context->emit('error', [
            'code' => 'translation_failed',
            'message' => $message,
            'field' => $field,
            'label' => $label,
        ]);

        return TaskResult::failed('translation_failed', $message, $data);
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('Local bulk translation requires a backend owner.');
        }
    }
}
