<?php

declare(strict_types=1);

namespace Weline\Cms\Service\Resumable;

use Weline\Cms\Service\PageTranslationTaskProcessor;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;

final class PageTranslationTaskHandler implements ResumableTaskStartHandlerInterface
{
    public const TYPE_CODE = 'cms.page_translation';

    public function __construct(private readonly PageTranslationTaskProcessor $processor)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);
        $pageId = (int)($input['page_id'] ?? 0);
        $requestId = trim((string)($input['request_id'] ?? ''));
        $frozen = $this->processor->freezeInput($pageId, $requestId);
        if ($owner->websiteId !== null && $owner->websiteId !== (int)$frozen['website_id']) {
            throw new ResumableTaskAccessDeniedException('CMS page translation website scope mismatch.');
        }

        return new TaskStartRequest(
            input: $frozen,
            businessKey: self::TYPE_CODE . ':' . $owner->principal . ':' . $frozen['request_id'],
            policy: TaskPolicy::defaults(),
        );
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        if ($checkpoint?->cursor === 'translation_completed') {
            return TaskResult::completed($checkpoint->state);
        }

        $context->heartbeat();
        $context->throwIfStopRequested();
        if ($checkpoint === null) {
            $context->saveCheckpoint('translation_started', $this->state($input));
            $context->emit('start', [
                'message' => (string)__('开始补全 CMS 页面缺失语言'),
                'page_id' => $input['page_id'],
                'source_locale' => $input['source_locale'],
                'target_locales' => $input['target_locales'],
                'attempt' => $context->attempt(),
            ]);
        }

        $state = is_array($context->checkpoint()?->state) ? $context->checkpoint()->state : [];
        $results = is_array($state['results'] ?? null) ? $state['results'] : [];
        $nextIndex = max(0, (int)($state['next_index'] ?? 0));
        $targets = array_values(array_map('strval', (array)($input['target_locales'] ?? [])));
        $total = count($targets);

        for ($index = $nextIndex; $index < $total; $index++) {
            $context->heartbeat();
            $context->throwIfStopRequested();
            $locale = $targets[$index];
            $effectKey = 'translate.' . $locale;
            $context->saveCheckpoint('before_target', $this->state($input, $results, $index, $locale));
            $effect = $context->reserveEffect($effectKey);

            if ($effect->alreadyExisted) {
                $title = trim((string)($effect->result['title'] ?? ''));
                if ($effect->state !== TaskEffectState::APPLIED || $title === '') {
                    return $this->recoveryUnsafe($context, $input, $effectKey, $locale, $results);
                }
            } else {
                try {
                    $title = $this->processor->translateTarget(
                        $input,
                        $locale,
                        $effect->externalIdempotencyKey(),
                    );
                } catch (\Throwable $throwable) {
                    return $this->failed($context, $input, $locale, $results, $throwable);
                }
                $context->completeEffect($effectKey, ['locale' => $locale, 'title' => $title]);
            }

            try {
                $status = $this->processor->persistTarget($input, $locale, $title);
            } catch (\Throwable $throwable) {
                return $this->failed($context, $input, $locale, $results, $throwable);
            }
            $results[$locale] = [
                'status' => $status,
                'title' => $status === 'saved' ? $title : '',
            ];
            $context->saveCheckpoint(
                'target_completed',
                $this->state($input, $results, $index + 1),
            );
            $context->emit('progress', [
                'message' => (string)__('CMS 页面语言翻译进度：%{1}/%{2}', [$index + 1, $total]),
                'page_id' => $input['page_id'],
                'locale' => $locale,
                'status' => $status,
                'completed' => $index + 1,
                'total' => $total,
            ], 'cms-page-translation-progress');
        }

        $completed = $this->state($input, $results, $total) + [
            'message' => (string)__('CMS 页面缺失语言已处理完成。'),
        ];
        $context->saveCheckpoint('translation_completed', $completed);
        $context->emit('completed', [
            'message' => $completed['message'],
            'page_id' => $input['page_id'],
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
        string $currentLocale = '',
    ): array {
        return [
            'page_id' => (int)$input['page_id'],
            'website_id' => (int)$input['website_id'],
            'source_locale' => (string)$input['source_locale'],
            'target_locales' => array_values((array)$input['target_locales']),
            'next_index' => $nextIndex,
            'current_locale' => $currentLocale,
            'results' => $results,
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $results */
    private function failed(
        ResumableTaskContextInterface $context,
        array $input,
        string $locale,
        array $results,
        \Throwable $throwable,
    ): TaskResult {
        $message = trim($throwable->getMessage());
        $message = $message === '' ? (string)__('CMS 页面语言翻译失败。') : mb_strimwidth($message, 0, 1_500, '…');
        $data = $this->state($input, $results) + [
            'failed_locale' => $locale,
            'error_code' => 'translation_failed',
        ];
        $context->saveCheckpoint('translation_failed', $data);
        $context->emit('error', [
            'code' => 'translation_failed',
            'message' => $message,
            'page_id' => $input['page_id'],
            'locale' => $locale,
        ]);

        return TaskResult::failed('translation_failed', $message, $data);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $results */
    private function recoveryUnsafe(
        ResumableTaskContextInterface $context,
        array $input,
        string $effectKey,
        string $locale,
        array $results,
    ): TaskResult {
        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            $this->state($input, $results) + [
                'effect_key' => $effectKey,
                'failed_locale' => $locale,
                'attempt' => $context->attempt(),
            ],
            'external_effect_unknown',
            (string)__('翻译服务调用在确认前中断，请重新发起缺失语言翻译。'),
        );
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('CMS page translation requires a backend owner.');
        }
    }
}
