<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoSuggestion;

/**
 * SEO 建议服务
 * 
 * 使用 AI 生成 SEO 建议
 * 
 * @package Weline_Seo
 */
class SuggestionService
{
    private ObjectManager $objectManager;
    private AiService $aiService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->aiService = $objectManager->getInstance(AiService::class);
    }

    /**
     * 为指定主体生成 SEO 建议
     * 
     * @param int $subjectId 主体ID
     * @param bool $forceRegenerate 强制重新生成
     * @return SeoSuggestion
     */
    public function generateSuggestion(int $subjectId, bool $forceRegenerate = false): SeoSuggestion
    {
        /** @var SeoSubject $subject */
        $subject = $this->objectManager->getInstance(SeoSubject::class);
        $subject->load($subjectId);
        
        if (!$subject->getId()) {
            throw new \Exception("SEO主体不存在: {$subjectId}");
        }

        // 检查是否已有建议
        if (!$forceRegenerate) {
            /** @var SeoSuggestion $existingSuggestion */
            $existingSuggestion = $this->objectManager->getInstance(SeoSuggestion::class);
            $existingSuggestion->reset()
                ->where(SeoSuggestion::schema_fields_SUBJECT_ID, $subjectId)
                ->where(SeoSuggestion::schema_fields_STATUS, SeoSuggestion::STATUS_ACTIVE)
                ->order(SeoSuggestion::schema_fields_CREATED_AT, 'DESC')
                ->find()
                ->fetch();
            
            if ($existingSuggestion->getId()) {
                return $existingSuggestion;
            }
        }

        // 获取关键词列表
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        $keywords = $keywordModel->reset()
            ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
            ->where(SeoKeyword::schema_fields_STATUS, SeoKeyword::STATUS_ENABLED)
            ->order(SeoKeyword::schema_fields_PRIORITY, 'DESC')
            ->select()
            ->fetchArray();

        $keywordList = array_column($keywords, 'keyword');

        // 构建 Prompt
        $prompt = $this->buildPrompt($subject, $keywordList);

        // 调用 AI 生成建议
        try {
            $response = $this->aiService->generateText(
                $prompt,
                null, // 使用默认模型
                'seo_keyword_planning', // 场景代码
                $subject->getLocale() ?: 'zh-CN',
                []
            );

            // 解析 AI 响应
            $suggestionData = $this->parseAiResponse($response);
        } catch (\Exception $e) {
            // AI 调用失败，使用默认建议
            $suggestionData = $this->getDefaultSuggestion($subject, $keywordList);
        }

        // 保存建议
        /** @var SeoSuggestion $suggestion */
        $suggestion = $this->objectManager->getInstance(SeoSuggestion::class);
        $suggestion->setSubjectId($subjectId)
            ->setContentArray($suggestionData['content'] ?? [])
            ->setKeywordsArray($suggestionData['keywords'] ?? $keywordList)
            ->setPriority($suggestionData['priority'] ?? 0)
            ->setStatus(SeoSuggestion::STATUS_ACTIVE)
            ->save();

        return $suggestion;
    }

    /**
     * 构建 AI Prompt
     * 
     * @param SeoSubject $subject
     * @param array $keywords
     * @return string
     */
    private function buildPrompt(SeoSubject $subject, array $keywords): string
    {
        $prompt = "请为以下内容提供SEO关键词建议：\n\n";
        $prompt .= "标题：{$subject->getTitle()}\n";
        $prompt .= "描述：{$subject->getDescription()}\n";
        
        if (!empty($keywords)) {
            $prompt .= "现有关键词：" . implode('、', $keywords) . "\n";
        }
        
        $prompt .= "\n请提供：\n";
        $prompt .= "1. 主要关键词（3-5个）\n";
        $prompt .= "2. 长尾关键词（5-10个）\n";
        $prompt .= "3. 关键词优先级说明\n";
        $prompt .= "4. SEO优化建议\n\n";
        $prompt .= "请以JSON格式返回，格式：{\"keywords\": [...], \"long_tail\": [...], \"priority\": {...}, \"suggestions\": [...]}";

        return $prompt;
    }

    /**
     * 解析 AI 响应
     * 
     * @param string $response
     * @return array
     */
    private function parseAiResponse(string $response): array
    {
        // 尝试提取 JSON
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if (is_array($json)) {
                return [
                    'keywords' => $json['keywords'] ?? [],
                    'long_tail' => $json['long_tail'] ?? [],
                    'priority' => $json['priority'] ?? [],
                    'suggestions' => $json['suggestions'] ?? [],
                    'content' => $json,
                ];
            }
        }

        // 如果无法解析 JSON，返回默认结构
        return [
            'keywords' => [],
            'long_tail' => [],
            'priority' => [],
            'suggestions' => [$response],
            'content' => ['raw_response' => $response],
        ];
    }

    /**
     * 获取默认建议（AI 调用失败时使用）
     * 
     * @param SeoSubject $subject
     * @param array $keywords
     * @return array
     */
    private function getDefaultSuggestion(SeoSubject $subject, array $keywords): array
    {
        return [
            'keywords' => $keywords,
            'long_tail' => [],
            'priority' => [],
            'suggestions' => ['请优化标题和描述，添加更多相关关键词'],
            'content' => [
                'type' => 'default',
                'message' => 'AI服务暂时不可用，请手动优化SEO',
            ],
        ];
    }
}

