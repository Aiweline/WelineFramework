<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\Framework\App\Exception;

/**
 * 店铺画像服务类
 * 
 * 负责从店铺描述中提取和分析客户画像
 */
class StoreProfileService
{
    /**
     * 从店铺描述提取客户画像
     * 
     * @param int $storeId 店铺ID
     * @return array 客户画像数据
     * @throws Exception
     */
    public function extractProfile(int $storeId): array
    {
        try {
            $store = w_query('store', 'getStoreById', ['store_id' => $storeId]);

            if ($store === null) {
                throw new Exception(__('店铺不存在：%{1}', [(string)$storeId]));
            }

            // 提取店铺信息
            $description = $store['description'] ?? '';
            $name = $store['name'] ?? '';
            $metaDescription = $store['meta_description'] ?? '';
            $metaKeywords = $store['meta_keywords'] ?? '';

            // 合并所有文本内容
            $textContent = implode(' ', array_filter([
                $name,
                $description,
                $metaDescription,
                $metaKeywords
            ]));

            // 提取关键特征
            $profile = [
                'store_id' => $storeId,
                'store_name' => $name,
                'text_content' => $textContent,
                'industry' => $this->extractIndustry($textContent),
                'target_customers' => $this->extractTargetCustomers($textContent),
                'product_features' => $this->extractProductFeatures($textContent),
                'keywords' => $this->extractKeywords($textContent),
                'location' => [
                    'address' => $store['address'] ?? '',
                    'latitude' => $store['latitude'] ?? '',
                    'longitude' => $store['longitude'] ?? '',
                ],
                'extracted_at' => date('Y-m-d H:i:s'),
            ];

            return $profile;

        } catch (\Exception $e) {
            throw new Exception(__('提取店铺画像失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 分析客户画像特征
     * 
     * @param array $profile 客户画像数据
     * @return array 分析后的特征向量
     */
    public function analyzeProfile(array $profile): array
    {
        $textContent = $profile['text_content'] ?? '';
        
        // 基础特征提取
        $features = [
            'word_count' => str_word_count($textContent),
            'char_count' => mb_strlen($textContent),
            'industry_keywords' => $this->countKeywords($textContent, $this->getIndustryKeywords()),
            'customer_keywords' => $this->countKeywords($textContent, $this->getCustomerKeywords()),
            'product_keywords' => $this->countKeywords($textContent, $this->getProductKeywords()),
        ];

        // 生成特征向量（简化版，实际应该使用更复杂的NLP算法）
        $vector = [];
        foreach ($features as $key => $value) {
            $vector[] = is_numeric($value) ? (float)$value : 0.0;
        }

        return [
            'features' => $features,
            'vector' => $vector,
            'profile' => $profile,
        ];
    }

    /**
     * 提取行业信息
     * 
     * @param string $text 文本内容
     * @return string 行业
     */
    private function extractIndustry(string $text): string
    {
        $industries = ['零售', '电商', '餐饮', '服务', '制造', '科技', '金融', '教育', '医疗', '旅游'];
        
        foreach ($industries as $industry) {
            if (mb_strpos($text, $industry) !== false) {
                return $industry;
            }
        }
        
        return __('其他');
    }

    /**
     * 提取目标客户
     * 
     * @param string $text 文本内容
     * @return array 目标客户列表
     */
    private function extractTargetCustomers(string $text): array
    {
        $customers = ['个人', '企业', '学生', '家庭', '老年人', '年轻人', '女性', '男性'];
        $found = [];
        
        foreach ($customers as $customer) {
            if (mb_strpos($text, $customer) !== false) {
                $found[] = $customer;
            }
        }
        
        return $found ?: [__('通用')];
    }

    /**
     * 提取产品特点
     * 
     * @param string $text 文本内容
     * @return array 产品特点列表
     */
    private function extractProductFeatures(string $text): array
    {
        $features = ['价格', '质量', '服务', '品牌', '创新', '便捷', '专业', '定制'];
        $found = [];
        
        foreach ($features as $feature) {
            if (mb_strpos($text, $feature) !== false) {
                $found[] = $feature;
            }
        }
        
        return $found;
    }

    /**
     * 提取关键词
     * 
     * @param string $text 文本内容
     * @return array 关键词列表
     */
    private function extractKeywords(string $text): array
    {
        // 简单的关键词提取（实际应该使用更复杂的NLP算法）
        $words = preg_split('/[\s,，。！？；：、]+/u', $text);
        $words = array_filter($words, function($word) {
            return mb_strlen($word) >= 2;
        });
        
        $wordCount = array_count_values($words);
        arsort($wordCount);
        
        return array_slice(array_keys($wordCount), 0, 10);
    }

    /**
     * 统计关键词出现次数
     * 
     * @param string $text 文本内容
     * @param array $keywords 关键词列表
     * @return int 出现次数
     */
    private function countKeywords(string $text, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $keyword) {
            $count += mb_substr_count($text, $keyword);
        }
        return $count;
    }

    /**
     * 获取行业关键词列表
     * 
     * @return array
     */
    private function getIndustryKeywords(): array
    {
        return ['零售', '电商', '餐饮', '服务', '制造', '科技', '金融', '教育', '医疗', '旅游'];
    }

    /**
     * 获取客户关键词列表
     * 
     * @return array
     */
    private function getCustomerKeywords(): array
    {
        return ['个人', '企业', '学生', '家庭', '客户', '用户', '消费者'];
    }

    /**
     * 获取产品关键词列表
     * 
     * @return array
     */
    private function getProductKeywords(): array
    {
        return ['产品', '商品', '服务', '质量', '价格', '品牌', '创新'];
    }
}

