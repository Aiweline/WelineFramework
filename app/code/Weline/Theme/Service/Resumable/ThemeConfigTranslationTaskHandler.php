<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

use Weline\Ai\Api\AiRuntimeInterface;
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

/**
 * Detached AI translation for Theme editor configuration values.
 *
 * The only external effect is the provider call. Its parsed translation
 * result is ledgered before terminal completion, so a reconnect only
 * subscribes/replays and never starts a second request-bound generation.
 */
final class ThemeConfigTranslationTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'theme.config_translation';
    private const EFFECT_KEY = 'ai_translation';
    private const MAX_SOURCE_BYTES = 65_536;
    private const MAX_RESPONSE_BYTES = 262_144;
    private const MAX_TARGET_LOCALES = 30;

    public function __construct(private readonly AiRuntimeInterface $aiRuntime)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);
        $frozen = $this->freezeInput($input, $this->ownerId($owner));

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
            return TaskResult::completed($this->completedData($checkpoint->state));
        }

        $context->heartbeat();
        $context->throwIfStopRequested();
        if ($checkpoint === null) {
            $context->saveCheckpoint('translation_started', $this->state($input));
            $context->emit('start', [
                'message' => (string)__('开始翻译主题配置文案'),
                'source_locale' => $input['source_locale'],
                'target_locales' => $input['target_locales'],
                'field_key' => $input['field_key'],
                'attempt' => $context->attempt(),
            ]);
        }

        $state = is_array($context->checkpoint()?->state) ? $context->checkpoint()->state : [];
        $translations = is_array($state['translations'] ?? null) ? $state['translations'] : [];
        $context->saveCheckpoint('before_translation', $this->state($input, $translations, self::EFFECT_KEY));
        $effect = $context->reserveEffect(self::EFFECT_KEY);
        if ($effect->alreadyExisted) {
            if ($effect->state !== TaskEffectState::APPLIED || !$this->hasTranslations($effect->result, $input['target_locales'])) {
                return $this->recoveryUnsafe($context, $input);
            }
            $translations = is_array($effect->result['translations'] ?? null) ? $effect->result['translations'] : [];
        } else {
            try {
                $translations = $this->generateTranslations(
                    $input,
                    $effect->externalIdempotencyKey(),
                    (int)$input['actor_id'],
                );
            } catch (\Throwable $throwable) {
                return $this->failed($context, $input, $throwable);
            }
            $context->completeEffect(self::EFFECT_KEY, ['translations' => $translations]);
        }

        $completed = $this->completedData($this->state($input, $translations));
        $context->saveCheckpoint('translation_completed', $completed);
        $context->emit('progress', [
            'message' => (string)__('主题配置 AI 翻译已生成'),
            'source_locale' => $input['source_locale'],
            'target_locales' => $input['target_locales'],
            'translated_count' => count($translations),
            'field_key' => $input['field_key'],
        ]);

        return TaskResult::completed($completed);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function freezeInput(array $input, int $actorId): array
    {
        $requestId = trim((string)($input['request_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid Theme translation request_id.');
        }

        $sourceText = trim((string)($input['source_text'] ?? ''));
        if ($sourceText === '') {
            throw new \InvalidArgumentException((string)__('缺少源文案。'));
        }
        if (strlen($sourceText) > self::MAX_SOURCE_BYTES) {
            throw new \InvalidArgumentException('Theme translation source text is too large.');
        }

        $sourceLocale = $this->normalizeLocale((string)($input['source_locale'] ?? 'zh_Hans_CN'));
        if ($sourceLocale === '') {
            throw new \InvalidArgumentException((string)__('源语言无效。'));
        }
        $targetLocales = $this->normalizeLocaleList($input['target_locales'] ?? [], $sourceLocale);
        if ($targetLocales === []) {
            throw new \InvalidArgumentException((string)__('缺少目标语言。'));
        }

        return [
            'request_id' => $requestId,
            'source_text' => $sourceText,
            'source_locale' => $sourceLocale,
            'target_locales' => $targetLocales,
            'field_key' => $this->boundedString($input['field_key'] ?? '', 255, 'field_key'),
            'layout_id' => max(0, (int)($input['layout_id'] ?? 0)),
            'layout_type' => $this->boundedString($input['layout_type'] ?? '', 64, 'layout_type'),
            'layout_option' => $this->boundedString($input['layout_option'] ?? '', 128, 'layout_option'),
            'context' => $this->normalizeContext($input['context'] ?? ''),
            'model_code' => $this->boundedString($input['model_code'] ?? '', 128, 'model_code'),
            // Never trust an API actor_id; capture the authenticated owner at
            // task creation so recovery has no Request/Session dependency.
            'actor_id' => max(0, $actorId),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function generateTranslations(array $input, string $idempotencyKey, int $actorId): array
    {
        $response = $this->aiRuntime->generate(
            $this->prompt($input),
            $input['model_code'] !== '' ? $input['model_code'] : null,
            'theme',
            null,
            [
                'operation' => 'config_i18n_translate',
                'source_locale' => $input['source_locale'],
                'target_locales' => $input['target_locales'],
                'field_key' => $input['field_key'],
                'layout_id' => $input['layout_id'],
                'layout_type' => $input['layout_type'],
                'layout_option' => $input['layout_option'],
                'context' => $input['context'],
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'is_backend' => true,
                'idempotency_key' => $idempotencyKey,
                'resumable_idempotency_key' => $idempotencyKey,
            ],
            $actorId > 0 ? $actorId : null,
            true,
        );
        if (strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('Theme translation AI response is too large.');
        }

        return $this->parseTranslations($response, $input['target_locales']);
    }

    /** @param array<string,mixed> $input @param array<string,string> $translations @return array<string,mixed> */
    private function state(array $input, array $translations = [], ?string $effectKey = null): array
    {
        $state = [
            'source_locale' => $input['source_locale'],
            'target_locales' => $input['target_locales'],
            'field_key' => $input['field_key'],
            'layout_id' => $input['layout_id'],
            'layout_type' => $input['layout_type'],
            'layout_option' => $input['layout_option'],
            'context' => $input['context'],
            'translations' => $translations,
        ];
        if ($effectKey !== null) {
            $state['effect_key'] = $effectKey;
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function completedData(array $state): array
    {
        $translations = is_array($state['translations'] ?? null) ? $state['translations'] : [];
        return [
            'source_locale' => (string)($state['source_locale'] ?? ''),
            'target_locales' => is_array($state['target_locales'] ?? null) ? $state['target_locales'] : [],
            'translations' => $translations,
            'translated_count' => count($translations),
            'field_key' => (string)($state['field_key'] ?? ''),
            'layout_id' => (int)($state['layout_id'] ?? 0),
            'layout_type' => (string)($state['layout_type'] ?? ''),
            'layout_option' => (string)($state['layout_option'] ?? ''),
            'context' => (string)($state['context'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $input */
    private function recoveryUnsafe(ResumableTaskContextInterface $context, array $input): TaskResult
    {
        $context->markEffectUnknown(self::EFFECT_KEY);
        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            [
                'effect_key' => self::EFFECT_KEY,
                'field_key' => $input['field_key'],
                'target_locales' => $input['target_locales'],
                'attempt' => $context->attempt(),
            ],
            'external_effect_unknown',
            (string)__('主题配置 AI 翻译在确认前中断，无法安全恢复。'),
        );
    }

    /** @param array<string,mixed> $input */
    private function failed(ResumableTaskContextInterface $context, array $input, \Throwable $throwable): TaskResult
    {
        $message = trim($throwable->getMessage());
        $message = $message === '' ? (string)__('主题配置 AI 翻译失败。') : mb_strimwidth($message, 0, 1_500, '…');
        $context->saveCheckpoint('translation_failed', $this->state($input) + ['error_code' => 'translation_failed']);
        $context->emit('error', ['code' => 'translation_failed', 'message' => $message, 'field_key' => $input['field_key']]);
        return TaskResult::failed('translation_failed', $message, ['field_key' => $input['field_key']]);
    }

    /** @param list<string> $targetLocales */
    private function hasTranslations(array $result, array $targetLocales): bool
    {
        $translations = $result['translations'] ?? null;
        if (!is_array($translations) || $translations === []) {
            return false;
        }
        foreach ($translations as $locale => $translation) {
            if (!in_array((string)$locale, $targetLocales, true) || !is_string($translation)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $input */
    private function prompt(array $input): string
    {
        $targetJson = json_encode($input['target_locales'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $fieldLine = $input['field_key'] !== '' ? "字段路径：{$input['field_key']}\n" : '';
        $layoutLine = $input['layout_type'] !== '' ? "布局类型：{$input['layout_type']}\n" : '';
        $optionLine = $input['layout_option'] !== '' ? "布局选项：{$input['layout_option']}\n" : '';
        $contextLine = $input['context'] !== '' ? "业务上下文：{$input['context']}\n" : '';

        return "你是 Weline 主题可视化编辑器的配置翻译助手。\n"
            . "请把源文案翻译成目标语言，并且只返回一个 JSON 对象。\n"
            . "源语言：{$input['source_locale']}\n"
            . "目标语言：{$targetJson}\n"
            . $fieldLine
            . $layoutLine
            . $optionLine
            . $contextLine
            . "规则：\n"
            . "1. JSON key 必须严格等于每个目标语言 code。\n"
            . "2. JSON value 必须是翻译后的字符串。\n"
            . "3. 保留 HTML 标签、属性、URL、id、class、数字、变量占位符、模板表达式和配置 token。\n"
            . "4. 只翻译用户可读文本，不输出解释、Markdown 或代码块。\n"
            . "源文案：\n<<<SOURCE\n{$input['source_text']}\nSOURCE\n";
    }

    /** @param list<string> $targetLocales @return array<string,string> */
    private function parseTranslations(string $response, array $targetLocales): array
    {
        $text = trim($response);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('AI 翻译未返回有效 JSON。'));
        }

        $translations = [];
        foreach ($targetLocales as $locale) {
            if (array_key_exists($locale, $decoded) && is_scalar($decoded[$locale])) {
                $translations[$locale] = (string)$decoded[$locale];
            }
        }
        if ($translations === []) {
            throw new \RuntimeException((string)__('AI 翻译未返回目标语言结果。'));
        }
        return $translations;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,31}$/', $locale) === 1 ? $locale : '';
    }

    /** @return list<string> */
    private function normalizeLocaleList(mixed $value, string $sourceLocale): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', trim($value));
        }
        if (!is_array($value)) {
            return [];
        }
        $locales = [];
        foreach ($value as $locale) {
            $normalized = is_scalar($locale) ? $this->normalizeLocale((string)$locale) : '';
            if ($normalized !== '' && $normalized !== $sourceLocale) {
                $locales[$normalized] = $normalized;
            }
        }
        if (count($locales) > self::MAX_TARGET_LOCALES) {
            throw new \InvalidArgumentException('Too many Theme translation target locales.');
        }
        return array_values($locales);
    }

    private function normalizeContext(mixed $value): string
    {
        $context = trim((string)$value);
        return in_array($context, ['layout_config', 'widget_config'], true) ? $context : '';
    }

    private function boundedString(mixed $value, int $maxLength, string $field): string
    {
        $value = trim((string)$value);
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("Theme translation {$field} is too large.");
        }
        return $value;
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('Theme translation tasks require a backend owner.');
        }
    }

    private function ownerId(TaskOwner $owner): int
    {
        $id = substr($owner->principal, strlen('backend:'));
        return ctype_digit($id) ? (int)$id : 0;
    }
}
