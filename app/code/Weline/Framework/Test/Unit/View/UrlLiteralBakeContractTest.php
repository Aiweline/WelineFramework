<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * Literal url-family tags bake into com_* at compile time; dynamic paths keep getUrl stubs.
 */
final class UrlLiteralBakeContractTest extends TestCase
{
    public function testCompilerGenerationBumpsForUrlLiteralBake(): void
    {
        self::assertSame('20261008-url-literal-bake-v1', Taglib::COMPILER_GENERATION);
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/View/Taglib.php');
        self::assertStringContainsString('function tryBakeUrlFamily', $source);
        self::assertStringContainsString('adjustBakedStaticInlineReplacement', $source);
    }

    public function testLiteralPathBakesWithoutGetUrlStub(): void
    {
        $template = $this->createMock(Template::class);
        $template->method('getUrl')->willReturnCallback(
            static fn(string $path, array $params = []): string => '/~site/grocery/' . ltrim($path, '/')
        );

        $baked = $this->tryBake($template, 'getUrl', '@url{}', ["@url{'product/list'}", "'product/list'", ''], []);
        self::assertSame('/~site/grocery/product/list', $baked);

        $bakedXml = $this->tryBake(
            $template,
            'getUrl',
            'tag-self-close-with-attrs',
            ['<url path="product/list"/>', '', ''],
            ['path' => 'product/list'],
        );
        self::assertSame('/~site/grocery/product/list', $bakedXml);
    }

    public function testDynamicPathKeepsNullSoStubRemains(): void
    {
        $template = $this->createMock(Template::class);
        $template->expects(self::never())->method('getUrl');

        self::assertNull(
            $this->tryBake($template, 'getUrl', '@url{}', ['@url{$route}', '$route', ''], [])
        );
        self::assertNull(
            $this->tryBake(
                $template,
                'getUrl',
                '@url{}',
                ["@url{'product/view'|['id'=>\$id]}", "'product/view'|['id'=>\$id]", ''],
                [],
            )
        );
    }

    public function testLiteralParamsBakeWithResolvedUrl(): void
    {
        $template = $this->createMock(Template::class);
        $template->method('getUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $q = $params === [] ? '' : '?' . http_build_query($params);

                return '/u/' . ltrim($path, '/') . $q;
            }
        );

        $baked = $this->tryBake(
            $template,
            'getUrl',
            '@url{}',
            ["@url{'product/view'|['id'=>1]}", "'product/view'|['id'=>1]", ''],
            [],
        );
        self::assertSame('/u/product/view?id=1', $baked);
    }

    public function testPhpContextVarExportsBakedUrl(): void
    {
        $method = new ReflectionMethod(Taglib::class, 'adjustBakedStaticInlineReplacement');
        $method->setAccessible(true);
        $raw = "@url{'product/list'}";
        $content = "<?php \$u = '{$raw}'; ?>";
        $baked = '/~site/grocery/product/list';
        [$pos, $newRaw, $replacement] = $method->invoke(
            new Taglib(),
            $content,
            (int)strpos($content, $raw),
            $raw,
            $baked,
            'url'
        );
        $result = substr($content, 0, $pos) . $replacement . substr($content, $pos + strlen($newRaw));
        self::assertSame('<?php $u = ' . var_export($baked, true) . '; ?>', $result);
        self::assertStringNotContainsString('getUrl(', $result);
    }

    /**
     * @param array<int, mixed> $tagData
     * @param array<string, mixed> $attributes
     */
    private function tryBake(
        Template $template,
        string $methodName,
        string $tagKey,
        array $tagData,
        array $attributes,
    ): ?string {
        $method = new ReflectionMethod(Taglib::class, 'tryBakeUrlFamily');
        $method->setAccessible(true);

        return $method->invoke(new Taglib(), $template, $methodName, $tagKey, $tagData, $attributes);
    }
}
