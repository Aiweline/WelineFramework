<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Form;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Form\FormRenderer;

final class FormRendererCaptchaSlotTest extends TestCase
{
    public function testPlaceExtensionHtmlReplacesEmptyCaptchaSlot(): void
    {
        $body = '<div class="fields">x</div>'
            . '<div data-weline-form-captcha-slot></div>'
            . '<button type="submit">Go</button>';
        $extension = '<div class="weline-captcha">challenge</div>';

        $result = FormRenderer::placeExtensionHtml($body, $extension);

        self::assertStringContainsString($extension, $result);
        self::assertStringNotContainsString('data-weline-form-captcha-slot', $result);
        self::assertTrue(
            \strpos($result, $extension) < \strpos($result, '<button type="submit">'),
            'Captcha must appear before the submit button'
        );
    }

    public function testPlaceExtensionHtmlAppendsWhenSlotMissing(): void
    {
        $body = '<button type="submit">Go</button>';
        $extension = '<div class="weline-captcha">challenge</div>';

        $result = FormRenderer::placeExtensionHtml($body, $extension);

        self::assertSame($body . $extension, $result);
    }

    public function testPlaceExtensionHtmlNoopsWhenExtensionEmpty(): void
    {
        $body = '<div data-weline-form-captcha-slot></div>';

        self::assertSame($body, FormRenderer::placeExtensionHtml($body, ''));
    }
}
