<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\AutoLeadAgent\Model\AgentToken;
use Weline\AutoLeadAgent\Model\WasmHash;
use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\AutoLeadAgent\Model\SearchTask;
use Weline\AutoLeadAgent\Model\SearchEngineMapping;
use Weline\AutoLeadAgent\Model\TargetWebsite;

class Install implements InstallInterface
{
    /**
     * 安装模块
     * 
     * @param Setup $setup
     * @param Context $context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 安装Token表
        /** @var AgentToken $agentToken */
        $agentToken = ObjectManager::getInstance(AgentToken::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($agentToken);
        $agentToken->setup($modelSetup, $context);
        
        // 安装WASM哈希表
        /** @var WasmHash $wasmHash */
        $wasmHash = ObjectManager::getInstance(WasmHash::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($wasmHash);
        $wasmHash->setup($modelSetup, $context);
        
        // 安装潜在客户表
        /** @var LeadCandidate $leadCandidate */
        $leadCandidate = ObjectManager::getInstance(LeadCandidate::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($leadCandidate);
        $leadCandidate->setup($modelSetup, $context);
        
        // 安装搜索任务表
        /** @var SearchTask $searchTask */
        $searchTask = ObjectManager::getInstance(SearchTask::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($searchTask);
        $searchTask->setup($modelSetup, $context);
        
        // 安装搜索引擎映射表
        /** @var SearchEngineMapping $mappingModel */
        $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($mappingModel);
        $mappingModel->setup($modelSetup, $context);
        
        // 初始化默认映射数据
        $this->initDefaultMappings($mappingModel, $context);
        
        // 安装搜索目标网站表
        /** @var TargetWebsite $targetWebsite */
        $targetWebsite = ObjectManager::getInstance(TargetWebsite::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($targetWebsite);
        $targetWebsite->setup($modelSetup, $context);
        
        // 初始化默认目标网站数据
        $this->initDefaultTargetWebsites($targetWebsite, $context);
    }
    
    /**
     * 初始化默认映射数据
     * 
     * @param SearchEngineMapping $mappingModel
     * @param Context $context
     * @return void
     */
    private function initDefaultMappings(SearchEngineMapping $mappingModel, Context $context): void
    {
        // 检查是否已有数据
        $existingCount = $mappingModel->clear()->count();
        if ($existingCount > 0) {
            $context->getPrinter()->note(__('搜索引擎映射表已有数据，跳过初始化'));
            return;
        }
        
        $context->getPrinter()->setup(__('开始初始化默认搜索引擎映射数据...'));
        
        // 默认映射数据（全球常见国家和地区）
        $defaultMappings = [
            // 中国
            ['region' => '中国', 'language' => 'zh', 'engines' => ['Baidu', '360搜索', '搜狗'], 'sort' => 10],
            ['region' => '中国', 'language' => 'zh-CN', 'engines' => ['Baidu', '360搜索', '搜狗'], 'sort' => 11],
            ['region' => '中国', 'language' => 'zh-Hans', 'engines' => ['Baidu', '360搜索', '搜狗'], 'sort' => 12],
            ['region' => '中国', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 13],
            
            // 美国
            ['region' => '美国', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 20],
            ['region' => '美国', 'language' => 'es', 'engines' => ['Google', 'Bing'], 'sort' => 21],
            
            // 俄罗斯
            ['region' => '俄罗斯', 'language' => 'ru', 'engines' => ['Yandex', 'Google'], 'sort' => 30],
            ['region' => '俄罗斯', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 31],
            
            // 日本
            ['region' => '日本', 'language' => 'ja', 'engines' => ['Google', 'Bing'], 'sort' => 40],
            ['region' => '日本', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 41],
            
            // 韩国
            ['region' => '韩国', 'language' => 'ko', 'engines' => ['Google', 'Bing'], 'sort' => 50],
            ['region' => '韩国', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 51],
            
            // 英国
            ['region' => '英国', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 60],
            
            // 加拿大
            ['region' => '加拿大', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 70],
            ['region' => '加拿大', 'language' => 'fr', 'engines' => ['Google', 'Bing'], 'sort' => 71],
            
            // 澳大利亚
            ['region' => '澳大利亚', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 80],
            
            // 德国
            ['region' => '德国', 'language' => 'de', 'engines' => ['Google', 'Bing'], 'sort' => 90],
            ['region' => '德国', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 91],
            
            // 法国
            ['region' => '法国', 'language' => 'fr', 'engines' => ['Google', 'Bing'], 'sort' => 100],
            ['region' => '法国', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 101],
            
            // 西班牙
            ['region' => '西班牙', 'language' => 'es', 'engines' => ['Google', 'Bing'], 'sort' => 110],
            ['region' => '西班牙', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 111],
            
            // 意大利
            ['region' => '意大利', 'language' => 'it', 'engines' => ['Google', 'Bing'], 'sort' => 120],
            ['region' => '意大利', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 121],
            
            // 印度
            ['region' => '印度', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 130],
            ['region' => '印度', 'language' => 'hi', 'engines' => ['Google', 'Bing'], 'sort' => 131],
            
            // 巴西
            ['region' => '巴西', 'language' => 'pt', 'engines' => ['Google', 'Bing'], 'sort' => 140],
            ['region' => '巴西', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 141],
            
            // 墨西哥
            ['region' => '墨西哥', 'language' => 'es', 'engines' => ['Google', 'Bing'], 'sort' => 150],
            ['region' => '墨西哥', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 151],
            
            // 阿根廷
            ['region' => '阿根廷', 'language' => 'es', 'engines' => ['Google', 'Bing'], 'sort' => 160],
            ['region' => '阿根廷', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 161],
            
            // 荷兰
            ['region' => '荷兰', 'language' => 'nl', 'engines' => ['Google', 'Bing'], 'sort' => 170],
            ['region' => '荷兰', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 171],
            
            // 比利时
            ['region' => '比利时', 'language' => 'fr', 'engines' => ['Google', 'Bing'], 'sort' => 180],
            ['region' => '比利时', 'language' => 'nl', 'engines' => ['Google', 'Bing'], 'sort' => 181],
            ['region' => '比利时', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 182],
            
            // 瑞士
            ['region' => '瑞士', 'language' => 'de', 'engines' => ['Google', 'Bing'], 'sort' => 190],
            ['region' => '瑞士', 'language' => 'fr', 'engines' => ['Google', 'Bing'], 'sort' => 191],
            ['region' => '瑞士', 'language' => 'it', 'engines' => ['Google', 'Bing'], 'sort' => 192],
            ['region' => '瑞士', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 193],
            
            // 瑞典
            ['region' => '瑞典', 'language' => 'sv', 'engines' => ['Google', 'Bing'], 'sort' => 200],
            ['region' => '瑞典', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 201],
            
            // 挪威
            ['region' => '挪威', 'language' => 'no', 'engines' => ['Google', 'Bing'], 'sort' => 210],
            ['region' => '挪威', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 211],
            
            // 丹麦
            ['region' => '丹麦', 'language' => 'da', 'engines' => ['Google', 'Bing'], 'sort' => 220],
            ['region' => '丹麦', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 221],
            
            // 芬兰
            ['region' => '芬兰', 'language' => 'fi', 'engines' => ['Google', 'Bing'], 'sort' => 230],
            ['region' => '芬兰', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 231],
            
            // 波兰
            ['region' => '波兰', 'language' => 'pl', 'engines' => ['Google', 'Bing'], 'sort' => 240],
            ['region' => '波兰', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 241],
            
            // 土耳其
            ['region' => '土耳其', 'language' => 'tr', 'engines' => ['Google', 'Bing'], 'sort' => 250],
            ['region' => '土耳其', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 251],
            
            // 沙特阿拉伯
            ['region' => '沙特阿拉伯', 'language' => 'ar', 'engines' => ['Google', 'Bing'], 'sort' => 260],
            ['region' => '沙特阿拉伯', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 261],
            
            // 阿联酋
            ['region' => '阿联酋', 'language' => 'ar', 'engines' => ['Google', 'Bing'], 'sort' => 270],
            ['region' => '阿联酋', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 271],
            
            // 以色列
            ['region' => '以色列', 'language' => 'he', 'engines' => ['Google', 'Bing'], 'sort' => 280],
            ['region' => '以色列', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 281],
            
            // 南非
            ['region' => '南非', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 290],
            ['region' => '南非', 'language' => 'af', 'engines' => ['Google', 'Bing'], 'sort' => 291],
            
            // 埃及
            ['region' => '埃及', 'language' => 'ar', 'engines' => ['Google', 'Bing'], 'sort' => 300],
            ['region' => '埃及', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 301],
            
            // 泰国
            ['region' => '泰国', 'language' => 'th', 'engines' => ['Google', 'Bing'], 'sort' => 310],
            ['region' => '泰国', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 311],
            
            // 越南
            ['region' => '越南', 'language' => 'vi', 'engines' => ['Google', 'Bing'], 'sort' => 320],
            ['region' => '越南', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 321],
            
            // 印度尼西亚
            ['region' => '印度尼西亚', 'language' => 'id', 'engines' => ['Google', 'Bing'], 'sort' => 330],
            ['region' => '印度尼西亚', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 331],
            
            // 马来西亚
            ['region' => '马来西亚', 'language' => 'ms', 'engines' => ['Google', 'Bing'], 'sort' => 340],
            ['region' => '马来西亚', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 341],
            
            // 新加坡
            ['region' => '新加坡', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 350],
            ['region' => '新加坡', 'language' => 'zh', 'engines' => ['Google', 'Bing'], 'sort' => 351],
            ['region' => '新加坡', 'language' => 'ms', 'engines' => ['Google', 'Bing'], 'sort' => 352],
            
            // 菲律宾
            ['region' => '菲律宾', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 360],
            ['region' => '菲律宾', 'language' => 'tl', 'engines' => ['Google', 'Bing'], 'sort' => 361],
            
            // 新西兰
            ['region' => '新西兰', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 370],
            
            // 欧洲（通用）
            ['region' => '欧洲', 'language' => 'en', 'engines' => ['Google', 'Bing', 'DuckDuckGo'], 'sort' => 400],
            ['region' => '欧洲', 'language' => 'de', 'engines' => ['Google', 'Bing'], 'sort' => 401],
            ['region' => '欧洲', 'language' => 'fr', 'engines' => ['Google', 'Bing'], 'sort' => 402],
            ['region' => '欧洲', 'language' => 'es', 'engines' => ['Google', 'Bing'], 'sort' => 403],
            ['region' => '欧洲', 'language' => 'it', 'engines' => ['Google', 'Bing'], 'sort' => 404],
            
            // 中东（通用）
            ['region' => '中东', 'language' => 'ar', 'engines' => ['Google', 'Bing'], 'sort' => 500],
            ['region' => '中东', 'language' => 'en', 'engines' => ['Google', 'Bing'], 'sort' => 501],
        ];
        
        $insertedCount = 0;
        foreach ($defaultMappings as $mapping) {
            try {
                $mappingModel->clear()
                    ->setData(SearchEngineMapping::schema_fields_REGION, $mapping['region'])
                    ->setData(SearchEngineMapping::schema_fields_LANGUAGE, $mapping['language'])
                    ->setSearchEnginesArray($mapping['engines'])
                    ->setData(SearchEngineMapping::schema_fields_IS_ACTIVE, 1)
                    ->setData(SearchEngineMapping::schema_fields_SORT_ORDER, $mapping['sort'])
                    ->save();
                $insertedCount++;
            } catch (\Throwable $e) {
                // 忽略重复键错误（可能已存在）
                // MySQL: "Duplicate entry"
                // PostgreSQL: "duplicate key value violates unique constraint"
                $errorMessage = $e->getMessage();
                $isDuplicateError = (
                    strpos($errorMessage, 'Duplicate entry') !== false ||
                    strpos($errorMessage, 'duplicate key value violates unique constraint') !== false ||
                    strpos($errorMessage, 'SQLSTATE[23505]') !== false
                );
                if (!$isDuplicateError) {
                    $context->getPrinter()->warning(__('插入映射失败：%{1} - %{2}', [$mapping['region'], $errorMessage]));
                }
            }
        }
        
        $context->getPrinter()->success(__('默认映射数据初始化完成，共插入 %{1} 条记录', [$insertedCount]));
    }
    
    /**
     * 初始化默认目标网站数据
     * 
     * @param TargetWebsite $targetWebsiteModel
     * @param Context $context
     * @return void
     */
    private function initDefaultTargetWebsites(TargetWebsite $targetWebsiteModel, Context $context): void
    {
        // 检查是否已有数据
        $existingCount = $targetWebsiteModel->clear()->count();
        if ($existingCount > 0) {
            $context->getPrinter()->note(__('搜索目标网站表已有数据，跳过初始化'));
            return;
        }
        
        $context->getPrinter()->setup(__('开始初始化默认搜索目标网站数据...'));
        
        // 默认目标网站数据
        $defaultWebsites = [
            [
                'name' => 'LinkedIn',
                'domain' => 'linkedin.com',
                'search_syntax_template' => 'site:linkedin.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 10,
                'description' => 'LinkedIn - 全球最大的职业社交网络平台',
                'icon_url' => null
            ],
            [
                'name' => 'Facebook',
                'domain' => 'facebook.com',
                'search_syntax_template' => 'site:facebook.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 20,
                'description' => 'Facebook - 全球最大的社交网络平台',
                'icon_url' => null
            ],
            [
                'name' => 'Twitter/X',
                'domain' => 'twitter.com',
                'search_syntax_template' => 'site:twitter.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 30,
                'description' => 'Twitter/X - 全球流行的社交媒体平台',
                'icon_url' => null
            ],
            [
                'name' => 'Instagram',
                'domain' => 'instagram.com',
                'search_syntax_template' => 'site:instagram.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 40,
                'description' => 'Instagram - 图片和视频分享社交平台',
                'icon_url' => null
            ],
            [
                'name' => 'YouTube',
                'domain' => 'youtube.com',
                'search_syntax_template' => 'site:youtube.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 50,
                'description' => 'YouTube - 全球最大的视频分享平台',
                'icon_url' => null
            ],
            [
                'name' => 'GitHub',
                'domain' => 'github.com',
                'search_syntax_template' => 'site:github.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 60,
                'description' => 'GitHub - 全球最大的代码托管平台',
                'icon_url' => null
            ],
            [
                'name' => 'Reddit',
                'domain' => 'reddit.com',
                'search_syntax_template' => 'site:reddit.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 70,
                'description' => 'Reddit - 全球流行的社区论坛平台',
                'icon_url' => null
            ],
            [
                'name' => 'Pinterest',
                'domain' => 'pinterest.com',
                'search_syntax_template' => 'site:pinterest.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 80,
                'description' => 'Pinterest - 图片分享和发现平台',
                'icon_url' => null
            ],
            [
                'name' => 'TikTok',
                'domain' => 'tiktok.com',
                'search_syntax_template' => 'site:tiktok.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 90,
                'description' => 'TikTok - 短视频社交平台',
                'icon_url' => null
            ],
            [
                'name' => 'Medium',
                'domain' => 'medium.com',
                'search_syntax_template' => 'site:medium.com "{keyword1}" "{keyword2}"',
                'is_active' => 1,
                'sort_order' => 100,
                'description' => 'Medium - 内容发布和阅读平台',
                'icon_url' => null
            ],
        ];
        
        $insertedCount = 0;
        foreach ($defaultWebsites as $website) {
            try {
                $targetWebsiteModel->clear()
                    ->setData(TargetWebsite::schema_fields_NAME, $website['name'])
                    ->setData(TargetWebsite::schema_fields_DOMAIN, $website['domain'])
                    ->setData(TargetWebsite::schema_fields_SEARCH_SYNTAX_TEMPLATE, $website['search_syntax_template'])
                    ->setData(TargetWebsite::schema_fields_IS_ACTIVE, $website['is_active'])
                    ->setData(TargetWebsite::schema_fields_SORT_ORDER, $website['sort_order'])
                    ->setData(TargetWebsite::schema_fields_DESCRIPTION, $website['description'])
                    ->setData(TargetWebsite::schema_fields_ICON_URL, $website['icon_url'])
                    ->save();
                $insertedCount++;
            } catch (\Throwable $e) {
                // 忽略重复键错误（可能已存在）
                // MySQL: "Duplicate entry"
                // PostgreSQL: "duplicate key value violates unique constraint"
                $errorMessage = $e->getMessage();
                $isDuplicateError = (
                    strpos($errorMessage, 'Duplicate entry') !== false ||
                    strpos($errorMessage, 'duplicate key value violates unique constraint') !== false ||
                    strpos($errorMessage, 'SQLSTATE[23505]') !== false
                );
                if (!$isDuplicateError) {
                    $context->getPrinter()->warning(__('插入目标网站失败：%{1} - %{2}', [$website['name'], $errorMessage]));
                }
            }
        }
        
        $context->getPrinter()->success(__('默认目标网站数据初始化完成，共插入 %{1} 条记录', [$insertedCount]));
    }
}

