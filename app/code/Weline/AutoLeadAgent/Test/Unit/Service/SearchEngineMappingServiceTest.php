<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Service\SearchEngineMappingService;
use Weline\AutoLeadAgent\Model\SearchEngineMapping;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索引擎映射服务单元测试
 */
class SearchEngineMappingServiceTest extends TestCase
{
    private SearchEngineMappingService $service;
    private SearchEngineMapping $mappingModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SearchEngineMappingService();
        $this->mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
        
        // 清除缓存
        SearchEngineMappingService::clearCache();
    }

    /**
     * 测试中国+中文的搜索引擎映射
     */
    public function testGetSearchEnginesForChinaChinese(): void
    {
        // 确保数据库中有测试数据
        $this->ensureTestMapping('中国', 'zh', ['Baidu', '360搜索', '搜狗']);
        
        $engines = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh');
        $this->assertIsArray($engines);
        $this->assertNotEmpty($engines);
        // 验证至少包含一个中文搜索引擎
        $this->assertTrue(
            in_array('Baidu', $engines) || 
            in_array('360搜索', $engines) || 
            in_array('搜狗', $engines)
        );
    }
    
    /**
     * 确保测试映射存在
     */
    private function ensureTestMapping(string $region, string $language, array $engines): void
    {
        $existing = $this->mappingModel->clear()
            ->where(SearchEngineMapping::fields_REGION, $region)
            ->where(SearchEngineMapping::fields_LANGUAGE, $language)
            ->find()
            ->fetch();
        
        if (!$existing || !$existing->getId()) {
            $this->mappingModel->clear()
                ->setData(SearchEngineMapping::fields_REGION, $region)
                ->setData(SearchEngineMapping::fields_LANGUAGE, $language)
                ->setSearchEnginesArray($engines)
                ->setData(SearchEngineMapping::fields_IS_ACTIVE, 1)
                ->setData(SearchEngineMapping::fields_SORT_ORDER, 9999)
                ->save();
            
            // 清除缓存
            SearchEngineMappingService::clearCache();
        }
    }

    /**
     * 测试美国+英文的搜索引擎映射
     */
    public function testGetSearchEnginesForUSAEnglish(): void
    {
        $engines = $this->service->getSearchEnginesByRegionAndLanguage('美国', 'en');
        $this->assertIsArray($engines);
        $this->assertContains('Google', $engines);
        $this->assertContains('Bing', $engines);
        $this->assertContains('DuckDuckGo', $engines);
    }

    /**
     * 测试俄罗斯+俄文的搜索引擎映射
     */
    public function testGetSearchEnginesForRussiaRussian(): void
    {
        $engines = $this->service->getSearchEnginesByRegionAndLanguage('俄罗斯', 'ru');
        $this->assertIsArray($engines);
        $this->assertContains('Yandex', $engines);
        $this->assertContains('Google', $engines);
    }

    /**
     * 测试地区别名映射
     */
    public function testRegionAliasMapping(): void
    {
        // 测试英文别名
        $engines1 = $this->service->getSearchEnginesByRegionAndLanguage('China', 'zh');
        $engines2 = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh');
        $this->assertEquals($engines1, $engines2);

        // 测试代码别名
        $engines3 = $this->service->getSearchEnginesByRegionAndLanguage('CN', 'zh');
        $this->assertEquals($engines1, $engines3);
    }

    /**
     * 测试语言别名映射
     */
    public function testLanguageAliasMapping(): void
    {
        // 测试中文别名
        $engines1 = $this->service->getSearchEnginesByRegionAndLanguage('中国', '中文');
        $engines2 = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh');
        $this->assertEquals($engines1, $engines2);

        // 测试英文别名
        $engines3 = $this->service->getSearchEnginesByRegionAndLanguage('美国', '英文');
        $engines4 = $this->service->getSearchEnginesByRegionAndLanguage('美国', 'en');
        $this->assertEquals($engines3, $engines4);
    }

    /**
     * 测试语言代码标准化
     */
    public function testNormalizeLanguage(): void
    {
        $this->assertEquals('zh', $this->service->normalizeLanguage('中文'));
        $this->assertEquals('zh-CN', $this->service->normalizeLanguage('简体中文'));
        $this->assertEquals('en', $this->service->normalizeLanguage('英文'));
        $this->assertEquals('en', $this->service->normalizeLanguage('英语'));
        $this->assertEquals('ja', $this->service->normalizeLanguage('日文'));
        $this->assertEquals('ru', $this->service->normalizeLanguage('俄文'));
    }

    /**
     * 测试地区代码标准化
     */
    public function testNormalizeRegion(): void
    {
        $this->assertEquals('中国', $this->service->normalizeRegion('China'));
        $this->assertEquals('中国', $this->service->normalizeRegion('CN'));
        $this->assertEquals('美国', $this->service->normalizeRegion('United States'));
        $this->assertEquals('美国', $this->service->normalizeRegion('USA'));
        $this->assertEquals('美国', $this->service->normalizeRegion('US'));
    }

    /**
     * 测试从地区推断语言
     */
    public function testInferLanguageFromRegion(): void
    {
        $this->assertEquals('zh', $this->service->inferLanguageFromRegion('中国'));
        $this->assertEquals('ja', $this->service->inferLanguageFromRegion('日本'));
        $this->assertEquals('ko', $this->service->inferLanguageFromRegion('韩国'));
        $this->assertEquals('ru', $this->service->inferLanguageFromRegion('俄罗斯'));
        $this->assertEquals('en', $this->service->inferLanguageFromRegion('美国'));
        $this->assertEquals('en', $this->service->inferLanguageFromRegion('未知地区')); // 默认返回英文
    }

    /**
     * 测试根据语言获取搜索引擎（无地区匹配时）
     */
    public function testGetSearchEnginesByLanguage(): void
    {
        // 中文
        $engines = $this->service->getSearchEnginesByLanguage('zh');
        $this->assertContains('Baidu', $engines);
        $this->assertContains('360搜索', $engines);
        $this->assertContains('搜狗', $engines);

        // 俄文
        $engines = $this->service->getSearchEnginesByLanguage('ru');
        $this->assertContains('Yandex', $engines);
        $this->assertContains('Google', $engines);

        // 英文（默认）
        $engines = $this->service->getSearchEnginesByLanguage('en');
        $this->assertContains('Google', $engines);
        $this->assertContains('Bing', $engines);
        $this->assertContains('DuckDuckGo', $engines);
    }

    /**
     * 测试不存在的地区+语言组合（应返回默认搜索引擎）
     */
    public function testUnknownRegionLanguageCombination(): void
    {
        $engines = $this->service->getSearchEnginesByRegionAndLanguage('未知地区', '未知语言');
        $this->assertIsArray($engines);
        $this->assertNotEmpty($engines);
        // 应该返回基于语言的搜索引擎或默认搜索引擎
    }

    /**
     * 测试多语言代码格式（zh-CN, zh-TW等）
     */
    public function testLanguageCodeVariants(): void
    {
        // zh-CN 应该匹配 zh
        $engines1 = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh-CN');
        $engines2 = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh');
        $this->assertEquals($engines1, $engines2);

        // zh-Hans 应该匹配 zh
        $engines3 = $this->service->getSearchEnginesByRegionAndLanguage('中国', 'zh-Hans');
        $this->assertEquals($engines1, $engines3);
    }

    /**
     * 测试获取所有支持的地区
     */
    public function testGetSupportedRegions(): void
    {
        $regions = $this->service->getSupportedRegions();
        $this->assertIsArray($regions);
        $this->assertContains('中国', $regions);
        $this->assertContains('美国', $regions);
        $this->assertContains('俄罗斯', $regions);
    }

    /**
     * 测试获取所有支持的语言
     */
    public function testGetSupportedLanguages(): void
    {
        $languages = $this->service->getSupportedLanguages();
        $this->assertIsArray($languages);
        $this->assertContains('zh', $languages);
        $this->assertContains('en', $languages);
        $this->assertContains('ru', $languages);
    }
}

