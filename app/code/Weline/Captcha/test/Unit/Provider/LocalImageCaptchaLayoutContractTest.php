<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;

final class LocalImageCaptchaLayoutContractTest extends TestCase
{
    public function testRenderStylesAlignImageWithInput(): void
    {
        $templatePath = \dirname(__DIR__, 3) . '/view/templates/frontend/local-image-challenge.phtml';
        $cssPath = \dirname(__DIR__, 3) . '/view/statics/css/captcha-local.css';
        $lazyJsPath = \dirname(__DIR__, 3) . '/view/statics/js/captcha-lazy.js';

        self::assertFileExists($templatePath);
        self::assertFileExists($cssPath);
        self::assertFileExists($lazyJsPath);

        $template = (string) \file_get_contents($templatePath);
        $css = (string) \file_get_contents($cssPath);
        $lazyJs = (string) \file_get_contents($lazyJsPath);

        self::assertStringContainsString('weline-captcha-row', $template);
        self::assertStringContainsString('weline-captcha-visual', $template);
        self::assertStringContainsString('weline-captcha-refresh', $template);
        self::assertStringContainsString('w-field__label', $template);
        self::assertStringContainsString('for="', $template);
        self::assertStringNotContainsString('<style>', $template, 'Layout CSS must live in captcha-local.css for lazy injection');

        self::assertStringContainsString('flex-direction: row', $css);
        self::assertStringContainsString('aspect-ratio: 168 / 40', $css);
        self::assertStringContainsString('object-fit: contain', $css);
        self::assertStringContainsString('flex: 0 1 auto', $css);
        self::assertStringContainsString('width: fit-content', $css);
        self::assertStringContainsString('height: var(--weline-control-height', $css);
        self::assertStringContainsString('width: auto !important', $css);
        self::assertStringNotContainsString('flex: 1 1 auto', $css);
        self::assertStringNotContainsString('object-fit: cover', $css);

        self::assertStringContainsString('ensureStylesheet', $lazyJs);
        self::assertStringContainsString('hoistFragmentStyles', $lazyJs);
        self::assertStringContainsString('refreshWhenShown', $lazyJs);
        self::assertStringContainsString('#cs-bind-modal', $lazyJs);
        self::assertStringContainsString('scheduleDomScan', $lazyJs);
        self::assertStringContainsString('withObserverPaused', $lazyJs);
        self::assertStringContainsString('20260910-google-ready1', $lazyJs);

        $runtime = (string) \file_get_contents(\dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php');
        self::assertStringContainsString('20260910-google-ready1', $runtime);
        self::assertStringNotContainsString('20260908-google-trust1', $runtime);
        self::assertStringNotContainsString('20260905-input-fit1', $runtime);
        self::assertStringNotContainsString('20260907-pending1', $runtime);
    }
}
