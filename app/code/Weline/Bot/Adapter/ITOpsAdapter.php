<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * IT operations scenario adapter.
 */
class ITOpsAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_it_ops';
    }

    public function getName(): string
    {
        return __('IT Ops Assistant');
    }

    public function getDescription(): string
    {
        return __('Adapter tuned for monitoring, log analysis, incident triage, and change-safe operations.');
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
        $targetServer = trim((string) ($params['server'] ?? ''));
        $targetService = trim((string) ($params['service'] ?? ''));

        $systemPrompt = "You are a professional IT operations assistant.\n\n";
        $systemPrompt .= "[Core Capabilities]\n";
        $systemPrompt .= "- Service and host status diagnostics\n";
        $systemPrompt .= "- Log analysis and error triage\n";
        $systemPrompt .= "- Reliability-first remediation planning\n";
        $systemPrompt .= "- Security and configuration sanity checks\n\n";
        $systemPrompt .= "[Execution Rules]\n";
        $systemPrompt .= "- Prefer safe, reversible operations.\n";
        $systemPrompt .= "- Ask for confirmation before restart/stop/delete actions.\n";
        $systemPrompt .= "- Provide explicit validation and rollback steps.\n\n";

        if ($targetServer !== '') {
            $systemPrompt .= "[Target Server]\n{$targetServer}\n\n";
        }
        if ($targetService !== '') {
            $systemPrompt .= "[Target Service]\n{$targetService}\n\n";
        }

        $systemPrompt .= "[User Request]\n{$prompt}";
        return $systemPrompt;
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        if (isset($params['server']) && !is_string($params['server'])) {
            $errors[] = 'server must be a string';
        }
        if (isset($params['service']) && !is_string($params['service'])) {
            $errors[] = 'service must be a string';
        }
        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'server' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Target server name or host',
            ],
            'service' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Target service name',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Service health check',
                'input' => 'Check nginx status and summarize recent errors.',
                'expected_output' => 'Service status plus key issue analysis and next steps.',
            ],
            [
                'title' => 'Error-log triage',
                'input' => 'Analyze the latest 100 lines in /var/log/nginx/error.log',
                'expected_output' => 'Grouped error patterns with remediation guidance.',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
