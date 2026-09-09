<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Theme\Service\SlotRendererService;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';

final class SlotRendererHeaderNarrowFragmentTest extends TestCase
{
    public function testNarrowFragmentPreservesHeaderBeltAfterDomRoundtrip(): void
    {
        $header = <<<'HTML'
<header class="weline-header" data-wslot="header">
    <div class="header-site-notice">
        <div class="header-site-notice-inner">
            <div data-wslot="top-bar-rights" class="header-site-notice-links">
                <a href="/faq">帮助中心</a>
            </div>
        </div>
    </div>
    <div class="header-container">
        <div class="header-belt">
            <div data-wslot="user-area" class="header-user-area">
                <div class="header-action-item">账户</div>
            </div>
        </div>
        <div class="header-main-nav">
            <div data-wslot="category-menu">导航</div>
        </div>
    </div>
</header>
HTML;

        $html = '<body>' . $header . str_repeat('<div class="page-padding">x</div>', 40) . '</body>';
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();

        $narrow = new ReflectionMethod(SlotRendererService::class, 'narrowHtmlToSlotFragment');
        $narrow->setAccessible(true);
        $result = $narrow->invoke($service, $html, ['top-bar-rights', 'user-area']);
        self::assertIsArray($result);

        $fragment = (string)$result['fragment'];
        self::assertStringContainsString('class="header-belt"', $fragment);
        self::assertStringContainsString('class="header-container"', $fragment);

        \libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div data-weline-slot-root="1">' . $fragment . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
        );
        $processed = '';
        foreach ($doc->documentElement?->childNodes ?? [] as $child) {
            $processed .= $doc->saveHTML($child);
        }
        \libxml_clear_errors();

        self::assertStringContainsString('class="header-belt"', $processed);
        self::assertStringContainsString('class="header-container"', $processed);

        $rebuilt = (string)$result['before'] . $processed . (string)$result['after'];
        self::assertMatchesRegularExpression('/class="header-belt"/', $rebuilt);
        self::assertMatchesRegularExpression('/class="header-container"/', $rebuilt);
        self::assertStringContainsString('class="header-main-nav"', $rebuilt);
    }

    public function testNarrowSkipsWhenBoundsCrossHeaderIntoMain(): void
    {
        $html = <<<'HTML'
<body>
<header class="weline-header">
    <div data-wslot="delivery" class="header-location-slot">ADDR</div>
</header>
<div class="weline-main-content">
    <div data-wslot="content" data-wslot-append="true" class="category-layout__content-root-slot">
        <main id="category-layout-main">PRODUCTS</main>
        <section data-wslot="category-recommendations" class="category-layout__recommendations">RECO</section>
    </div>
</div>
</body>
HTML;

        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $narrow = new ReflectionMethod(SlotRendererService::class, 'narrowHtmlToSlotFragment');
        $narrow->setAccessible(true);
        $result = $narrow->invoke($service, $html, ['delivery', 'category-recommendations']);
        self::assertNull($result, 'cross-header narrow must be skipped to keep category main');
    }

}
