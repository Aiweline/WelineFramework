<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * SEO 优化适配器
 *
 * 专为 SEO 场景设计的适配器
 */
class SEOAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_seo';
    }

    public function getName(): string
    {
        return __('SEO 优化助手');
    }

    public function getDescription(): string
    {
        return __('专为 SEO 场景设计，支持关键词分析、内容优化建议、Meta 标签生成、结构化数据建议等能力。');
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
        $keyword = $params['keyword'] ?? '';
        $targetUrl = $params['target_url'] ?? '';
        $language = $params['language'] ?? 'zh-CN';

        $systemPrompt = "你是一个专业的 SEO 优化助手，具备以下能力：\n\n";
        $systemPrompt .= "【核心能力】\n";
        $systemPrompt .= "- 关键词研究和分析\n";
        $systemPrompt .= "- 页面内容优化建议\n";
        $systemPrompt .= "- Meta 标签（Title、Description）生成\n";
        $systemPrompt .= "- 结构化数据（Schema.org）建议\n";
        $systemPrompt .= "- 内链外链策略建议\n";
        $systemPrompt .= "- 技术SEO问题检测\n\n";

        if ($keyword) {
            $systemPrompt .= "【目标关键词】{$keyword}\n\n";
        }
        if ($targetUrl) {
            $systemPrompt .= "【目标页面】{$targetUrl}\n\n";
        }
        $systemPrompt .= "【语言】{$language}\n\n";
        $systemPrompt .= "用户请求：{$prompt}";

        return $systemPrompt;
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'keyword' => [
                'type' => 'string',
                'required' => false,
                'description' => '目标关键词',
            ],
            'target_url' => [
                'type' => 'string',
                'required' => false,
                'description' => '目标页面 URL',
            ],
            'language' => [
                'type' => 'string',
                'required' => false,
                'description' => '内容语言',
                'default' => 'zh-CN',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => '生成 Meta 标签',
                'input' => '为"PHP 教程"关键词生成页面 Meta 标签',
                'expected_output' => '返回优化后的 Title、Description、Keywords',
            ],
            [
                'title' => '内容优化建议',
                'input' => '分析这篇 SEO 优化文章的内容质量',
                'expected_output' => '返回关键词密度、可读性、结构化建议',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
