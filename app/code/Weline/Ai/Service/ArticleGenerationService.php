<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Adapter\ArticleGenerationAdapter;
use Weline\Framework\Manager\ObjectManager;

/**
 * 文章生成服务
 * 
 * 为 Blog 和 PageBuilder 模块提供便捷的 AI 文章生成接口。
 * 封装了 AiService 和 ArticleGenerationAdapter 的调用逻辑。
 */
class ArticleGenerationService
{
    private AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * 根据关键词生成博客文章
     *
     * @param string $keyword 主关键词
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array{title: string, summary: string, content: string, meta_title?: string, meta_description?: string, meta_keywords?: string, tags?: array}
     */
    public function generateBlogArticle(string $keyword, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($keyword, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_BLOG,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成产品介绍文章
     *
     * @param string $productName 产品名称
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateProductArticle(string $productName, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($productName, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_PRODUCT,
            'style' => ArticleGenerationAdapter::STYLE_MARKETING,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成落地页内容
     *
     * @param string $topic 页面主题
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateLandingPageContent(string $topic, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($topic, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_LANDING,
            'style' => ArticleGenerationAdapter::STYLE_MARKETING,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成新闻资讯文章
     *
     * @param string $topic 新闻主题
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateNewsArticle(string $topic, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($topic, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_NEWS,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成教程文章
     *
     * @param string $topic 教程主题
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateTutorialArticle(string $topic, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($topic, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_TUTORIAL,
            'style' => ArticleGenerationAdapter::STYLE_EDUCATIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_LONG,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成 FAQ 内容
     *
     * @param string $topic FAQ 主题
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateFaqContent(string $topic, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($topic, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_FAQ,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => false,
        ], $options));
    }

    /**
     * 生成企业/关于我们页面内容
     *
     * @param string $companyInfo 公司信息描述
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateAboutContent(string $companyInfo, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($companyInfo, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_ABOUT,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 生成评测文章
     *
     * @param string $subject 评测对象
     * @param string $locale 语言代码
     * @param array $options 选项
     * @return array
     */
    public function generateReviewArticle(string $subject, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        return $this->generateArticle($subject, array_merge([
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_REVIEW,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_LONG,
            'locale' => $locale,
            'include_seo' => true,
        ], $options));
    }

    /**
     * 通用文章生成方法
     *
     * @param string $topic 文章主题或关键词
     * @param array $options 生成选项
     * @return array
     */
    public function generateArticle(string $topic, array $options = []): array
    {
        $keyword = $options['keyword'] ?? $topic;
        $locale = $options['locale'] ?? 'zh_Hans_CN';
        $modelCode = $options['model_code'] ?? null;
        $userId = $options['user_id'] ?? null;
        $isBackend = $options['is_backend'] ?? true;

        $prompt = $this->buildPrompt($topic, $options);

        $params = [
            'keyword' => $keyword,
            'additional_keywords' => $options['additional_keywords'] ?? [],
            'article_type' => $options['article_type'] ?? ArticleGenerationAdapter::ARTICLE_TYPE_BLOG,
            'style' => $options['style'] ?? ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => $options['length'] ?? ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'target_audience' => $options['target_audience'] ?? '',
            'include_seo' => $options['include_seo'] ?? true,
            'include_outline' => $options['include_outline'] ?? false,
            'custom_instructions' => $options['custom_instructions'] ?? '',
        ];

        try {
            $response = $this->aiService->generate(
                $prompt,
                $modelCode,
                'pagebuilder_article_generation',
                $locale,
                $params,
                $userId,
                $isBackend
            );

            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }

            return $this->createFallbackResult($topic, $response);
        } catch (\Throwable $e) {
            return $this->createErrorResult($topic, $e->getMessage());
        }
    }

    /**
     * 流式生成文章（用于实时显示进度）
     *
     * @param string $topic 文章主题
     * @param callable $callback 流式回调
     * @param array $options 生成选项
     * @return array
     */
    public function generateArticleStream(string $topic, callable $callback, array $options = []): array
    {
        $keyword = $options['keyword'] ?? $topic;
        $locale = $options['locale'] ?? 'zh_Hans_CN';
        $modelCode = $options['model_code'] ?? null;

        $prompt = $this->buildPrompt($topic, $options);

        $params = [
            'keyword' => $keyword,
            'additional_keywords' => $options['additional_keywords'] ?? [],
            'article_type' => $options['article_type'] ?? ArticleGenerationAdapter::ARTICLE_TYPE_BLOG,
            'style' => $options['style'] ?? ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => $options['length'] ?? ArticleGenerationAdapter::LENGTH_MEDIUM,
            'locale' => $locale,
            'target_audience' => $options['target_audience'] ?? '',
            'include_seo' => $options['include_seo'] ?? true,
            'custom_instructions' => $options['custom_instructions'] ?? '',
        ];

        try {
            $fullResponse = '';
            $streamCallback = function (string $chunk) use ($callback, &$fullResponse) {
                $fullResponse .= $chunk;
                $callback($chunk, $fullResponse);
            };

            $this->aiService->generateStream(
                $prompt,
                $streamCallback,
                $modelCode,
                'pagebuilder_article_generation',
                $locale,
                $params
            );

            $decoded = json_decode($fullResponse, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }

            return $this->createFallbackResult($topic, $fullResponse);
        } catch (\Throwable $e) {
            return $this->createErrorResult($topic, $e->getMessage());
        }
    }

    /**
     * 批量生成文章
     *
     * @param array $topics 主题列表
     * @param array $commonOptions 公共选项
     * @param callable|null $onProgress 进度回调
     * @return array 生成结果列表
     */
    public function generateBatch(array $topics, array $commonOptions = [], ?callable $onProgress = null): array
    {
        $results = [];
        $total = count($topics);

        foreach ($topics as $index => $topic) {
            $topicStr = is_array($topic) ? ($topic['keyword'] ?? $topic['topic'] ?? '') : $topic;
            $topicOptions = is_array($topic) ? array_merge($commonOptions, $topic) : $commonOptions;

            if ($onProgress) {
                $onProgress('start', [
                    'index' => $index + 1,
                    'total' => $total,
                    'topic' => $topicStr,
                ]);
            }

            try {
                $result = $this->generateArticle($topicStr, $topicOptions);
                $results[] = [
                    'topic' => $topicStr,
                    'success' => true,
                    'data' => $result,
                ];

                if ($onProgress) {
                    $onProgress('done', [
                        'index' => $index + 1,
                        'total' => $total,
                        'topic' => $topicStr,
                        'title' => $result['title'] ?? '',
                    ]);
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'topic' => $topicStr,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                if ($onProgress) {
                    $onProgress('error', [
                        'index' => $index + 1,
                        'total' => $total,
                        'topic' => $topicStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $results;
    }

    private function buildPrompt(string $topic, array $options): string
    {
        $articleType = $options['article_type'] ?? ArticleGenerationAdapter::ARTICLE_TYPE_BLOG;
        $typeLabel = $this->getArticleTypeLabel($articleType);

        return "请为以下主题撰写一篇{$typeLabel}：\n\n{$topic}";
    }

    private function getArticleTypeLabel(string $type): string
    {
        $types = [
            ArticleGenerationAdapter::ARTICLE_TYPE_BLOG => '博客文章',
            ArticleGenerationAdapter::ARTICLE_TYPE_NEWS => '新闻资讯',
            ArticleGenerationAdapter::ARTICLE_TYPE_PRODUCT => '产品介绍',
            ArticleGenerationAdapter::ARTICLE_TYPE_LANDING => '落地页内容',
            ArticleGenerationAdapter::ARTICLE_TYPE_ABOUT => '企业介绍',
            ArticleGenerationAdapter::ARTICLE_TYPE_FAQ => 'FAQ问答',
            ArticleGenerationAdapter::ARTICLE_TYPE_TUTORIAL => '教程指南',
            ArticleGenerationAdapter::ARTICLE_TYPE_REVIEW => '评测文章',
        ];

        return $types[$type] ?? '文章';
    }

    private function createFallbackResult(string $topic, string $response): array
    {
        return [
            'title' => $topic . ' - ' . date('Y-m-d'),
            'summary' => '',
            'content' => '<p>' . nl2br(htmlspecialchars($response)) . '</p>',
            '_fallback' => true,
        ];
    }

    /**
     * 创建错误结果 - 不包含虚假的标题和内容，让调用方识别并处理错误
     */
    private function createErrorResult(string $topic, string $error): array
    {
        return [
            'title' => '',
            'summary' => '',
            'content' => '',
            '_error' => true,
            '_error_message' => $error,
        ];
    }

    /**
     * 静态便捷方法：生成博客文章
     */
    public static function blog(string $keyword, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        /** @var self $service */
        $service = ObjectManager::getInstance(self::class);
        return $service->generateBlogArticle($keyword, $locale, $options);
    }

    /**
     * 静态便捷方法：生成产品文章
     */
    public static function product(string $productName, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        /** @var self $service */
        $service = ObjectManager::getInstance(self::class);
        return $service->generateProductArticle($productName, $locale, $options);
    }

    /**
     * 静态便捷方法：生成落地页
     */
    public static function landing(string $topic, string $locale = 'zh_Hans_CN', array $options = []): array
    {
        /** @var self $service */
        $service = ObjectManager::getInstance(self::class);
        return $service->generateLandingPageContent($topic, $locale, $options);
    }
}
