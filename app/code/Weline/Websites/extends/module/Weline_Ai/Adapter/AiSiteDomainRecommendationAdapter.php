<?php
declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * Generates semantic ASCII labels only; Websites owns all domain suffixes.
 */
final class AiSiteDomainRecommendationAdapter implements ScenarioAdapterInterface, AdapterModelBindingInterface
{
    public const SCENARIO_CODE = 'websites_ai_site_domain_recommendation';

    public function getDefaultModelBindings(): array
    {
        return ['text2text' => 'deepseek-v4-flash'];
    }

    public function getCode(): string
    {
        return self::SCENARIO_CODE;
    }

    public function getName(): string
    {
        return (string)__('AI 建站域名语义标签推荐');
    }

    public function getDescription(): string
    {
        return (string)__('根据建站目标生成可用于域名的 ASCII 语义标签，不负责后缀、可用性、购买或绑定。');
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
        $brief = \trim((string)($params['brief'] ?? $prompt));
        $preferredDomain = \trim((string)($params['preferred_domain'] ?? ''));
        $locale = \trim((string)($params['locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';

        return <<<PROMPT
You generate semantic domain labels for an AI website builder.

Task context:
- Website brief: {$brief}
- Preferred domain or label: {$preferredDomain}
- Content locale: {$locale}

Output contract:
1. Return exactly one valid JSON object: {"labels":["label-one","label-two","label-three","label-four","label-five"]}
2. Return exactly five distinct labels, ordered best first.
3. Each label must use lowercase ASCII letters, digits, and single hyphens only.
4. Each label must start and end with a letter or digit and be 3-54 characters long.
5. Labels should be short, memorable, brand-relevant, and semantically connected to the brief.
6. Do not include a dot, TLD, protocol, path, availability claim, registrar claim, purchase claim, markdown, or extra prose.
PROMPT;
    }

    public function processResponse(string $response, array $params = []): string
    {
        $content = \trim($response);
        if ($content === '') {
            return $response;
        }

        if (\preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches) === 1) {
            $content = \trim((string)($matches[1] ?? ''));
        } elseif (\preg_match('/(\{[\s\S]*\})/m', $content, $matches) === 1) {
            $content = \trim((string)($matches[1] ?? ''));
        }

        $decoded = \json_decode($content, true);
        return \is_array($decoded) ? $content : $response;
    }

    public function validateParams(array $params = []): array
    {
        $brief = \trim((string)($params['brief'] ?? ''));
        $preferredDomain = \trim((string)($params['preferred_domain'] ?? ''));
        if ($brief === '' && $preferredDomain === '') {
            return [(string)__('建站描述或偏好域名至少需要填写一项。')];
        }

        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => (string)__('AI 建站域名语义标签参数'),
            'fields' => [
                ['name' => 'brief', 'type' => 'string', 'required' => false],
                ['name' => 'preferred_domain', 'type' => 'string', 'required' => false],
                ['name' => 'locale', 'type' => 'string', 'required' => false],
            ],
        ];
    }

    public function getExamples(): array
    {
        return [[
            'title' => (string)__('精品咖啡品牌域名标签'),
            'description' => (string)__('只生成语义标签，由 Websites 服务端决定域名后缀。'),
            'input' => (string)__('为上海精品咖啡工作室制作预约品牌站'),
            'expected_output' => '{"labels":["shanghai-coffee","reserve-roast","coffee-studio","city-brew","artisan-cup"]}',
        ]];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
