<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * SSE payload contract for PageBuilder AI workspace streams.
 *
 * Build execution has one public progress truth source: plan_json block node status
 * plus the derived build task summary emitted for display.
 */
class AiSiteSsePayloadNormalizer
{
    public const EMITTED_UNSUPPORTED_ALIASES = false;

    /** @var array<string, string> */
    private const ALIAS_TO_AUTHORITATIVE = [];

    /** @var array<string, list<string>> */
    private const AUTHORITATIVE_TO_ALIASES = [];

    /**
     * @return list<string>
     */
    public static function authoritativeEventNames(): array
    {
        return [
            'start',
            'progress',
            'chunk',
            'info',
            'warning',
            'done',
            'error',
            'plan_json_block_completed',
            'plan_json_block_failed',
            'page_generated',
            'shared_component_generated',
            'asset_generation_started',
            'asset_manifest_updated',
            'asset_generation_progress',
            'asset_generation_done',
            'asset_generation_failed',
            'asset_generation_skipped',
            'block_partial_patch_applied',
            'block_partial_patch_failed',
            'environment_ready',
            'state',
            'plan_state',
            'task_progress',
            'task_completed',
            'task_failed',
            'log',
            'ai_chunk',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        if (isset($payload['queue_status']) && \is_string($payload['queue_status'])) {
            $payload['queue_status'] = \strtolower(\trim($payload['queue_status']));
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    public static function authoritativePayloadFields(): array
    {
        return [
            'operation',
            'message',
            'queue_status',
            'plan_json_execution_summary',
            'progress_kind',
            'progress_percent',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function aliasToAuthoritativeMap(): array
    {
        return self::ALIAS_TO_AUTHORITATIVE;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function authoritativeToAliasesMap(): array
    {
        return self::AUTHORITATIVE_TO_ALIASES;
    }
}
