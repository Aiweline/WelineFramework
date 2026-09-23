<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Maps Cursor Agent hook stdin JSON onto learningctl Collector payloads.
 */
final class CursorLearningHookAdapter
{
    /**
     * @return array{event:string,payload:array<string,mixed>,cursor_response:array<string,mixed>}
     */
    public static function adapt(string $fallbackEvent, array $raw): array
    {
        $hookName = strtolower(trim((string) ($raw['hook_event_name'] ?? $fallbackEvent)));
        $event = self::mapEvent($hookName !== '' ? $hookName : $fallbackEvent);
        $sessionId = trim((string) ($raw['session_id'] ?? $raw['conversation_id'] ?? ''));
        $roots = is_array($raw['workspace_roots'] ?? null) ? $raw['workspace_roots'] : [];
        $cwd = '';
        foreach ($roots as $root) {
            $candidate = trim((string) $root);
            if ($candidate !== '') {
                $cwd = $candidate;
                break;
            }
        }
        if ($cwd === '') {
            $cwd = getcwd() ?: '';
        }

        $payload = [
            'session_id' => $sessionId,
            'cwd' => $cwd,
            'hook_event_name' => $event,
            'model' => (string) ($raw['model'] ?? $raw['model_id'] ?? ''),
            'turn_id' => (string) ($raw['generation_id'] ?? ''),
            'transcript_path' => (string) ($raw['transcript_path'] ?? ''),
            'observed_at' => Clock::now(),
            'host' => 'cursor',
            'composer_mode' => (string) ($raw['composer_mode'] ?? ''),
            'is_background_agent' => !empty($raw['is_background_agent']),
        ];

        if (isset($raw['prompt']) && is_string($raw['prompt']) && trim($raw['prompt']) !== '') {
            $payload['prompt'] = trim($raw['prompt']);
        }
        if (isset($raw['user_prompt']) && is_string($raw['user_prompt']) && trim($raw['user_prompt']) !== '') {
            $payload['user_prompt'] = trim($raw['user_prompt']);
        }
        if (isset($raw['tool_name'])) {
            $payload['tool_name'] = (string) $raw['tool_name'];
        }
        if (array_key_exists('tool_input', $raw)) {
            $payload['tool_input'] = $raw['tool_input'];
        } elseif (array_key_exists('arguments', $raw)) {
            $payload['tool_input'] = $raw['arguments'];
        }
        if (array_key_exists('tool_output', $raw)) {
            $payload['tool_result'] = $raw['tool_output'];
        } elseif (array_key_exists('result', $raw)) {
            $payload['tool_result'] = $raw['result'];
        } elseif (array_key_exists('tool_response', $raw)) {
            $payload['tool_result'] = $raw['tool_response'];
        }
        if (isset($raw['status'])) {
            $payload['outcome'] = (string) $raw['status'];
        }
        if (isset($raw['file_path']) && is_string($raw['file_path']) && $raw['file_path'] !== '') {
            $payload['tool_input'] = is_array($payload['tool_input'] ?? null)
                ? $payload['tool_input']
                : [];
            $payload['tool_input']['path'] = $raw['file_path'];
            if (!isset($payload['tool_name']) || trim((string) $payload['tool_name']) === '') {
                $payload['tool_name'] = $event === 'PostToolUse' ? 'Write' : 'Write';
            }
        }
        if (isset($raw['edits']) && is_array($raw['edits'])) {
            $payload['tool_input'] = is_array($payload['tool_input'] ?? null)
                ? $payload['tool_input']
                : [];
            $payload['tool_input']['edits'] = $raw['edits'];
            if (!isset($payload['tool_name']) || trim((string) $payload['tool_name']) === '') {
                $payload['tool_name'] = 'Write';
            }
        }

        return [
            'event' => $event,
            'payload' => $payload,
            'cursor_response' => self::defaultCursorResponse($event),
        ];
    }

    public static function mapEvent(string $hookName): string
    {
        $normalized = strtolower(trim($hookName));
        $normalized = str_replace(['_', '-'], '', $normalized);

        return match ($normalized) {
            'sessionstart' => 'SessionStart',
            'sessionend', 'stop' => 'Stop',
            'beforesubmitprompt', 'userpromptsubmit' => 'UserPromptSubmit',
            'pretooluse', 'beforemcpexecution', 'beforeshellexecution' => 'PreToolUse',
            'posttooluse', 'aftermcpexecution', 'aftershellexecution', 'afterfileedit' => 'PostToolUse',
            'precompact' => 'PreCompact',
            'postcompact' => 'PostCompact',
            default => 'UserPromptSubmit',
        };
    }

    /** @return array<string, mixed>|object */
    public static function defaultCursorResponse(string $event): array|object
    {
        return match ($event) {
            'UserPromptSubmit' => ['continue' => true],
            'PreToolUse' => ['permission' => 'allow'],
            'SessionStart' => [
                'additional_context' => 'Weline learning: SessionStart captured. For engineering, run prepare_project and obey hard_constraints; treat durable user rules as knowledge candidates and report learning conflicts before overriding them.',
            ],
            default => new \stdClass(),
        };
    }
}
