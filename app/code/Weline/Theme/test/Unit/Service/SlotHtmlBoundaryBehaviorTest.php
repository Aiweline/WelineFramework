<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\SlotHtmlOpaqueParker;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\TemplateInlineWidgetMerger;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SlotHtmlBoundaryBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        foreach ([SlotBoundaryScanner::class, SlotHtmlOpaqueParker::class, SlotRendererService::class, TemplateInlineWidgetMerger::class] as $class) {
            $path = dirname(__DIR__, 3) . '/Service/' . substr($class, strrpos($class, '\\') + 1) . '.php';
            if (!class_exists($class, false)) {
                require_once $path;
            }
            self::assertSame(realpath($path), (new \ReflectionClass($class))->getFileName());
        }
    }

    public static function slotBodies(): iterable
    {
        yield 'nested elements' => ['<div><div>old</div></div>', '</div>'];
        yield 'script closing token' => ['<script>const text="</div>";</script><span>old</span>', '</div>'];
        yield 'comment closing token' => ['<!-- literal </div> --><span>old</span>', '</div>'];
        yield 'quoted tag tokens' => ['<span title="</div> > <div>">old</span>', '</div>'];
        yield 'spaced closing tag' => ['<span>old</span>', '</DIV >'];
        yield 'textarea closing token' => ['<textarea>literal </div></textarea><span>old</span>', '</div>'];
    }

    #[DataProvider('slotBodies')]
    public function testSlotReplacementKeepsTheActualClosingTagAndFollowingSibling(string $body, string $close): void
    {
        $open = '<div data-wslot="demo" title="1 > 0">';
        $tail = '<aside>outside</aside>';
        $html = $open . $body . $close . $tail;
        $scanner = new SlotBoundaryScanner();
        $bounds = $scanner->findSlotElementBounds($html, 'demo');
        self::assertNotNull($bounds);
        self::assertSame($body, $scanner->extractWrapperInnerBySlotId($html, 'demo'));
        self::assertSame($open . '<strong>new</strong>' . $close . $tail, $scanner->replaceWrapperInner(
            $html,
            ['inner_start' => $bounds[0], 'inner_end' => $bounds[1]],
            '<strong>new</strong>',
        ));
    }

    public function testBatchWrapperReplacementUpdatesDisjointSiblingsInOnePass(): void
    {
        $scanner = new SlotBoundaryScanner();
        $html = '<main><div data-wslot="left">old-left</div><p>middle</p>'
            . '<section data-wslot="right">old-right</section></main>';
        $left = $scanner->findSlotElementBounds($html, 'left');
        $right = $scanner->findSlotElementBounds($html, 'right');
        self::assertNotNull($left);
        self::assertNotNull($right);

        self::assertSame(
            '<main><div data-wslot="left">new-left</div><p>middle</p>'
                . '<section data-wslot="right">new-right</section></main>',
            $scanner->replaceWrapperInners($html, [
                ['inner_start' => $left[0], 'inner_end' => $left[1], 'new_inner' => 'new-left'],
                ['inner_start' => $right[0], 'inner_end' => $right[1], 'new_inner' => 'new-right'],
            ]),
        );
    }

    public function testCowReplacementConsumesTheWholeNestedWidget(): void
    {
        $old = '<div class="widget-wrapper" data-widget-code="card"><div>old</div><p>old tail</p></div>';
        $new = '<div class="widget-wrapper" data-widget-code="card"><div>new</div></div>';
        self::assertSame($new . '<aside>outside</aside>', $this->replace($old . '<aside>outside</aside>', 'card', $new));
    }

    public function testCowReplacementPreservesLiteralReplacementSyntax(): void
    {
        $new = '<div class="widget-wrapper" data-widget-code="price">Price $19.99 / ${1} / \\1</div>';
        self::assertSame($new, $this->replace('<div class="widget-wrapper" data-widget-code="price">old</div>', 'price', $new));
    }

    public function testSameWidgetCodeKeepsDistinctInstancesAndUpdatesOnlyMatchingUid(): void
    {
        $firstUid = str_repeat('a', 32);
        $secondUid = str_repeat('b', 32);
        $first = '<div class="widget-wrapper" data-widget-code="faq-accordion" data-node-uid="' . $firstUid . '">First</div>';
        $second = '<div class="widget-wrapper" data-widget-code="faq-accordion" data-node-uid="' . $secondUid . '">Second</div>';
        $html = $this->replace($first, 'faq-accordion', $second, $secondUid);
        self::assertStringContainsString($first, $html);
        self::assertStringContainsString($second, $html);
        $updated = str_replace('First', 'Updated', $first);
        $html = $this->replace($html, 'faq-accordion', $updated, $firstUid);
        self::assertStringContainsString($updated, $html);
        self::assertStringContainsString($second, $html);
        self::assertSame(1, substr_count($html, $firstUid));
        self::assertSame(1, substr_count($html, $secondUid));
        $legacy = '<div class="widget-wrapper" data-widget-code="faq-accordion">Legacy</div>';
        self::assertSame($first, $this->replace($legacy, 'faq-accordion', $first, $firstUid));
    }

    public function testWishlistReplacementConsumesNestedSections(): void
    {
        $old = '<section class="header-wishlist"><section>old</section><span>tail</span></section>';
        $new = '<section class="header-wishlist">$19.99</section>';
        self::assertSame($new . '<aside>outside</aside>', $this->replace($old . '<aside>outside</aside>', 'wishlist-icon', $new));
    }

    public function testParkedWrapperReplacementDoesNotLeaveCommentDebris(): void
    {
        $parker = new SlotHtmlOpaqueParker();
        $old = '<div class="widget-wrapper" data-widget-code="card"><!-- literal </div> --><span>old</span></div>';
        $tail = '<aside>outside</aside>';
        $new = '<div class="widget-wrapper" data-widget-code="card">new</div>';
        $parked = $parker->park($old . $tail);
        self::assertSame($old . $tail, $parker->restore($parked));
        self::assertSame($new . $tail, $parker->restore($this->replace($parked, 'card', $new)));
    }

    public function testTemplateExtractionKeepsRawTextAndTheCompleteWrapper(): void
    {
        $html = '<div data-weline-template-widget="1" data-template-ref="demo"><script>const text="</div>";</script><span>old</span></div>';
        self::assertSame([['ref' => 'demo', 'html' => $html]], (new TemplateInlineWidgetMerger())->extractTemplateWidgetsFromHtml($html));
    }

    public function testPlanKeepsParkedOpaqueTemplateShellsWhenLayoutAdditionsExist(): void
    {
        $parked = '<div class="weline-template-widget widget-wrapper" data-weline-template-widget="1"'
            . ' data-template-ref="tpl:form-account-social-login:demo"'
            . ' data-widget-code="account-social-login">'
            . '<!--WELINE_SLOT_OPAQUE_WIDGET_0_abcd-->'
            . '</div>';
        $plan = (new TemplateInlineWidgetMerger())->plan(
            [['ref' => 'tpl:form-account-social-login:demo', 'html' => $parked]],
            [[
                'widget_code' => 'account-social-login',
                'widget_module' => 'Weline_Customer',
                'widget_type' => 'form',
                'sort_order' => 0,
                'config' => ['enable_google' => true],
            ]],
        );

        $kinds = array_map(static fn(array $item): string => (string)($item['kind'] ?? ''), $plan);
        self::assertContains('template', $kinds);
        self::assertContains('layout', $kinds);
        $template = null;
        foreach ($plan as $item) {
            if (($item['kind'] ?? '') === 'template') {
                $template = $item;
                break;
            }
        }
        self::assertNotNull($template);
        self::assertStringContainsString('WELINE_SLOT_OPAQUE', (string)($template['html'] ?? ''));
    }

    public static function largeSlotOpeningTags(): iterable
    {
        $doublePayload = str_repeat("> ' &quot; <span> 中文 ", 2048);
        $singlePayload = str_repeat('> " &apos; <span> 中文 ', 2048);
        yield 'double quotes and mixed-case slot attribute' => [
            '<SECTION data-payload="' . $doublePayload . '" DaTa-WsLoT="demo&amp;tools">',
        ];
        yield 'single quotes and numeric entity' => [
            "<SECTION data-payload='" . $singlePayload . "' DATA-SLOT-ID='demo&#38;tools'>",
        ];
        yield 'unquoted slot value after large quoted attribute' => [
            '<SECTION data-payload="' . $doublePayload . '" data-preview-slot=demo&#x26;tools>',
        ];
    }

    #[DataProvider('largeSlotOpeningTags')]
    public function testLargeQuotedAttributesKeepWrapperBoundsAndOriginalBytes(string $open): void
    {
        $prefix = '前缀🙂'
            . '<div title="literal <section data-wslot=demo&amp;tools>not a slot</section>">before</div>'
            . '<!-- <section data-wslot="demo&amp;tools">comment decoy</section> -->'
            . '<script>const decoy = \'<section data-wslot="demo&amp;tools">script decoy</section>\';</script>';
        $body = '正文🙂<div title="still > inside">keep</div>'
            . '<script>const close = "</SECTION>";</script><!-- literal </SECTION> -->';
        $close = '</SECTION >';
        $tail = '<aside>following sibling</aside>';
        $html = $prefix . $open . $body . $close . $tail;
        $scanner = new SlotBoundaryScanner();
        $bounds = $scanner->findSlotWrapperBounds($html, 'demo&tools');

        self::assertSame([
            'open_start' => strlen($prefix),
            'open_end' => strlen($prefix . $open),
            'inner_start' => strlen($prefix . $open),
            'inner_end' => strlen($prefix . $open . $body),
            'close_end' => strlen($prefix . $open . $body . $close),
        ], $bounds);
        self::assertSame($open, substr($html, $bounds['open_start'], $bounds['open_end'] - $bounds['open_start']));
        self::assertSame($body, substr($html, $bounds['inner_start'], $bounds['inner_end'] - $bounds['inner_start']));
        self::assertSame($close, substr($html, $bounds['inner_end'], $bounds['close_end'] - $bounds['inner_end']));
        self::assertSame($tail, substr($html, $bounds['close_end']));
        $replacement = '<strong>$19.99 / \\1 / 替换</strong>';
        self::assertSame($prefix . $open . $replacement . $close . $tail, $scanner->replaceWrapperInner($html, $bounds, $replacement));
    }

    public static function unclosedAttributeQuotes(): iterable
    {
        yield 'unclosed double quote' => ['"'];
        yield 'unclosed single quote' => ["'"];
    }

    #[DataProvider('unclosedAttributeQuotes')]
    public function testUnclosedLargeAttributeDoesNotExposeApparentSlotMarkup(string $quote): void
    {
        $html = '前缀<div data-payload=' . $quote . str_repeat('x > ', 8192)
            . '<section data-wslot=demo>not a real tag</section>';
        self::assertNull((new SlotBoundaryScanner())->findSlotWrapperBounds($html, 'demo'));
    }
    private function replace(string $html, string $code, string $replacement, string $nodeUid = ''): string
    {
        $class = new \ReflectionClass(SlotRendererService::class);
        return $class->getMethod('insertCowLayoutAdditionIntoMultipleSlotInner')->invoke(
            $class->newInstanceWithoutConstructor(), $html, [], ['widget_code' => $code, 'node_uid' => $nodeUid], $replacement,
        );
    }
}
