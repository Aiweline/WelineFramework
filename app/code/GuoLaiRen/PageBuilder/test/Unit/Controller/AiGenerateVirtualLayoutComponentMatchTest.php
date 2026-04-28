<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiGenerate;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 虚拟主题布局中 header/footer 为 toExportLayout 单块 { component, config }，
 * 须能从布局解析出组件条目供 AI 字段生成回退，否则会误报「组件不存在」。
 */
final class AiGenerateVirtualLayoutComponentMatchTest extends TestCase
{
    private function invokeResolve(
        array $layoutConfig,
        string $componentCode,
        string $region,
        int $index
    ): array {
        $controller = new AiGenerate(
            $this->createMock(PageModel::class),
            $this->createMock(Style::class)
        );
        $method = (new ReflectionClass(AiGenerate::class))->getMethod('resolveLayoutComponentEntryForGeneration');
        self::assertInstanceOf(ReflectionMethod::class, $method);
        $method->setAccessible(true);
        $result = $method->invoke($controller, $layoutConfig, $componentCode, $region, $index);
        self::assertIsArray($result);

        return $result;
    }

    public function testVirtualHeaderSingletonMatchesComponentCode(): void
    {
        $layout = [
            'header' => [
                'component' => 'header/ai-site-header',
                'config' => [
                    'logo' => 'https://example.com/a.png',
                    'display' => 'yes',
                ],
            ],
            'content' => [],
            'footer' => ['component' => '', 'config' => []],
        ];
        $entry = $this->invokeResolve($layout, 'header/ai-site-header', 'header', 0);
        self::assertSame('header/ai-site-header', (string)($entry['component'] ?? ''));
        self::assertArrayHasKey('config', $entry);
    }

    public function testHeaderListFormatStillResolvesByCode(): void
    {
        $layout = [
            'header' => [
                [
                    'code' => 'header/ai-site-header',
                    'config' => ['a' => '1'],
                ],
            ],
        ];
        $entry = $this->invokeResolve($layout, 'header/ai-site-header', 'header', 0);
        self::assertSame('header/ai-site-header', (string)($entry['code'] ?? $entry['component'] ?? ''));
    }
}
