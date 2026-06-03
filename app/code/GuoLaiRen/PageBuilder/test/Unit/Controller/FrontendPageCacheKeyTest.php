<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Frontend\Page;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class FrontendPageCacheKeyTest extends TestCase
{
    public function testNormalizeHostCandidateCollapsesLoopbackAndPorts(): void
    {
        $controller = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Page::class, 'normalizeHostCandidate');
        $method->setAccessible(true);

        self::assertSame('', $method->invoke($controller, '127.0.0.1:9503'));
        self::assertSame('', $method->invoke($controller, 'https://127.0.0.1:9503/'));
        self::assertSame('', $method->invoke($controller, 'localhost:9503'));
        self::assertSame('', $method->invoke($controller, '[::1]:9503'));
        self::assertSame('p11005ce4.weline.test', $method->invoke($controller, 'p11005ce4.weline.test:9503'));
    }

    public function testViewHtmlCacheKeyChangesWhenPageUpdateTimeOrLayoutChanges(): void
    {
        $controller = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Page::class, 'buildViewHtmlCacheKey');
        $method->setAccessible(true);

        $page = new PageModel();
        $page->setData(PageModel::schema_fields_ID, 77);
        $page->setData(PageModel::schema_fields_UPDATE_TIME, '2026-06-03 10:00:00');
        $page->setData(PageModel::schema_fields_LAYOUT_CONFIG, '{"components":["hero"]}');
        $page->setData(PageModel::schema_fields_RENDER_MODE, PageModel::RENDER_MODE_THEME);

        $initial = $method->invoke($controller, $page, 'en_US', 403, 'modern');
        $same = $method->invoke($controller, $page, 'en_US', 403, 'modern');
        self::assertSame($initial, $same);

        $page->setData(PageModel::schema_fields_UPDATE_TIME, '2026-06-03 10:01:00');
        $changedByUpdateTime = $method->invoke($controller, $page, 'en_US', 403, 'modern');
        self::assertNotSame($initial, $changedByUpdateTime);

        $page->setData(PageModel::schema_fields_UPDATE_TIME, '2026-06-03 10:00:00');
        $page->setData(PageModel::schema_fields_LAYOUT_CONFIG, '{"components":["hero","gallery"]}');
        $changedByLayout = $method->invoke($controller, $page, 'en_US', 403, 'modern');
        self::assertNotSame($initial, $changedByLayout);
    }
}
