<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\WidgetTranslationService;

/**
 * Composer toolbar labels must translate when widget locale switches (e.g. English).
 */
final class WidgetComposerToolbarI18nContractTest extends TestCase
{
    public function testEnglishWidgetDictionaryTranslatesComposerTools(): void
    {
        $service = new WidgetTranslationService();
        $en = $service->getWidgetTranslationsForLocales(['en_US'])['en_US'] ?? [];

        $this->assertSame('Emoji', $en['表情'] ?? null);
        $this->assertSame('Image', $en['图片'] ?? null);
        $this->assertSame('File', $en['文件'] ?? null);
        $this->assertSame('Screenshot', $en['截图'] ?? null);
        $this->assertSame('Chat tools', $en['聊天工具'] ?? null);
    }

    public function testWidgetJsRefreshesComposerToolLabelsOnLocaleChange(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/customer-service.js'
        );
        $this->assertStringContainsString('function updateWidgetLocaleText()', $js);
        $this->assertStringContainsString("data-cs-tool", $js);
        $this->assertStringContainsString("__('表情')", $js);
        $this->assertStringContainsString("__('图片')", $js);
        $this->assertStringContainsString("__('文件')", $js);
        $this->assertStringContainsString("__('截图')", $js);
        $this->assertStringContainsString("__('聊天工具')", $js);
        $this->assertMatchesRegularExpression(
            '/function updateWidgetLocaleText\(\)[\s\S]*composerLabels[\s\S]*__\(\'表情\'\)[\s\S]*data-cs-tool/',
            $js
        );
    }
}
