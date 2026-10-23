<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogPostTranslationService;
use Weline\Blog\Service\BlogTranslationSimulation;

/**
 * 安全闸验收：模拟供应商识别 + 翻译服务在模拟态下不得落库。
 */
final class BlogTranslationSimulationTest extends TestCase
{
    public function testFakeDetection(): void
    {
        $this->assertTrue(BlogTranslationSimulation::isFake('fake-translator', null));
        $this->assertTrue(BlogTranslationSimulation::isFake('FAKE-TRANSLATOR', 'Fake'));
        $this->assertTrue(BlogTranslationSimulation::isFake(null, 'fake'));
        $this->assertTrue(BlogTranslationSimulation::isFake('fake', ''));
        $this->assertFalse(BlogTranslationSimulation::isFake('translategemma:12b', 'ollama_webui_test'));
        $this->assertFalse(BlogTranslationSimulation::isFake('gpt-4o-mini', 'openai'));
        $this->assertFalse(BlogTranslationSimulation::isFake('', ''));
    }

    public function testPreviewShape(): void
    {
        $preview = BlogTranslationSimulation::preview([
            'slug' => 'luoshen-fr',
            'title' => '[French] 洛神赋',
            'excerpt' => '袖上白花',
            'status' => 'draft',
            'content' => str_repeat('x', 400),
        ]);

        $this->assertSame('luoshen-fr', $preview['slug']);
        $this->assertSame('[French] 洛神赋', $preview['title']);
        $this->assertSame('draft', $preview['status']);
        $this->assertSame(BlogTranslationSimulation::PREVIEW_CONTENT_LENGTH, mb_strlen($preview['content_preview']));
    }

    public function testTranslatePostRefusesWritesWhenSimulated(): void
    {
        $source = (string)file_get_contents((string)(new \ReflectionClass(BlogPostTranslationService::class))->getFileName());

        $this->assertStringContainsString('isSimulatedProvider()', $source, '服务缺少模拟供应商检测');
        $this->assertStringContainsString("if (\$simulated) {", $source, '服务缺少模拟态写库拦截');
        $this->assertMatchesRegularExpression(
            '/if \(\$simulated\) \{[^}]*\$result\[\'simulated\'\]\[\$locale\][^}]*continue;/s',
            $source,
            '模拟态分支必须在 save() 之前 continue'
        );
        $guardPos = strpos($source, "if (\$simulated) {");
        $savePos = strpos($source, '$saved = null;');
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($savePos);
        $this->assertLessThan($savePos, $guardPos, '模拟拦截必须出现在落库代码之前');
    }
}
