<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Adapter\CodeGenerationAdapter;
use Weline\Ai\Adapter\TranslationAdapter;

/**
 * 场景适配器单元测试
 */
class AdapterTest extends TestCase
{
    private CodeGenerationAdapter $codeAdapter;
    private TranslationAdapter $translationAdapter;

    protected function setUp(): void
    {
        $this->codeAdapter = new CodeGenerationAdapter();
        $this->translationAdapter = new TranslationAdapter();
    }

    /**
     * 测试代码生成适配器基本信息
     */
    public function testCodeAdapterBasicInfo(): void
    {
        $this->assertEquals('code_generation', $this->codeAdapter->getCode());
        $this->assertEquals('代码生成适配器', $this->codeAdapter->getName());
        $this->assertNotEmpty($this->codeAdapter->getDescription());
        $this->assertEquals('1.0.0', $this->codeAdapter->getVersion());
    }

    /**
     * 测试代码生成适配器支持的模型类型
     */
    public function testCodeAdapterSupportedModelTypes(): void
    {
        $types = $this->codeAdapter->getSupportedModelTypes();
        $this->assertIsArray($types);
        $this->assertContains('chat', $types);
        $this->assertContains('completion', $types);
    }

    /**
     * 测试代码生成适配器提示词适配
     */
    public function testCodeAdapterPromptAdaptation(): void
    {
        $prompt = '创建一个用户类';
        $params = [
            'language' => 'php',
            'style' => 'psr',
            'include_comments' => true
        ];

        $adaptedPrompt = $this->codeAdapter->adaptPrompt($prompt, $params);
        
        $this->assertNotEmpty($adaptedPrompt);
        $this->assertStringContainsString('PHP', $adaptedPrompt);
        $this->assertStringContainsString('PSR', $adaptedPrompt);
        $this->assertStringContainsString('注释', $adaptedPrompt);
    }

    /**
     * 测试代码生成适配器响应处理
     */
    public function testCodeAdapterResponseProcessing(): void
    {
        $response = "```php\n<?php\nclass User {}\n```";
        $processed = $this->codeAdapter->processResponse($response, ['language' => 'php']);
        
        $this->assertStringContainsString('class User', $processed);
        $this->assertStringStartsWith('<?php', trim($processed));
    }

    /**
     * 测试代码生成适配器参数验证
     */
    public function testCodeAdapterParamValidation(): void
    {
        // 有效参数
        $validParams = [
            'language' => 'php',
            'style' => 'psr'
        ];
        $errors = $this->codeAdapter->validateParams($validParams);
        $this->assertEmpty($errors);

        // 无效语言
        $invalidParams = [
            'language' => 'invalid_language'
        ];
        $errors = $this->codeAdapter->validateParams($invalidParams);
        $this->assertNotEmpty($errors);
    }

    /**
     * 测试代码生成适配器模型支持
     */
    public function testCodeAdapterModelSupport(): void
    {
        $this->assertTrue($this->codeAdapter->supportsModel('gpt-4'));
        $this->assertTrue($this->codeAdapter->supportsModel('claude-3'));
        $this->assertTrue($this->codeAdapter->supportsModel('codex'));
        $this->assertFalse($this->codeAdapter->supportsModel('unknown-model'));
    }

    /**
     * 测试翻译适配器基本信息
     */
    public function testTranslationAdapterBasicInfo(): void
    {
        $this->assertEquals('translation', $this->translationAdapter->getCode());
        $this->assertEquals('翻译适配器', $this->translationAdapter->getName());
        $this->assertNotEmpty($this->translationAdapter->getDescription());
        $this->assertEquals('1.0.0', $this->translationAdapter->getVersion());
    }

    /**
     * 测试翻译适配器提示词适配
     */
    public function testTranslationAdapterPromptAdaptation(): void
    {
        $prompt = 'Hello world';
        $params = [
            'target_language' => '中文',
            'strategy' => 'standard'
        ];

        $adaptedPrompt = $this->translationAdapter->adaptPrompt($prompt, $params);
        
        $this->assertNotEmpty($adaptedPrompt);
        $this->assertStringContainsString('中文', $adaptedPrompt);
        $this->assertStringContainsString($prompt, $adaptedPrompt);
    }

    /**
     * 测试翻译适配器专业翻译
     */
    public function testTranslationAdapterProfessionalStrategy(): void
    {
        $prompt = 'API documentation';
        $params = [
            'target_language' => '中文',
            'strategy' => 'professional',
            'context' => '技术文档'
        ];

        $adaptedPrompt = $this->translationAdapter->adaptPrompt($prompt, $params);
        
        $this->assertStringContainsString('专业', $adaptedPrompt);
        $this->assertStringContainsString('技术文档', $adaptedPrompt);
    }

    /**
     * 测试翻译适配器响应处理
     */
    public function testTranslationAdapterResponseProcessing(): void
    {
        // 测试带前缀的响应
        $response = '翻译：你好世界';
        $processed = $this->translationAdapter->processResponse($response, []);
        $this->assertEquals('你好世界', $processed);

        // 测试带引号的响应
        $response = '"Hello World"';
        $processed = $this->translationAdapter->processResponse($response, []);
        $this->assertEquals('Hello World', $processed);
    }

    /**
     * 测试翻译适配器参数验证
     */
    public function testTranslationAdapterParamValidation(): void
    {
        // 缺少目标语言
        $invalidParams = [];
        $errors = $this->translationAdapter->validateParams($invalidParams);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('目标语言', $errors[0]);

        // 有效参数
        $validParams = [
            'target_language' => '中文',
            'strategy' => 'standard'
        ];
        $errors = $this->translationAdapter->validateParams($validParams);
        $this->assertEmpty($errors);
    }

    /**
     * 测试翻译适配器模型支持
     */
    public function testTranslationAdapterModelSupport(): void
    {
        $this->assertTrue($this->translationAdapter->supportsModel('gpt-4'));
        $this->assertTrue($this->translationAdapter->supportsModel('claude-3'));
        $this->assertTrue($this->translationAdapter->supportsModel('gpt-3.5-turbo'));
        $this->assertFalse($this->translationAdapter->supportsModel('unknown-model'));
    }

    /**
     * 测试参数模板
     */
    public function testAdapterParamTemplates(): void
    {
        // 代码生成适配器参数模板
        $codeTemplate = $this->codeAdapter->getParamTemplate();
        $this->assertIsArray($codeTemplate);
        $this->assertArrayHasKey('language', $codeTemplate);
        $this->assertArrayHasKey('style', $codeTemplate);

        // 翻译适配器参数模板
        $translationTemplate = $this->translationAdapter->getParamTemplate();
        $this->assertIsArray($translationTemplate);
        $this->assertArrayHasKey('target_language', $translationTemplate);
        $this->assertArrayHasKey('strategy', $translationTemplate);
    }

    /**
     * 测试示例
     */
    public function testAdapterExamples(): void
    {
        // 代码生成适配器示例
        $codeExamples = $this->codeAdapter->getExamples();
        $this->assertIsArray($codeExamples);
        $this->assertNotEmpty($codeExamples);
        $this->assertArrayHasKey('title', $codeExamples[0]);
        $this->assertArrayHasKey('input', $codeExamples[0]);

        // 翻译适配器示例
        $translationExamples = $this->translationAdapter->getExamples();
        $this->assertIsArray($translationExamples);
        $this->assertNotEmpty($translationExamples);
        $this->assertArrayHasKey('title', $translationExamples[0]);
        $this->assertArrayHasKey('input', $translationExamples[0]);
    }
}

