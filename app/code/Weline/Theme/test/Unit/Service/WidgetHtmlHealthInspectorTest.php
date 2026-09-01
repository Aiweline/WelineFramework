<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\WidgetHtmlHealthInspector;

final class WidgetHtmlHealthInspectorTest extends TestCase
{
    private WidgetHtmlHealthInspector $inspector;

    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            eval('function __(string $words, array|string|int $args = "") { '
                . 'if (!is_array($args)) { return str_replace(["%{1}", "%{}"], (string)$args, $words); } '
                . 'foreach ($args as $k => $v) { $key = is_numeric($k) ? ((int)$k + 1) : $k; '
                . '$words = str_replace("%{" . $key . "}", (string)$v, $words); } return $words; }');
        }

        $moduleRoot = dirname(__DIR__, 3);
        if (!class_exists(WidgetHtmlHealthInspector::class, false)) {
            require_once $moduleRoot . '/Service/WidgetHtmlHealthInspector.php';
        }
        $this->inspector = new WidgetHtmlHealthInspector();
    }

    public function testBalancedHtmlHasNoIssues(): void
    {
        $html = '<section class="wc-theme_widget_hero_slider" weline-code="hero-slider"><div class="slide"><h2>Hello</h2></div></section>';
        $issues = $this->inspector->inspect($html, ['code' => 'hero-slider']);
        self::assertSame([], $issues);
        self::assertSame('ok', $this->inspector->worstSeverity($issues));
    }

    public function testDetectsUnclosedAndMismatch(): void
    {
        $unclosed = $this->inspector->inspect('<div class="a"><span>x');
        self::assertNotEmpty($unclosed);
        self::assertSame('error', $this->inspector->worstSeverity($unclosed));
        self::assertTrue($this->hasCode($unclosed, 'unclosed_tag'));

        $mismatch = $this->inspector->inspect('<div><span></div></span>');
        self::assertTrue($this->hasCode($mismatch, 'tag_mismatch') || $this->hasCode($mismatch, 'unexpected_close'));
        self::assertSame('error', $this->inspector->worstSeverity($mismatch));
    }

    public function testDetectsEmptyBrandShellAndPageTitleLeak(): void
    {
        $brand = $this->inspector->inspect('<a class="brand-item" href="#"></a>');
        self::assertTrue($this->hasCode($brand, 'empty_brand_item'));

        $leak = $this->inspector->inspect('<h2 class="slide-title">Weline_Theme</h2>');
        self::assertTrue($this->hasCode($leak, 'page_title_leak'));
        self::assertSame('warning', $this->inspector->worstSeverity($leak));
    }

    public function testDetectsDuplicateIds(): void
    {
        $html = '<div id="hero-root"><span id="hero-root">x</span></div>';
        $issues = $this->inspector->inspect($html);
        self::assertTrue($this->hasCode($issues, 'duplicate_id'));
    }

    public function testDataFormIdDoesNotCountAsDuplicateId(): void
    {
        // w:form captcha="lazy" emits data-form-id mirroring the real form id.
        $html = '<form id="checkout-delivery-quick-add-form" data-weline-form="1">'
            . '<div class="weline-captcha-lazy-host" data-weline-captcha-lazy="1"'
            . ' data-form-id="checkout-delivery-quick-add-form" data-intent="checkout.save_delivery_address"></div>'
            . '</form>';
        $issues = $this->inspector->inspect($html);
        self::assertFalse($this->hasCode($issues, 'duplicate_id'), json_encode($issues, JSON_UNESCAPED_UNICODE));
    }

    public function testDetectsPhpFatalAndWarningInWidgetOutput(): void
    {
        $fatal = '<br /><b>Fatal error</b>: Call to undefined function foo() in <b>/app/code/Weline/Theme/view/theme/frontend/widgets/banner/hero-slider/default.phtml</b> on line <b>42</b><br />';
        $fatalIssues = $this->inspector->inspect($fatal, ['code' => 'hero-slider']);
        self::assertTrue($this->hasCode($fatalIssues, 'php_fatal'), json_encode($fatalIssues, JSON_UNESCAPED_UNICODE));
        self::assertSame('error', $this->inspector->worstSeverity($fatalIssues));

        $warning = '<b>Warning</b>: Undefined variable $slides in <b>/tmp/trust-badges/default.phtml</b> on line <b>12</b><br />'
            . '<section class="wc-theme_widget_trust_badges" weline-code="theme.widget.trust_badges"><div>ok</div></section>';
        $warningIssues = $this->inspector->inspect($warning, ['code' => 'trust-badges']);
        self::assertTrue($this->hasCode($warningIssues, 'php_warning'), json_encode($warningIssues, JSON_UNESCAPED_UNICODE));
        self::assertSame('warning', $this->inspector->worstSeverity($warningIssues));
    }

    public function testComponentWelineCodeDoesNotTriggerMissingClosedRoot(): void
    {
        // Shredded bestsellers shell: only product_label component code remains.
        $html = '<div class="w-product-labels"><span class="w-product-label" weline-code="theme.component.product_label">New</span></div>';
        $issues = $this->inspector->inspect($html, ['code' => 'bestsellers']);
        self::assertFalse($this->hasCode($issues, 'missing_closed_root'), json_encode($issues, JSON_UNESCAPED_UNICODE));
        self::assertTrue($this->hasCode($issues, 'broken_widget_shell'), json_encode($issues, JSON_UNESCAPED_UNICODE));
    }

    public function testOwnWelineCodeWithoutRootClassStillReportsMissingClosedRoot(): void
    {
        $html = '<div weline-code="theme.widget.bestsellers"><p>partial</p></div>';
        $issues = $this->inspector->inspect($html, ['code' => 'bestsellers']);
        self::assertTrue($this->hasCode($issues, 'missing_closed_root'), json_encode($issues, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array{code?:string}> $issues
     */
    private function hasCode(array $issues, string $code): bool
    {
        foreach ($issues as $issue) {
            if (($issue['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }
}
