<?php

declare(strict_types=1);

namespace Weline\Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 文章生成场景适配器
 * 
 * 专为 Blog 和 PageBuilder 模块设计的 AI 文章生成适配器：
 * - 支持多种文章类型（博客、新闻、产品介绍、落地页等）
 * - 支持多语言文章生成
 * - 支持 SEO 优化（标题、摘要、关键词、Meta 信息）
 * - 支持结构化输出（JSON 格式）
 * - 支持自定义文章风格和长度
 */
class ArticleGenerationAdapter implements ScenarioAdapterInterface
{
    public const ARTICLE_TYPE_BLOG = 'blog';
    public const ARTICLE_TYPE_NEWS = 'news';
    public const ARTICLE_TYPE_PRODUCT = 'product';
    public const ARTICLE_TYPE_LANDING = 'landing';
    public const ARTICLE_TYPE_ABOUT = 'about';
    public const ARTICLE_TYPE_FAQ = 'faq';
    public const ARTICLE_TYPE_TUTORIAL = 'tutorial';
    public const ARTICLE_TYPE_REVIEW = 'review';

    public const STYLE_PROFESSIONAL = 'professional';
    public const STYLE_CASUAL = 'casual';
    public const STYLE_TECHNICAL = 'technical';
    public const STYLE_MARKETING = 'marketing';
    public const STYLE_EDUCATIONAL = 'educational';

    public const LENGTH_SHORT = 'short';
    public const LENGTH_MEDIUM = 'medium';
    public const LENGTH_LONG = 'long';

    private const LENGTH_WORDS = [
        self::LENGTH_SHORT => [300, 500],
        self::LENGTH_MEDIUM => [500, 800],
        self::LENGTH_LONG => [800, 1500],
    ];

    public function getCode(): string
    {
        return 'pagebuilder_article_generation';
    }

    public function getName(): string
    {
        return __('文章生成适配器');
    }

    public function getDescription(): string
    {
        return __('专为 Blog 和 PageBuilder 模块设计的 AI 文章生成适配器。支持多种文章类型、多语言、SEO 优化、自定义风格和长度。输出结构化 JSON 格式，包含标题、摘要、正文、SEO 元信息等。');
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
        $articleType = $params['article_type'] ?? self::ARTICLE_TYPE_BLOG;
        $style = $params['style'] ?? self::STYLE_PROFESSIONAL;
        $length = $params['length'] ?? self::LENGTH_MEDIUM;
        $locale = $params['locale'] ?? 'zh_Hans_CN';
        $keyword = $params['keyword'] ?? '';
        $additionalKeywords = $params['additional_keywords'] ?? [];
        $targetAudience = $params['target_audience'] ?? '';
        $includeSeo = $params['include_seo'] ?? true;
        $includeOutline = $params['include_outline'] ?? false;
        $customInstructions = $params['custom_instructions'] ?? '';

        $language = $this->getLanguageName($locale);
        $wordRange = self::LENGTH_WORDS[$length] ?? self::LENGTH_WORDS[self::LENGTH_MEDIUM];
        $typeLabel = $this->getArticleTypeLabel($articleType);
        $styleLabel = $this->getStyleLabel($style);

        $systemPrompt = $this->buildSystemPrompt($language, $typeLabel, $styleLabel, $wordRange, $includeSeo);
        $userPrompt = $this->buildUserPrompt($prompt, $keyword, $additionalKeywords, $targetAudience, $includeOutline, $customInstructions);

        return $systemPrompt . "\n\n---\n\n" . $userPrompt;
    }

    private function buildSystemPrompt(
        string $language,
        string $typeLabel,
        string $styleLabel,
        array $wordRange,
        bool $includeSeo
    ): string {
        $outputFields = [
            '"title": "文章标题（包含主关键词，吸引点击）"',
            '"summary": "200字以内摘要，概括文章核心内容"',
            '"content": "文章正文，HTML格式，使用<p>分段，可包含<h2><h3>小标题、<ul><li>列表等"',
        ];

        if ($includeSeo) {
            $outputFields[] = '"meta_title": "SEO标题，50-60字符，包含关键词"';
            $outputFields[] = '"meta_description": "SEO描述，120-160字符，包含关键词"';
            $outputFields[] = '"meta_keywords": "SEO关键词，逗号分隔，5-10个"';
            $outputFields[] = '"tags": ["标签1", "标签2", "标签3"]';
        }

        $outputFormat = "{\n  " . implode(",\n  ", $outputFields) . "\n}";

        $prompt = <<<PROMPT
你是一位专业的{$language}内容创作者，擅长撰写高质量的{$typeLabel}文章。

## 写作要求

1. **语言**：使用{$language}撰写
2. **类型**：{$typeLabel}
3. **风格**：{$styleLabel}
4. **长度**：正文 {$wordRange[0]}-{$wordRange[1]} 字

## 内容质量标准

- 标题要吸引人，包含核心关键词
- 开篇引人入胜，快速切入主题
- 内容逻辑清晰，层次分明
- 适当使用小标题、列表等排版元素
- 语言流畅自然，避免机械感
- 结尾有力，可包含行动号召

## 输出格式

严格按以下 JSON 格式输出，不要包含其他说明文字或 Markdown 代码块标记：

{$outputFormat}

## 重要提示

1. 只返回 JSON，不要有任何额外文字
2. JSON 必须有效，可直接解析
3. content 字段使用 HTML 格式
4. 确保所有引号和特殊字符正确转义
PROMPT;

        return $prompt;
    }

    private function buildUserPrompt(
        string $prompt,
        string $keyword,
        array $additionalKeywords,
        string $targetAudience,
        bool $includeOutline,
        string $customInstructions
    ): string {
        $parts = [];

        if (!empty($keyword)) {
            $parts[] = "**主关键词**：{$keyword}";
        }

        if (!empty($additionalKeywords)) {
            $keywordsStr = implode('、', $additionalKeywords);
            $parts[] = "**相关关键词**：{$keywordsStr}（请在文章中自然融入）";
        }

        if (!empty($targetAudience)) {
            $parts[] = "**目标受众**：{$targetAudience}";
        }

        $parts[] = "**文章主题/要求**：\n{$prompt}";

        if ($includeOutline) {
            $parts[] = "**附加要求**：请先构思文章大纲，确保结构完整";
        }

        if (!empty($customInstructions)) {
            $parts[] = "**特别说明**：\n{$customInstructions}";
        }

        return implode("\n\n", $parts);
    }

    public function processResponse(string $response, array $params = []): string
    {
        $json = $this->extractJson($response);

        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && $this->validateArticleStructure($decoded)) {
            $decoded = $this->normalizeArticleData($decoded);
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $json = $this->tryFixJson($json);

        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && $this->validateArticleStructure($decoded)) {
            $decoded = $this->normalizeArticleData($decoded);
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->createFallbackResponse($response, $params);
    }

    private function extractJson(string $response): string
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{[\s\S]*\})/', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }

    private function tryFixJson(string $json): string
    {
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);

        $json = preg_replace('/\\\\([^"\\\\\/bfnrtu])/', '\\\\\\\\$1', $json);

        return $json;
    }

    private function validateArticleStructure(array $data): bool
    {
        return isset($data['title']) && isset($data['content']);
    }

    private function normalizeArticleData(array $data): array
    {
        $normalized = [
            'title' => trim($data['title'] ?? ''),
            'summary' => trim($data['summary'] ?? ''),
            'content' => $data['content'] ?? '',
        ];

        if (isset($data['meta_title'])) {
            $normalized['meta_title'] = trim($data['meta_title']);
        }
        if (isset($data['meta_description'])) {
            $normalized['meta_description'] = trim($data['meta_description']);
        }
        if (isset($data['meta_keywords'])) {
            $normalized['meta_keywords'] = trim($data['meta_keywords']);
        }
        if (isset($data['tags'])) {
            $normalized['tags'] = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
            $normalized['tags'] = array_map('trim', $normalized['tags']);
        }

        if (empty($normalized['summary']) && !empty($normalized['content'])) {
            $normalized['summary'] = $this->generateSummaryFromContent($normalized['content']);
        }

        return $normalized;
    }

    private function generateSummaryFromContent(string $content): string
    {
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197) . '...';
        }

        return $text;
    }

    private function createFallbackResponse(string $response, array $params): string
    {
        $keyword = $params['keyword'] ?? '';
        $title = !empty($keyword) ? $keyword : '未命名文章';

        $content = '<p>' . nl2br(htmlspecialchars($response)) . '</p>';

        $fallback = [
            'title' => $title . ' - ' . date('Y-m-d'),
            'summary' => mb_substr(strip_tags($response), 0, 200),
            'content' => $content,
            '_fallback' => true,
            '_original_response' => $response,
        ];

        return json_encode($fallback, JSON_UNESCAPED_UNICODE);
    }

    private function getLanguageName(string $locale): string
    {
        $languages = [
            'zh_Hans_CN' => '简体中文',
            'zh_Hant_TW' => '繁体中文',
            'en_US' => 'English',
            'ja_JP' => '日本語',
            'ko_KR' => '한국어',
            'es_ES' => 'Español',
            'fr_FR' => 'Français',
            'de_DE' => 'Deutsch',
            'pt_BR' => 'Português',
            'ru_RU' => 'Русский',
            'ar_SA' => 'العربية',
            'hi_IN' => 'हिन्दी',
            'th_TH' => 'ไทย',
            'vi_VN' => 'Tiếng Việt',
            'id_ID' => 'Bahasa Indonesia',
            'ms_MY' => 'Bahasa Melayu',
        ];

        return $languages[$locale] ?? 'English';
    }

    private function getArticleTypeLabel(string $type): string
    {
        $types = [
            self::ARTICLE_TYPE_BLOG => '博客文章',
            self::ARTICLE_TYPE_NEWS => '新闻资讯',
            self::ARTICLE_TYPE_PRODUCT => '产品介绍',
            self::ARTICLE_TYPE_LANDING => '落地页内容',
            self::ARTICLE_TYPE_ABOUT => '企业/品牌介绍',
            self::ARTICLE_TYPE_FAQ => 'FAQ问答',
            self::ARTICLE_TYPE_TUTORIAL => '教程/指南',
            self::ARTICLE_TYPE_REVIEW => '评测/评价',
        ];

        return $types[$type] ?? '文章';
    }

    private function getStyleLabel(string $style): string
    {
        $styles = [
            self::STYLE_PROFESSIONAL => '专业严谨',
            self::STYLE_CASUAL => '轻松活泼',
            self::STYLE_TECHNICAL => '技术深度',
            self::STYLE_MARKETING => '营销推广',
            self::STYLE_EDUCATIONAL => '教育科普',
        ];

        return $styles[$style] ?? '专业';
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];

        if (isset($params['article_type'])) {
            $validTypes = [
                self::ARTICLE_TYPE_BLOG, self::ARTICLE_TYPE_NEWS, self::ARTICLE_TYPE_PRODUCT,
                self::ARTICLE_TYPE_LANDING, self::ARTICLE_TYPE_ABOUT, self::ARTICLE_TYPE_FAQ,
                self::ARTICLE_TYPE_TUTORIAL, self::ARTICLE_TYPE_REVIEW,
            ];
            if (!in_array($params['article_type'], $validTypes, true)) {
                $errors[] = __('无效的文章类型：%{type}', ['type' => $params['article_type']]);
            }
        }

        if (isset($params['style'])) {
            $validStyles = [
                self::STYLE_PROFESSIONAL, self::STYLE_CASUAL, self::STYLE_TECHNICAL,
                self::STYLE_MARKETING, self::STYLE_EDUCATIONAL,
            ];
            if (!in_array($params['style'], $validStyles, true)) {
                $errors[] = __('无效的写作风格：%{style}', ['style' => $params['style']]);
            }
        }

        if (isset($params['length'])) {
            $validLengths = [self::LENGTH_SHORT, self::LENGTH_MEDIUM, self::LENGTH_LONG];
            if (!in_array($params['length'], $validLengths, true)) {
                $errors[] = __('无效的文章长度：%{length}', ['length' => $params['length']]);
            }
        }

        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => __('文章生成适配器参数'),
            'fields' => [
                'keyword' => [
                    'type' => 'string',
                    'label' => __('主关键词'),
                    'required' => false,
                    'description' => __('文章的核心关键词，将在标题和内容中突出'),
                ],
                'additional_keywords' => [
                    'type' => 'array',
                    'label' => __('相关关键词'),
                    'required' => false,
                    'description' => __('需要在文章中自然融入的相关关键词'),
                ],
                'article_type' => [
                    'type' => 'string',
                    'label' => __('文章类型'),
                    'required' => false,
                    'default' => self::ARTICLE_TYPE_BLOG,
                    'options' => [
                        self::ARTICLE_TYPE_BLOG => __('博客文章'),
                        self::ARTICLE_TYPE_NEWS => __('新闻资讯'),
                        self::ARTICLE_TYPE_PRODUCT => __('产品介绍'),
                        self::ARTICLE_TYPE_LANDING => __('落地页'),
                        self::ARTICLE_TYPE_ABOUT => __('企业介绍'),
                        self::ARTICLE_TYPE_FAQ => __('FAQ'),
                        self::ARTICLE_TYPE_TUTORIAL => __('教程'),
                        self::ARTICLE_TYPE_REVIEW => __('评测'),
                    ],
                ],
                'style' => [
                    'type' => 'string',
                    'label' => __('写作风格'),
                    'required' => false,
                    'default' => self::STYLE_PROFESSIONAL,
                    'options' => [
                        self::STYLE_PROFESSIONAL => __('专业严谨'),
                        self::STYLE_CASUAL => __('轻松活泼'),
                        self::STYLE_TECHNICAL => __('技术深度'),
                        self::STYLE_MARKETING => __('营销推广'),
                        self::STYLE_EDUCATIONAL => __('教育科普'),
                    ],
                ],
                'length' => [
                    'type' => 'string',
                    'label' => __('文章长度'),
                    'required' => false,
                    'default' => self::LENGTH_MEDIUM,
                    'options' => [
                        self::LENGTH_SHORT => __('短篇（300-500字）'),
                        self::LENGTH_MEDIUM => __('中篇（500-800字）'),
                        self::LENGTH_LONG => __('长篇（800-1500字）'),
                    ],
                ],
                'locale' => [
                    'type' => 'string',
                    'label' => __('语言'),
                    'required' => false,
                    'default' => 'zh_Hans_CN',
                    'description' => __('文章语言代码，如 zh_Hans_CN, en_US'),
                ],
                'target_audience' => [
                    'type' => 'string',
                    'label' => __('目标受众'),
                    'required' => false,
                    'description' => __('文章的目标读者群体'),
                ],
                'include_seo' => [
                    'type' => 'boolean',
                    'label' => __('包含SEO信息'),
                    'required' => false,
                    'default' => true,
                    'description' => __('是否生成 meta_title、meta_description、meta_keywords'),
                ],
                'custom_instructions' => [
                    'type' => 'text',
                    'label' => __('自定义说明'),
                    'required' => false,
                    'description' => __('额外的写作要求或说明'),
                ],
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => __('博客文章生成'),
                'description' => __('根据关键词生成 SEO 优化的博客文章'),
                'input' => __('关键词：PHP 8.4 新特性'),
                'params' => [
                    'keyword' => 'PHP 8.4 新特性',
                    'article_type' => 'blog',
                    'style' => 'technical',
                    'length' => 'medium',
                    'locale' => 'zh_Hans_CN',
                ],
                'expected_output' => '{"title": "PHP 8.4 新特性详解：开发者必知的 10 大改进", "summary": "...", "content": "<p>...</p>", "meta_title": "...", "meta_description": "...", "meta_keywords": "...", "tags": ["PHP", "PHP8.4", "编程"]}',
            ],
            [
                'title' => __('产品介绍页'),
                'description' => __('生成产品介绍文案'),
                'input' => __('产品：智能家居控制中心'),
                'params' => [
                    'keyword' => '智能家居控制中心',
                    'article_type' => 'product',
                    'style' => 'marketing',
                    'length' => 'medium',
                ],
                'expected_output' => '{"title": "...", "summary": "...", "content": "...", ...}',
            ],
            [
                'title' => __('FAQ 生成'),
                'description' => __('生成常见问题解答'),
                'input' => __('主题：电商退换货政策'),
                'params' => [
                    'keyword' => '退换货政策',
                    'article_type' => 'faq',
                    'style' => 'professional',
                ],
                'expected_output' => '{"title": "退换货政策常见问题", "content": "<h2>Q: 如何申请退货？</h2><p>A: ...</p>", ...}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
