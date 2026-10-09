<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Footer link groups use details/summary so mobile can collapse the link wall.
 */
final class FooterMobileAccordionContractTest extends TestCase
{
    public function testFooterContainerUsesDetailsSummarySections(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("\$sectionTag = \$titleHtml !== '' ? 'details' : 'div'", $src);
        self::assertStringContainsString('<summary class="footer-section__title">', $src);
        self::assertStringContainsString('</<?= $sectionTag ?>>', $src);
        self::assertStringContainsString("\$sectionOpenAttr = \$sectionTag === 'details' ? ' open' : ''", $src);
        self::assertStringContainsString('data-footer-accordion="1"', $src);
    }

    public function testFooterChromeCssForcesDesktopOpenAndMobileAccordion(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/css/widgets/footer-chrome-amazon.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*min-width:\s*561px\s*\)\s*\{[\s\S]*?'
            . 'details\.footer-section\s*>\s*\.footer-section__links\s*\{[\s\S]*?display:\s*flex\s*!important/i',
            $css,
            'Desktop must force footer section links visible'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*min-width:\s*561px\s*\)\s*\{[\s\S]*?'
            . '::details-content/i',
            $css,
            'Desktop should progressively force details-content visible'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*560px\s*\)\s*\{[\s\S]*?'
            . 'details\.footer-section\s*>\s*summary\.footer-section__title/i',
            $css,
            'Mobile must style summary as accordion trigger'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*560px\s*\)\s*\{[\s\S]*?'
            . '\.footer-content(?:--cols-\d)?\s*,[\s\S]*?gap:\s*0/i',
            $css,
            'Mobile accordion rows must not keep desktop grid gap'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*560px\s*\)\s*\{[\s\S]*?'
            . 'summary\.footer-section__title\s*\{[\s\S]*?margin:\s*0/i',
            $css,
            'Mobile summary must zero out desktop title bottom margin'
        );
    }

    public function testFooterWidgetJsSyncsAccordionByViewport(): void
    {
        foreach ([
            'widget-container-footer-default-0.js',
            'widget-hanfu-container-footer-default-0.js',
        ] as $file) {
            $path = dirname(__DIR__, 2) . '/view/statics/js/widgets/' . $file;
            self::assertFileExists($path);
            $js = (string)file_get_contents($path);

            self::assertStringContainsString("matchMedia('(max-width: 560px)')", $js, $file);
            self::assertStringContainsString('data-footer-accordion', $js, $file);
            self::assertStringContainsString('el.open = true', $js, $file);
            self::assertStringContainsString('el.open = false', $js, $file);
        }
    }

    public function testHanfuDesignFooterDefaultsOpenForDesktop(): void
    {
        $path = dirname(__DIR__, 6) . '/app/design/Weline/hanfu/frontend/widgets/container/footer/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("\$sectionOpenAttr = \$sectionTag === 'details' ? ' open' : ''", $src);
        self::assertStringContainsString('data-footer-accordion="1"', $src);
    }
}
