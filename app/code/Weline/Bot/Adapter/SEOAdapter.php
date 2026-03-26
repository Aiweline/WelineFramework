<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * SEO scenario adapter.
 */
class SEOAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_seo';
    }

    public function getName(): string
    {
        return __('SEO Assistant');
    }

    public function getDescription(): string
    {
        return __('Adapter tuned for keyword research, content optimization, metadata, and technical SEO checks.');
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
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $targetUrl = trim((string) ($params['target_url'] ?? ''));
        $language = trim((string) ($params['language'] ?? 'zh-CN'));

        $systemPrompt = "You are a professional SEO assistant.\n\n";
        $systemPrompt .= "[Core Capabilities]\n";
        $systemPrompt .= "- Keyword discovery and intent mapping\n";
        $systemPrompt .= "- On-page structure and content optimization\n";
        $systemPrompt .= "- Metadata generation (title, description)\n";
        $systemPrompt .= "- Internal link and schema guidance\n";
        $systemPrompt .= "- Technical SEO issue spotting\n\n";
        $systemPrompt .= "[Output Rules]\n";
        $systemPrompt .= "- Keep recommendations measurable and prioritized.\n";
        $systemPrompt .= "- Include rationale for each major suggestion.\n";
        $systemPrompt .= "- Avoid generic advice.\n\n";

        if ($keyword !== '') {
            $systemPrompt .= "[Primary Keyword]\n{$keyword}\n\n";
        }
        if ($targetUrl !== '') {
            $systemPrompt .= "[Target URL]\n{$targetUrl}\n\n";
        }
        $systemPrompt .= "[Language]\n{$language}\n\n";
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
        if (isset($params['keyword']) && !is_string($params['keyword'])) {
            $errors[] = 'keyword must be a string';
        }
        if (isset($params['target_url']) && !is_string($params['target_url'])) {
            $errors[] = 'target_url must be a string';
        }
        if (isset($params['language']) && !is_string($params['language'])) {
            $errors[] = 'language must be a string';
        }
        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'keyword' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Primary keyword',
            ],
            'target_url' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Target page URL',
            ],
            'language' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Content language',
                'default' => 'zh-CN',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Metadata generation',
                'input' => 'Generate title and meta description for keyword "php framework tutorial".',
                'expected_output' => 'Search-optimized title/description candidates.',
            ],
            [
                'title' => 'Content optimization',
                'input' => 'Review this landing page and provide prioritized SEO improvements.',
                'expected_output' => 'Actionable checklist with expected impact.',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
