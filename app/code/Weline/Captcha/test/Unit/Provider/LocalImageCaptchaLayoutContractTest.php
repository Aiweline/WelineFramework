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
        self::assertStringContainsString('flex-wrap: nowrap', $css);
        self::assertStringContainsString('height: var(--weline-control-height', $css);
        self::assertStringContainsString('width: auto !important', $css);

        self::assertStringContainsString('ensureStylesheet', $lazyJs);
        self::assertStringContainsString('hoistFragmentStyles', $lazyJs);
    }
}
