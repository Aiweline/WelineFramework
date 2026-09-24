<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Seo\Service\Head\PageSeoContextResolver;

/**
 * Product/category names must outrank layout default titles (「商品详情」「分类」),
 * and Chinese-source fallback titles must not reach en_US <title> untranslated.
 */
final class EntityTitleOverLayoutDefaultContractTest extends TestCase
{
    public function testThemeObserverSkipsLayoutTitleWhenEntityTitleExists(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Theme/Observer/ControllerFetchFileBefore.php'
        );
        $start = strpos($source, 'private function publishPageTitleToSeoBag(');
        self::assertNotFalse($start);
        $body = substr($source, $start, 1600);
        self::assertStringContainsString("SeoPageProfileBag::pull()['title']", $body);
        self::assertStringContainsString("\$template->getData('seo')", $body);
        self::assertLessThan(
            strpos($body, "\$template->getData('title')"),
            strpos($body, 'return;'),
            'Entity title guard must run before the layout title is read.'
        );
    }

    public function testNonHanTitleIsReturnedUntouched(): void
    {
        $method = new ReflectionMethod(PageSeoContextResolver::class, 'translateHanTitle');
        $method->setAccessible(true);
        $resolver = new PageSeoContextResolver();

        self::assertSame('Obsidian Ruyi Pendant', $method->invoke($resolver, ' Obsidian Ruyi Pendant '));
        self::assertSame('', $method->invoke($resolver, ''));
    }

    public function testResolverTranslatesTitleBeforeContext(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Head/PageSeoContextResolver.php'
        );
        $translate = strpos($source, '$title = $this->translateHanTitle((string) $title);');
        $context = strpos($source, "'title' => (string) \$title,");
        self::assertNotFalse($translate);
        self::assertNotFalse($context);
        self::assertLessThan($context, $translate);
    }
}
