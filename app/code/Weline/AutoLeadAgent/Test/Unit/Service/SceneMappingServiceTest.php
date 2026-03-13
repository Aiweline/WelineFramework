<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Service\SceneMappingService;

class SceneMappingServiceTest extends TestCase
{
    private SceneMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SceneMappingService();
    }

    public function testGetSceneMappingWithChineseLanguage(): void
    {
        $mapping = $this->service->getSceneMapping('', 'zh');
        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('时尚', $mapping);
        $this->assertArrayHasKey('activityWords', $mapping);
        $this->assertArrayHasKey('roleWords', $mapping);
        $this->assertArrayHasKey('_communitySuffix', $mapping);
        $this->assertIsArray($mapping['时尚']);
        $this->assertContains('时尚社区', $mapping['时尚']);
    }

    public function testGetSceneMappingWithEnglishLanguage(): void
    {
        $mapping = $this->service->getSceneMapping('', 'en');
        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('时尚', $mapping);
        $this->assertArrayHasKey('科技', $mapping);
    }

    public function testGetSceneMappingWithLanguageCodeVariant(): void
    {
        $mapping = $this->service->getSceneMapping('', 'zh-CN');
        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('美妆', $mapping);
    }

    public function testGetSceneMappingEmptyInputDefaultsToChinese(): void
    {
        $mapping = $this->service->getSceneMapping('', '');
        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('activityWords', $mapping);
        $this->assertEquals(['评论', '发帖', '分享', '讨论', '参与', '活跃', '关注', '点赞', '转发', '互动', '留言', '发布'], $mapping['activityWords']);
    }

    public function testGetSupportedLanguages(): void
    {
        $languages = $this->service->getSupportedLanguages();
        $this->assertIsArray($languages);
        $this->assertContains('zh', $languages);
        $this->assertContains('en', $languages);
        $this->assertContains('ja', $languages);
        $this->assertCount(3, $languages);
    }

    public function testMappingContainsRequiredKeys(): void
    {
        $mapping = $this->service->getSceneMapping('', 'zh');
        $required = ['activityWords', 'roleWords', '_communitySuffix'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $mapping, "Missing required key: $key");
        }
        $this->assertEquals('社区', $mapping['_communitySuffix']);
    }
}
