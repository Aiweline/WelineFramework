<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoKeyword;

/**
 * 关键词提取服务
 * 
 * 从描述文本中提取关键词
 * 
 * @package Weline_Seo
 */
class KeywordExtractorService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 从文本中提取关键词
     * 
     * @param string $text 文本内容
     * @param array $existingKeywords 已有关键词（用于去重）
     * @return array 关键词数组
     */
    public function extract(string $text, array $existingKeywords = []): array
    {
        if (empty($text)) {
            return [];
        }

        $keywords = [];
        
        // 1. 从 meta_keywords 字段提取（如果文本中包含逗号分隔的关键词）
        if (strpos($text, ',') !== false) {
            $parts = explode(',', $text);
            foreach ($parts as $part) {
                $keyword = trim($part);
                if (!empty($keyword) && strlen($keyword) > 1) {
                    $keywords[] = $keyword;
                }
            }
        }

        // 2. 简单分词（中文和英文）
        // 这里使用简单的规则，实际可以使用更复杂的NLP库或AI服务
        $words = $this->simpleTokenize($text);
        $keywords = array_merge($keywords, $words);

        // 去重和过滤
        $keywords = array_unique(array_filter($keywords));
        $keywords = array_diff($keywords, $existingKeywords);
        
        // 过滤太短或太长的关键词
        $keywords = array_filter($keywords, function($keyword) {
            $len = mb_strlen($keyword, 'UTF-8');
            return $len >= 2 && $len <= 50;
        });

        return array_values($keywords);
    }

    /**
     * 简单分词（基础实现）
     * 
     * @param string $text
     * @return array
     */
    private function simpleTokenize(string $text): array
    {
        $words = [];
        
        // 提取英文单词
        preg_match_all('/\b[a-zA-Z]{2,}\b/', $text, $englishMatches);
        $words = array_merge($words, $englishMatches[0] ?? []);
        
        // 提取中文词汇（简单按字符分割，实际应使用分词库）
        // 这里只提取2-4字的中文词组
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,4}/u', $text, $chineseMatches);
        $words = array_merge($words, $chineseMatches[0] ?? []);
        
        return array_unique($words);
    }

    /**
     * 保存关键词到数据库
     * 
     * @param int $subjectId 主体ID
     * @param array $keywords 关键词数组
     * @param string $source 来源
     * @return void
     */
    public function saveKeywords(int $subjectId, array $keywords, string $source = SeoKeyword::SOURCE_EXTRACTED): void
    {
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        
        foreach ($keywords as $keyword) {
            $keywordModel->reset()
                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
                ->where(SeoKeyword::schema_fields_KEYWORD, $keyword)
                ->find()
                ->fetch();
            
            if (!$keywordModel->getId()) {
                $keywordModel->reset()
                    ->setSubjectId($subjectId)
                    ->setKeyword($keyword)
                    ->setSource($source)
                    ->setStatus(SeoKeyword::STATUS_ENABLED)
                    ->setPriority(0)
                    ->save();
            }
        }
    }
}

