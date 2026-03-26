<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Adapter\CodeGenerationAdapter;
use Weline\Ai\Adapter\TranslationAdapter;

class AdapterTest extends TestCase
{
    private CodeGenerationAdapter $codeAdapter;

    private TranslationAdapter $translationAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codeAdapter = new CodeGenerationAdapter();
        $this->translationAdapter = new TranslationAdapter();
    }

    public function testCodeAdapterBasicInfo(): void
    {
        $this->assertSame('code_generation', $this->codeAdapter->getCode());
        $this->assertNotEmpty($this->codeAdapter->getName());
        $this->assertNotEmpty($this->codeAdapter->getDescription());
        $this->assertSame('1.0.0', $this->codeAdapter->getVersion());
    }

    public function testCodeAdapterSupportedModelTypes(): void
    {
        $types = $this->codeAdapter->getSupportedModelTypes();

        $this->assertIsArray($types);
        $this->assertContains('*', $types);
    }

    public function testCodeAdapterPromptAdaptation(): void
    {
        $adaptedPrompt = $this->codeAdapter->adaptPrompt('Create a user class', [
            'language' => 'PHP',
            'code_style' => 'clean',
            'include_comments' => true,
        ]);

        $this->assertStringContainsString('PHP', $adaptedPrompt);
        $this->assertStringContainsString('clean code principles', $adaptedPrompt);
        $this->assertStringContainsString('comments', $adaptedPrompt);
    }

    public function testCodeAdapterResponseProcessing(): void
    {
        $processed = $this->codeAdapter->processResponse("```php\n<?php\nclass User {}\n```", ['language' => 'PHP']);

        $this->assertStringContainsString('class User', $processed);
        $this->assertStringStartsWith('<?php', trim($processed));
    }

    public function testCodeAdapterParamValidation(): void
    {
        $this->assertSame([], $this->codeAdapter->validateParams([
            'language' => 'PHP',
            'code_style' => 'clean',
        ]));

        $errors = $this->codeAdapter->validateParams(['language' => 'invalid_language']);
        $this->assertNotEmpty($errors);
    }

    public function testCodeAdapterModelSupport(): void
    {
        $this->assertTrue($this->codeAdapter->supportsModel('gpt-4'));
        $this->assertTrue($this->codeAdapter->supportsModel('unknown-model'));
    }

    public function testTranslationAdapterBasicInfo(): void
    {
        $this->assertSame('translation', $this->translationAdapter->getCode());
        $this->assertNotEmpty($this->translationAdapter->getName());
        $this->assertNotEmpty($this->translationAdapter->getDescription());
        $this->assertSame('1.0.0', $this->translationAdapter->getVersion());
    }

    public function testTranslationAdapterPromptAdaptation(): void
    {
        $adaptedPrompt = $this->translationAdapter->adaptPrompt('Hello world', [
            'target_language' => '中文',
            'strategy' => 'standard',
        ]);

        $this->assertNotEmpty($adaptedPrompt);
        $this->assertStringContainsString('中文', $adaptedPrompt);
        $this->assertStringContainsString('Hello world', $adaptedPrompt);
    }

    public function testTranslationAdapterProfessionalStrategy(): void
    {
        $adaptedPrompt = $this->translationAdapter->adaptPrompt('API documentation', [
            'target_language' => '中文',
            'strategy' => 'professional',
            'context' => '技术文档',
        ]);

        $this->assertStringContainsString('技术文档', $adaptedPrompt);
    }

    public function testTranslationAdapterResponseProcessing(): void
    {
        $this->assertSame('你好世界', $this->translationAdapter->processResponse('翻译：你好世界', []));
        $this->assertSame('Hello World', $this->translationAdapter->processResponse('"Hello World"', []));
    }

    public function testTranslationAdapterParamValidation(): void
    {
        $errors = $this->translationAdapter->validateParams([]);
        $this->assertNotEmpty($errors);

        $this->assertSame([], $this->translationAdapter->validateParams([
            'target_language' => '中文',
            'strategy' => 'standard',
        ]));
    }

    public function testTranslationAdapterModelSupport(): void
    {
        $this->assertTrue($this->translationAdapter->supportsModel('gpt-4'));
        $this->assertTrue($this->translationAdapter->supportsModel('claude-3'));
        $this->assertFalse($this->translationAdapter->supportsModel('unknown-model'));
    }

    public function testAdapterParamTemplates(): void
    {
        $codeTemplate = $this->codeAdapter->getParamTemplate();
        $this->assertArrayHasKey('description', $codeTemplate);
        $this->assertArrayHasKey('fields', $codeTemplate);
        $codeFieldNames = array_column($codeTemplate['fields'], 'name');
        $this->assertContains('language', $codeFieldNames);
        $this->assertContains('code_style', $codeFieldNames);

        $translationTemplate = $this->translationAdapter->getParamTemplate();
        $this->assertArrayHasKey('target_language', $translationTemplate);
        $this->assertArrayHasKey('strategy', $translationTemplate);
    }

    public function testAdapterExamples(): void
    {
        $codeExamples = $this->codeAdapter->getExamples();
        $this->assertNotEmpty($codeExamples);
        $this->assertArrayHasKey('title', $codeExamples[0]);
        $this->assertArrayHasKey('input', $codeExamples[0]);

        $translationExamples = $this->translationAdapter->getExamples();
        $this->assertNotEmpty($translationExamples);
        $this->assertArrayHasKey('title', $translationExamples[0]);
        $this->assertArrayHasKey('input', $translationExamples[0]);
    }
}
