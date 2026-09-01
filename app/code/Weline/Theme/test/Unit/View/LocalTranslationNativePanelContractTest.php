<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Local translation drawer uses native panel + bin-query, not iframe embedding.
 */
final class LocalTranslationNativePanelContractTest extends TestCase
{
    public function testLocalTranslationUsesNativePanelAndBinQueryLoad(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/components/weline-local-translation.js',
        );
        $taglib = (string)file_get_contents(
            dirname(__DIR__, 4) . '/I18n/Taglib/Local.php',
        );

        self::assertStringContainsString('taglib-local-load', $js);
        self::assertStringContainsString('data-w-local-panel', $js);
        self::assertStringContainsString('data-w-local-list', $js);
        self::assertStringContainsString('w-local-translation__row', $js);
        self::assertStringContainsString('data-w-local-progress-bar', $js);
        self::assertStringContainsString('data-i18n-progress', $taglib);
        self::assertStringContainsString('w-local-translation__toolbar', $taglib);
        self::assertStringNotContainsString('w-local-translation__card', $js);
        self::assertStringContainsString("target.closest('[data-w-local-ai]')", $js);
        self::assertStringContainsString('isRealTranslation', $js);
        self::assertStringContainsString('data-w-local-base', $js);
        self::assertStringContainsString('w-local-translation__row-star', $js);
        self::assertStringContainsString('updateSourceMeta', $js);
        self::assertStringContainsString('data-i18n-ai-retranslate', $taglib);
        self::assertStringContainsString('data-w-local-ai', $js);
        self::assertStringContainsString('data-w-local-ai', $taglib);
        self::assertStringContainsString('dialogApi.confirm', $js);
        self::assertStringContainsString('event.stopPropagation()', $js);
        self::assertStringContainsString("drawerHost.dataset.wBackdrop = 'static'", $js);
        self::assertStringNotContainsString('window.confirm(', $js);
        self::assertStringNotContainsString('data-w-local-ai-spinner', $js);
        self::assertStringNotContainsString('data-w-local-spinner', $js);
        self::assertStringContainsString("panel.dataset.state = state", $js);
        self::assertStringNotContainsString('contentDocument', $js);
        self::assertStringNotContainsString('instanceof HTMLFormElement', $js);

        $ui = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/weline-ui.js',
        );
        self::assertStringContainsString("backdrop.addEventListener('pointerdown'", $ui);
        self::assertStringContainsString('ensureBodyHost', $ui);
        self::assertStringContainsString("element.closest('form') instanceof HTMLFormElement", $ui);
        self::assertStringContainsString('topOverlay() !== element', $ui);
        self::assertStringContainsString('trigger-primary1', $ui);

        self::assertStringNotContainsString('data-w-local-ai-spinner', $taglib);
        self::assertStringNotContainsString('data-w-local-spinner', $taglib);
        self::assertStringContainsString('data-w-local-panel', $taglib);
        self::assertStringNotContainsString('data-w-local-frame', $taglib);
        self::assertStringNotContainsString('isIframe', $taglib);

        $css = (string)file_get_contents(
            dirname(__DIR__, 4) . '/I18n/view/statics/css/local-translation.css',
        );
        self::assertStringContainsString('.w-local-translation-drawer', $css);
        self::assertStringContainsString('var(--weline-theme-surface-raised)', $css);
        self::assertStringContainsString('not lg full-bleed', $css);
        self::assertStringContainsString('.w-overlay[data-w-drawer-backdrop]', $css);
        self::assertStringContainsString('.w-local-translation__trigger :is(w-icon, .w-icon)', $css);
        self::assertStringContainsString('--backend-theme-primary', $css);
        self::assertStringContainsString('--weline-theme-primary', $css);
        self::assertStringContainsString('margin-inline-start', $css);
        self::assertStringNotContainsString('data-size="lg"', $taglib);
        self::assertStringContainsString('trigger-primary1', $taglib);
    }
}
