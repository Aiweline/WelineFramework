<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Form;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Form\FormRenderer;

final class FormRendererCaptchaModeTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        parent::tearDown();
    }

    public function testPostFormDoesNotEnableCaptchaUnlessRequested(): void
    {
        $html = FormRenderer::open([
            'id' => 'ordinary-post-form',
            'method' => 'post',
        ]);

        self::assertStringContainsString('data-weline-form-captcha="off"', $html);
        self::assertSame('off', FormRenderer::current()['captcha'] ?? null);
    }

    public function testRequiredCaptchaRemainsAnExplicitOptIn(): void
    {
        $html = FormRenderer::open([
            'id' => 'protected-post-form',
            'method' => 'post',
            'captcha' => 'required',
            'intent' => 'customer.login',
        ]);

        self::assertStringContainsString('data-weline-form-captcha="required"', $html);
        self::assertSame('required', FormRenderer::current()['captcha'] ?? null);
        self::assertSame('customer.login', FormRenderer::current()['intent'] ?? null);
    }

    public function testAsyncActionForcesCaptchaOffEvenWhenRequested(): void
    {
        $html = FormRenderer::open([
            'id' => 'country-disable-form',
            'method' => 'post',
            'captcha' => 'required',
            'data-async-action' => 'country-disable',
        ]);

        self::assertStringContainsString('data-weline-form-captcha="off"', $html);
        self::assertSame('off', FormRenderer::current()['captcha'] ?? null);
    }
}
