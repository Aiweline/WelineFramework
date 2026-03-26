<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * Default Bot scenario adapter.
 */
class BotAgentAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_agent';
    }

    public function getName(): string
    {
        return __('Bot Agent Adapter');
    }

    public function getDescription(): string
    {
        return __('Default adapter for role-based bot conversations with tools, memory context, and safety guardrails.');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $roleName = (string) ($params['role_name'] ?? 'AI Assistant');
        $skills = is_array($params['skills'] ?? null) ? $params['skills'] : [];
        $permissions = is_array($params['permissions'] ?? null) ? $params['permissions'] : [];
        $memory = is_array($params['memory'] ?? null) ? $params['memory'] : [];

        $enhancedPrompt = "You are {$roleName}.\n\n";

        if (!empty($memory)) {
            $enhancedPrompt .= "[Relevant Memory]\n";
            foreach ($memory as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $type = (string) ($item['type'] ?? 'fact');
                $value = trim((string) ($item['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $enhancedPrompt .= "- [{$type}] {$value}\n";
            }
            $enhancedPrompt .= "\n";
        }

        if (!empty($skills)) {
            $enhancedPrompt .= "[Available Skills]\n";
            foreach ($skills as $skill) {
                if (is_array($skill)) {
                    $name = (string) ($skill['name'] ?? $skill['code'] ?? 'skill');
                    $code = trim((string) ($skill['code'] ?? ''));
                    $enhancedPrompt .= $code === '' ? "- {$name}\n" : "- {$name} ({$code})\n";
                    continue;
                }

                $enhancedPrompt .= '- ' . trim((string) $skill) . "\n";
            }
            $enhancedPrompt .= "\n";
        }

        if (!empty($permissions)) {
            $enhancedPrompt .= "[Permission Scope]\n";
            foreach ($permissions as $permission) {
                $permission = trim((string) $permission);
                if ($permission !== '') {
                    $enhancedPrompt .= "- {$permission}\n";
                }
            }
            $enhancedPrompt .= "\n";
        }

        $enhancedPrompt .= "[Safety Rules]\n";
        $enhancedPrompt .= "- Ask for explicit confirmation before destructive or sensitive actions.\n";
        $enhancedPrompt .= "- Never expose secrets such as API keys, tokens, or passwords.\n";
        $enhancedPrompt .= "- If a task cannot be completed, explain constraints clearly and provide alternatives.\n";
        $enhancedPrompt .= "- Keep outputs actionable and aligned with granted permissions.\n\n";
        $enhancedPrompt .= "[User Input]\n{$prompt}";

        return $enhancedPrompt;
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $this->redactSensitiveInfo($response);
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];

        if (isset($params['role_id']) && !is_numeric($params['role_id'])) {
            $errors[] = 'role_id must be numeric';
        }
        if (isset($params['skills']) && !is_array($params['skills'])) {
            $errors[] = 'skills must be an array';
        }
        if (isset($params['permissions']) && !is_array($params['permissions'])) {
            $errors[] = 'permissions must be an array';
        }

        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'role_id' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Role ID',
            ],
            'role_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Role display name',
                'default' => 'AI Assistant',
            ],
            'skills' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Available skill list',
            ],
            'permissions' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Permission list',
            ],
            'memory' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Retrieved memory snippets',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Daily planning',
                'description' => 'Use role context to summarize and prioritize TODOs.',
                'input' => 'Please organize my tasks for today by priority.',
                'expected_output' => 'Prioritized task list with next actions.',
            ],
            [
                'title' => 'Tool assisted task',
                'description' => 'Read project files with permissions.',
                'input' => 'Read /app/config/app.php and summarize key config values.',
                'expected_output' => 'Config summary within allowed scope.',
            ],
            [
                'title' => 'Safe command guidance',
                'description' => 'Ask for confirmation before risky operations.',
                'input' => 'Delete all old logs under /var/log/app',
                'expected_output' => 'Requires explicit confirmation before deletion.',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }

    private function redactSensitiveInfo(string $text): string
    {
        $patterns = [
            '/sk-[a-zA-Z0-9]{20,}/' => 'sk-****REDACTED****',
            '/api[_-]?key["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{20,}/i' => 'api_key: ****REDACTED****',
            '/password["\']?\s*[:=]\s*["\']?[^\s"\']+/i' => 'password: ****REDACTED****',
            '/secret["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{10,}/i' => 'secret: ****REDACTED****',
            '/token["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{20,}/i' => 'token: ****REDACTED****',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
