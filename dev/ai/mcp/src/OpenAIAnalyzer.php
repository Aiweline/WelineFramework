<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class OpenAIAnalyzer implements ModelAnalyzer
{
    private string $apiKey;
    private string $baseUrl;
    private string $extractorModel;
    private string $verifierModel;
    private int $timeout;

    public function __construct(private readonly Config $config)
    {
        $environmentName = (string) $config->get('analysis.api_key_env', 'OPENAI_API_KEY');
        $apiKey = getenv($environmentName);
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('Analysis API key environment variable is not set: ' . $environmentName);
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) $config->get('analysis.base_url', 'https://api.openai.com/v1'), '/');
        $this->extractorModel = (string) $config->get('analysis.extractor_model');
        $this->verifierModel = (string) $config->get('analysis.verifier_model');
        $this->timeout = $config->duration('analysis.request_timeout');
    }

    public function extract(array $bundle): array
    {
        $result = $this->structured(
            $this->extractorModel,
            $this->prompt('extractor'),
            "<untrusted_episode_data>\n" . Json::encode($bundle) . "\n</untrusted_episode_data>",
            'learning_extraction',
            self::extractionSchema(),
        );
        if (!in_array($result['decision'] ?? '', ['candidate', 'no_learning'], true)) {
            throw new RuntimeException('Extractor returned an invalid decision');
        }

        return $result;
    }

    public function verify(array $draft, array $evidence): array
    {
        $result = $this->structured(
            $this->verifierModel,
            $this->prompt('verifier'),
            "<untrusted_candidate_and_evidence>\n" . Json::encode([
                'candidate' => $draft,
                'evidence_index' => $evidence,
            ]) . "\n</untrusted_candidate_and_evidence>",
            'learning_verification',
            self::assessmentSchema(),
        );
        $confidence = (float) ($result['confidence'] ?? -1);
        if ($confidence < 0 || $confidence > 1) {
            throw new RuntimeException('Verifier confidence is outside 0..1');
        }

        return $result;
    }

    public function metadata(): array
    {
        return [
            'provider' => 'openai',
            'transport' => 'native_php_https',
            'extractor_model' => $this->extractorModel,
            'verifier_model' => $this->verifierModel,
            'extractor_prompt' => 'extractor.v1',
            'verifier_prompt' => 'verifier.v1',
        ];
    }

    /** @param array<string, mixed> $schema
     *  @return array<string, mixed>
     */
    private function structured(string $model, string $instructions, string $input, string $schemaName, array $schema): array
    {
        $payload = Json::encode([
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => 5_000,
            'store' => false,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $payload,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $body = @file_get_contents($this->baseUrl . '/responses', false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }
        if ($body === false) {
            $error = error_get_last();
            throw new RuntimeException('Responses API request failed: ' . ($error['message'] ?? 'network error'));
        }
        $response = Json::object($body, 'Responses API response');
        if ($status < 200 || $status >= 300) {
            $message = (string) ($response['error']['message'] ?? ('HTTP ' . $status));
            [$message] = Redactor::string($message);
            throw new RuntimeException('Responses API: ' . Text::truncate($message, 500));
        }
        $outputText = is_string($response['output_text'] ?? null) ? $response['output_text'] : '';
        if ($outputText === '') {
            foreach (($response['output'] ?? []) as $output) {
                if (!is_array($output)) {
                    continue;
                }
                foreach (($output['content'] ?? []) as $content) {
                    if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                        $outputText .= $content['text'];
                    }
                }
            }
        }
        if (trim($outputText) === '') {
            throw new RuntimeException('Responses API returned no output text');
        }

        return Json::object($outputText, 'Structured model output');
    }

    private function prompt(string $name): string
    {
        $path = dirname(__DIR__) . '/prompts/' . $name . '/system.txt';
        $body = file_get_contents($path);
        if ($body === false || trim($body) === '') {
            throw new RuntimeException('Unable to load analyzer prompt: ' . $name);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private static function extractionSchema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $draft = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'title', 'category', 'problem_pattern', 'trigger', 'root_cause', 'correct_approach',
                'reusable_rule', 'evidence_ids', 'exceptions', 'paths', 'languages', 'wrong_approaches',
            ],
            'properties' => [
                'title' => ['type' => 'string'],
                'category' => [
                    'type' => 'string',
                    'enum' => [
                        'user_preference', 'project_constraint', 'project_fact', 'architecture_decision',
                        'debugging_strategy', 'anti_pattern', 'workflow_rule', 'tool_usage', 'test_oracle',
                        'security_boundary', 'temporary_context',
                    ],
                ],
                'problem_pattern' => ['type' => 'string'],
                'trigger' => ['type' => 'string'],
                'root_cause' => ['type' => 'string'],
                'correct_approach' => ['type' => 'string'],
                'reusable_rule' => ['type' => 'string'],
                'evidence_ids' => $strings,
                'exceptions' => $strings,
                'paths' => $strings,
                'languages' => $strings,
                'wrong_approaches' => $strings,
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['decision', 'experiences'],
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['candidate', 'no_learning']],
                'experiences' => ['type' => 'array', 'items' => $draft],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function assessmentSchema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'decision', 'confidence', 'verified_evidence_ids', 'problems',
                'narrowed_rule', 'scope_paths', 'exceptions',
            ],
            'properties' => [
                'decision' => [
                    'type' => 'string',
                    'enum' => [
                        'supported', 'partially_supported', 'unsupported', 'contradicted',
                        'scope_too_broad', 'causality_overstated', 'missing_evidence', 'injection_suspected',
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'verified_evidence_ids' => $strings,
                'problems' => $strings,
                'narrowed_rule' => ['type' => 'string'],
                'scope_paths' => $strings,
                'exceptions' => $strings,
            ],
        ];
    }
}
