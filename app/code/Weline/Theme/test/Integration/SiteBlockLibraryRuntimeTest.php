<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\ParamSchemaRegistry;
use Weline\Widget\Service\WidgetPreviewService;
use Weline\Widget\Service\WidgetRegistry;

/** Exercises the installed registry and real template compiler, not source-text assertions. */
final class SiteBlockLibraryRuntimeTest extends TestCase
{
    private const CODES = ['section-heading', 'button-group', 'spacer-divider', 'single-image', 'card-grid',
        'image-gallery', 'feature-list', 'stat-grid', 'team-grid', 'step-list', 'pricing-table',
        'contact-info', 'columns', 'hero-banner'];

    public function testInstalledLibraryIsGenericAndEveryTemplateRenders(): void
    {
        $registry = ObjectManager::getInstance(WidgetRegistry::class)->getRegistry();
        $widgets = [];
        foreach ($registry as $group) {
            foreach ($group as $widget) {
                if (($widget['module'] ?? '') === 'Weline_Theme') {
                    $widgets[$widget['code']] = $widget;
                }
            }
        }
        $preview = ObjectManager::getInstance(WidgetPreviewService::class);
        $schemas = ObjectManager::getInstance(ParamSchemaRegistry::class);
        foreach (self::CODES as $code) {
            self::assertArrayHasKey($code, $widgets, $code);
            self::assertContains('*', $widgets[$code]['page_layouts'], $code);
            $html = $preview->render('Weline_Theme', $code, ['title' => '通用部件验收', 'subtitle' => '内容可配置']);
            self::assertStringNotContainsString('widget-preview-error', $html, $code);
            self::assertStringContainsString('weline-site-block', $html, $code);
            $params = $schemas->expandParams($widgets[$code]['params']);
            if (isset($params['items'])) {
                self::assertSame('array', $params['items']['type'], $code);
                self::assertNotEmpty($params['items']['item_schema'], $code);
                self::assertTrue($params['items']['sortable'], $code);
            }
        }
    }

    public function testConfiguredContentAndOrderSurviveTheRealPreviewPipeline(): void
    {
        $preview = ObjectManager::getInstance(WidgetPreviewService::class);
        $html = $preview->render('Weline_Theme', 'card-grid', [
            'columns' => '4',
            'items' => json_encode([
                ['title' => '第二项', 'text' => '保留顺序', 'link' => '/about', 'link_label' => '了解更多'],
                ['title' => '第一项', 'text' => '<script>alert(1)</script>', 'link' => 'javascript:alert(1)'],
            ]),
        ]);
        self::assertStringContainsString('columns-4', $html);
        self::assertLessThan(strpos($html, '第一项'), strpos($html, '第二项'));
        self::assertStringContainsString('href="/about"', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testColumnSlotsBelongToStableWidgetInstances(): void
    {
        $preview = ObjectManager::getInstance(WidgetPreviewService::class);
        $slots = static function (string $html): array {
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $ids = [];
            foreach ($xpath->query('//*[@data-wslot]') as $slot) $ids[] = $slot->getAttribute('data-wslot');
            return $ids;
        };
        \Weline\Theme\Taglib\Slot::clearRegisteredSlots();
        $first = $slots($preview->render('Weline_Theme', 'columns', ['node_uid' => str_repeat('a', 32)]));
        \Weline\Theme\Taglib\Slot::clearRegisteredSlots();
        $second = $slots($preview->render('Weline_Theme', 'columns', ['node_uid' => str_repeat('b', 32)]));
        \Weline\Theme\Taglib\Slot::clearRegisteredSlots();
        $updated = $slots($preview->render('Weline_Theme', 'columns', ['node_uid' => str_repeat('a', 32), 'layout' => 'wide-left']));
        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame([], array_intersect($first, $second));
        self::assertSame($first, $updated);
    }

    public function testBlankOptionalCardLinkLabelStillHasAccessibleText(): void
    {
        $html = ObjectManager::getInstance(WidgetPreviewService::class)->render('Weline_Theme', 'card-grid', [
            'items' => [['title' => '入口', 'link' => '/about', 'link_label' => '']],
        ]);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<meta charset="UTF-8">' . $html);
        $link = $dom->getElementsByTagName('a')->item(0);
        self::assertNotNull($link);
        self::assertNotSame('', trim($link->textContent));
    }

    public function testFaqAndTestimonialsDoNotInventContentAndSupportJsonConfigs(): void
    {
        $preview = ObjectManager::getInstance(WidgetPreviewService::class);
        $faq = $preview->render('Weline_Theme', 'faq-accordion', ['faqs' => json_encode([
            ['question' => '实际问题', 'answer' => '实际回答'],
        ]), 'search_enabled' => true]);
        self::assertStringContainsString('<details', $faq);
        self::assertStringContainsString('实际回答', $faq);
        self::assertStringContainsString('data-faq-search', $faq);
        $empty = $preview->render('Weline_Theme', 'testimonials', ['testimonials' => []]);
        self::assertStringNotContainsString('sb-quote', $empty);
        $quotes = $preview->render('Weline_Theme', 'testimonials', ['testimonials' => json_encode([
            ['author' => '验收引用', 'content' => '已配置内容', 'rating' => 5],
        ])]);
        self::assertStringContainsString('已配置内容', $quotes);
        self::assertStringContainsString('验收引用', $quotes);
    }
}
