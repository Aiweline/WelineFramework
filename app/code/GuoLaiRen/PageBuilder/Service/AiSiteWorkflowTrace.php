<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * PageBuilder AI 建站流水线诊断日志（仅 w_log）。
 *
 * 开启：环境变量 PAGE_BUILDER_AI_SITE_TRACE=1
 * 完整提示词/合同：PAGE_BUILDER_AI_SITE_TRACE=verbose
 */
final class AiSiteWorkflowTrace
{
    private const CHANNEL = 'pagebuilder_ai_site_trace';

    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $raw = \getenv('PAGE_BUILDER_AI_SITE_TRACE');
        if ($raw === false || $raw === '') {
            self::$enabled = false;

            return false;
        }

        $normalized = \strtolower(\trim((string)$raw));
        self::$enabled = !\in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true);

        return self::$enabled;
    }

    public static function verbose(): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $raw = \strtolower(\trim((string)(\getenv('PAGE_BUILDER_AI_SITE_TRACE') ?: '')));

        return \in_array($raw, ['verbose', 'full', 'debug'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $event, array $context = []): void
    {
        if (!self::enabled() || !\function_exists('w_log_info')) {
            return;
        }

        \w_log_info('[AI Site Trace] ' . $event, \array_replace([
            'event' => $event,
            'ts' => \date('c'),
            'verbose' => self::verbose(),
        ], $context), self::CHANNEL);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function prompt(string $event, string $prompt, array $meta = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $clip = self::verbose() ? 200000 : 12000;
        $clipped = self::clipText($prompt, $clip);
        self::log($event, \array_replace($meta, [
            'prompt_chars' => $clipped['chars'],
            'prompt_truncated' => $clipped['truncated'],
            'prompt' => $clipped['text'],
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function json(string $event, array $payload, array $meta = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $encoded = (string)\json_encode(
            $payload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $clip = self::verbose() ? 400000 : 24000;
        $clipped = self::clipText($encoded, $clip);
        self::log($event, \array_replace($meta, [
            'json_chars' => $clipped['chars'],
            'json_truncated' => $clipped['truncated'],
            'payload' => $clipped['text'],
        ]));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, mixed> $meta
     */
    public static function taskList(string $event, array $tasks, array $meta = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $summaries = [];
        foreach ($tasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $summaries[] = [
                'index' => $index,
                'task_key' => (string)($task['task_key'] ?? $task['task_id'] ?? ''),
                'task_type' => (string)($task['task_type'] ?? $task['task_kind'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'region' => (string)($task['region'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'block_key' => (string)($task['block_key'] ?? ''),
                'label' => (string)($task['label'] ?? ''),
                'dependencies' => \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : ($task['depends_on'] ?? []),
            ];
        }

        self::json($event, ['tasks' => $summaries, 'task_count' => \count($summaries)], $meta);
    }

    /**
     * @return array{text: string, truncated: bool, chars: int}
     */
    private static function clipText(string $text, int $limit): array
    {
        $trimmed = \trim($text);
        if ($limit <= 0 || \strlen($trimmed) <= $limit) {
            return ['text' => $trimmed, 'truncated' => false, 'chars' => \strlen($trimmed)];
        }

        return ['text' => \substr($trimmed, 0, $limit), 'truncated' => true, 'chars' => \strlen($trimmed)];
    }
}
