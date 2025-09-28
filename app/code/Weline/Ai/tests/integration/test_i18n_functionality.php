<?php
declare(strict_types=1);

/**
 * 国际化功能集成测试
 * 
 * 测试场景: 多语言内容管理和翻译功能
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiI18nContent;
use Weline\Ai\Service\I18nManager;

class I18nFunctionalityIntegrationTest extends TestCase
{
    private AiI18nContent $i18nContentModel;
    private I18nManager $i18nManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->i18nContentModel = new AiI18nContent();
        $this->i18nManager = new I18nManager();
    }

    /**
     * 测试多语言内容管理
     */
    public function testMultiLanguageContentManagement(): void
    {
        // 创建中文内容
        $zhContent = new AiI18nContent();
        $zhContent->setData([
            'content_type' => 'message',
            'content_key' => 'welcome_message',
            'locale_code' => 'zh_CN',
            'content_value' => '欢迎使用AI助手'
        ]);
        $zhContent->save();
        
        // 创建英文内容
        $enContent = new AiI18nContent();
        $enContent->setData([
            'content_type' => 'message',
            'content_key' => 'welcome_message',
            'locale_code' => 'en_US',
            'content_value' => 'Welcome to AI Assistant'
        ]);
        $enContent->save();
        
        // 验证内容保存
        $this->assertGreaterThan(0, $zhContent->getId());
        $this->assertGreaterThan(0, $enContent->getId());
    }

    /**
     * 测试语言切换功能
     */
    public function testLanguageSwitching(): void
    {
        // 设置中文内容
        $this->createI18nContent('welcome_message', 'zh_CN', '欢迎使用AI助手');
        
        // 设置英文内容
        $this->createI18nContent('welcome_message', 'en_US', 'Welcome to AI Assistant');
        
        // 测试中文获取
        $zhResult = $this->i18nManager->getContent('welcome_message', 'zh_CN');
        $this->assertEquals('欢迎使用AI助手', $zhResult);
        
        // 测试英文获取
        $enResult = $this->i18nManager->getContent('welcome_message', 'en_US');
        $this->assertEquals('Welcome to AI Assistant', $enResult);
    }

    /**
     * 测试内容翻译功能
     */
    public function testContentTranslation(): void
    {
        $sourceText = 'Hello, how are you?';
        $targetLanguage = 'zh_CN';
        
        // 模拟翻译功能
        $translatedText = $this->i18nManager->translateContent($sourceText, $targetLanguage);
        
        $this->assertNotEmpty($translatedText);
        $this->assertNotEquals($sourceText, $translatedText);
    }

    /**
     * 创建国际化内容
     */
    private function createI18nContent(string $key, string $locale, string $value): AiI18nContent
    {
        $content = new AiI18nContent();
        $content->setData([
            'content_type' => 'message',
            'content_key' => $key,
            'locale_code' => $locale,
            'content_value' => $value
        ]);
        $content->save();
        
        return $content;
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->i18nContentModel->getCollection()
            ->where('content_key', 'welcome_message')
            ->delete();
        
        parent::tearDown();
    }
}
