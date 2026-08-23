<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Compatibility Hook adapter.
 *
 * Readiness is session-bound to the MCP process and is therefore enforced by
 * ToolService, not guessed from a separate Hook process. This adapter only emits
 * the concise entry instruction for hosts that still invoke the legacy guard.
 */
final class ProjectRoutingGuard
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function handle(array $payload): ?array
    {
        $event = strtolower(str_replace(['_', ' '], '-', trim((string) ($payload['hook_event_name'] ?? ''))));
        if (!in_array($event, ['userpromptsubmit', 'user-prompt-submit'], true)) {
            return null;
        }

        return [
            'hookSpecificOutput' => [
                'hookEventName' => 'UserPromptSubmit',
                'additionalContext' => 'Before project development, call prepare_project and continue only with status=ready. Carry its session-bound readiness_id into resolve_task_context and every guarded MCP tool.',
            ],
        ];
    }
}
