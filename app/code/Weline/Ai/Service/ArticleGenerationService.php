<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Adapter\ArticleGenerationAdapter;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

/**
 * 文章生成服务
 *
 * 封装 AI 文章生成，基于 ArticleGenerationAdapter 和 AiService，
 * 供内容模块调用。
 */
class ArticleGenerationService
{
    private const SCENARIO_CODE = null;

    private AiService $aiService;
    private AdapterScanner $adapterScanner;

    public function __construct(
        AiService $aiService,
        AdapterScanner $adapterScanner
    ) {
        $this->aiService = $aiService;
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 生成博客文章（Blog 模块专用）
     *
     * @param string $keyword 主关键词
     * @param string $locale 语言代码
     * @param array<string, mixed> $options 选项
     * @return array{title?: string, summary?: string, content?: string, _error?: bool, _error_message?: string}
     */
    public function generateBlogArticle(string $keyword, string $locale, array $options = []): array
    {
        $params = array_merge([
            'keyword' => $keyword,
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_BLOG,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'include_seo' => true,
        ], $options);
        return $this->generateArticle($keyword, $params);
    }

    /**
     * 生成文章
     *
     * @param string $topic 主题/关键词
     * @param array<string, mixed> $params 参数（含 keyword, locale, article_type, style, length, include_seo 等）
     * @return array{title?: string, summary?: string, content?: string, _error?: bool, _error_message?: string}
     */
    public function generateArticle(string $topic, array $params = []): array
    {
        try {
            $locale = $params['locale'] ?? 'zh_Hans_CN';
            $params['keyword'] = $params['keyword'] ?? $topic;
            $params['scenario_code'] = self::SCENARIO_CODE;

            $response = $this->aiService->generate(
                $topic,
                null,
                self::SCENARIO_CODE,
                $locale,
                $params,
                null,
                (bool)($params['is_backend'] ?? false)
            );

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                return [
                    '_error' => true,
                    '_error_message' => __('AI 返回格式异常，无法解析'),
                ];
            }
            return $decoded;
        } catch (Exception $e) {
            return [
                '_error' => true,
                '_error_message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                '_error' => true,
                '_error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 流式生成文章
     *
     * @param string $topic 主题
     * @param callable(string $chunk, string $fullResponse): void $onChunk 每收到一块内容时回调
     * @param array<string, mixed> $params 参数
     * @return array{title?: string, summary?: string, content?: string, _error?: bool, _error_message?: string}
     */
    public function generateArticleStream(string $topic, callable $onChunk, array $params = []): array
    {
        try {
            $locale = $params['locale'] ?? 'zh_Hans_CN';
            $params['keyword'] = $params['keyword'] ?? $topic;
            $params['scenario_code'] = self::SCENARIO_CODE;

            $fullResponse = '';
            $wrappedCallback = function ($chunk) use ($onChunk, &$fullResponse): void {
                $chunk = is_string($chunk) ? $chunk : (string) $chunk;
                $fullResponse .= $chunk;
                $onChunk($chunk, $fullResponse);
            };

            $this->aiService->generateStream(
                $topic,
                $wrappedCallback,
                null,
                self::SCENARIO_CODE,
                $locale,
                $params
            );

            $adapter = $this->adapterScanner->getAdapter(self::SCENARIO_CODE);
            if (!$adapter instanceof ArticleGenerationAdapter) {
                $processed = $fullResponse;
            } else {
                $processed = $adapter->processResponse($fullResponse, $params);
            }

            $decoded = json_decode($processed, true);
            if (!is_array($decoded)) {
                return [
                    '_error' => true,
                    '_error_message' => __('流式返回格式异常，无法解析'),
                ];
            }
            return $decoded;
        } catch (Exception $e) {
            return [
                '_error' => true,
                '_error_message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                '_error' => true,
                '_error_message' => $e->getMessage(),
            ];
        }
    }
}
