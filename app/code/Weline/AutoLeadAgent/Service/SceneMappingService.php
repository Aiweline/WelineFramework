<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Service\SearchEngineMappingService;

/**
 * 场景映射服务
 * 
 * 负责管理画像特征到场景的映射规则，支持多语言/多地区
 */
class SceneMappingService
{
    /**
     * 获取场景映射规则（根据地区/语言）
     * 
     * @param string $region 地区（如：中国、美国、日本）
     * @param string $language 语言代码（如：zh、en、ja）
     * @return array 场景映射规则
     */
    public function getSceneMapping(string $region = '', string $language = ''): array
    {
        // 如果没有指定语言，从地区推断语言
        if (empty($language) && !empty($region)) {
            /** @var SearchEngineMappingService $mappingService */
            $mappingService = ObjectManager::getInstance(SearchEngineMappingService::class);
            $language = $mappingService->inferLanguageFromRegion($region);
        }
        
        // 默认使用中文
        if (empty($language)) {
            $language = 'zh';
        }
        
        // 标准化语言代码
        $language = strtolower($language);
        if (strpos($language, '-') !== false) {
            $language = explode('-', $language)[0];
        }
        
        // 根据语言返回对应的映射规则
        return $this->getMappingByLanguage($language);
    }
    
    /**
     * 根据语言获取映射规则
     * 
     * 只返回中文默认映射规则，其他语言通过Google翻译API动态翻译
     * 
     * @param string $language 语言代码（保留用于未来扩展）
     * @return array 映射规则（始终返回中文版本）
     */
    private function getMappingByLanguage(string $language): array
    {
        // 只保留中文默认映射规则，其他语言通过前端Google翻译API动态翻译
        return [
            '时尚' => ['时尚杂志', '时尚博客', '时尚论坛', '时尚群组', '时尚分享', '时尚博主', '时尚社区', '时尚资讯', '时尚潮流'],
            '女性' => ['女性社区', '女性论坛', '女性群组', '美妆社区', '穿搭分享', '女性生活', '女性时尚'],
            '美妆' => ['美妆社区', '美妆博主', '美妆论坛', '化妆品评测', '美妆分享', '美妆教程', '美妆资讯'],
            '穿搭' => ['穿搭分享', '穿搭社区', '穿搭博主', '穿搭论坛', '时尚穿搭', '搭配分享'],
            '高端' => ['奢侈品论坛', '高端消费社区', '定制服务论坛', '精品社区', '高端生活', '精品分享'],
            '定制' => ['定制服务社区', '个性化定制论坛', '定制需求群组', '定制分享', '定制服务'],
            '奢侈品' => ['奢侈品论坛', '奢侈品分享', '高端消费群组', '奢侈品资讯', '奢侈品社区'],
            '精品' => ['精品社区', '精品分享', '精品论坛', '精品生活'],
            '科技' => ['科技论坛', '技术社区', '开发者社区', '科技博客', 'IT论坛', '技术分享', '科技资讯'],
            '教育' => ['教育论坛', '学习社区', '教育工作者群组', '培训社区', '教育分享', '学习交流'],
            '医疗' => ['医疗论坛', '健康社区', '医疗工作者群组', '健康分享', '医疗资讯', '健康生活'],
            '金融' => ['金融论坛', '投资社区', '金融资讯', '理财分享', '金融交流'],
            '房地产' => ['房产论坛', '房地产社区', '房产资讯', '房产分享', '购房交流'],
            '汽车' => ['汽车论坛', '汽车社区', '汽车资讯', '汽车分享', '车友会'],
            '商务' => ['商务论坛', '商业社区', '企业家群组', '商业交流', '商务资讯'],
            '创业' => ['创业论坛', '创业者社区', '创业群组', '创业分享', '创业交流'],
            '投资' => ['投资论坛', '投资社区', '投资者群组', '投资交流', '投资分享'],
            '企业管理' => ['管理论坛', '企业管理社区', '管理分享', '管理交流'],
            '健康' => ['健康社区', '健康论坛', '健康分享', '健康生活', '养生社区'],
            '运动' => ['运动社区', '运动论坛', '运动分享', '健身社区', '运动交流'],
            '旅游' => ['旅游论坛', '旅游社区', '旅游分享', '旅游攻略', '旅行交流'],
            '美食' => ['美食社区', '美食论坛', '美食分享', '美食博客', '美食交流'],
            '摄影' => ['摄影论坛', '摄影社区', '摄影分享', '摄影交流', '摄影作品'],
            'activityWords' => ['评论', '发帖', '分享', '讨论', '参与', '活跃', '关注', '点赞', '转发', '互动', '留言', '发布'],
            'roleWords' => ['读者', '用户', '成员', '粉丝', '关注者', '参与者', '活跃用户', '会员'],
            '_communitySuffix' => '社区' // 通用场景后缀
        ];
    }
    
    /**
     * 获取所有支持的语言
     * 
     * @return array
     */
    public function getSupportedLanguages(): array
    {
        return ['zh', 'en', 'ja'];
    }
}

