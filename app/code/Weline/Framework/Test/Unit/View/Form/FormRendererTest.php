<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Form;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Form\FormRenderer;

final class FormRendererTest extends TestCase
{
    public function testMalformedAbsoluteActionIsRejectedWithoutTypeError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormRenderer::open([
            'action' => 'http://?page=&pageSize=30',
            'method' => 'get',
        ]);
    }

    public function testQueryOnlyPaginationActionRemainsValid(): void
    {
        $open = FormRenderer::open([
            'action' => '?page=&pageSize=30',
            'method' => 'get',
            'intent' => 'pagination.jump',
        ]);

        self::assertStringContainsString('action="?page=&amp;pageSize=30"', $open);
        self::assertStringContainsString('method="get"', $open);
        self::assertStringContainsString('data-weline-form-intent="pagination.jump"', $open);
        self::assertStringContainsString('</form>', FormRenderer::close());
    }

    public function testReservedLiteralAttributeValueProtectsMethodPost(): void
    {
        self::assertTrue(FormRenderer::isReservedLiteralAttributeValue('method', 'post'));
        self::assertTrue(FormRenderer::isReservedLiteralAttributeValue('method', 'get'));
        self::assertFalse(FormRenderer::isReservedLiteralAttributeValue('method', 'put'));
        self::assertFalse(FormRenderer::isReservedLiteralAttributeValue('class', 'post'));
    }

    public function testRuntimeBootstrapCoalescesMutationObserver(): void
    {
        $js = FormRenderer::runtimeBootstrap();
        self::assertStringContainsString('Weline.dom.observe', $js);
        self::assertStringContainsString('weline-form:mount', $js);
        self::assertStringContainsString('ARCH_MO_FALLBACK_START', $js);
        self::assertStringContainsString('mountAll(d);', $js);
        self::assertStringNotContainsString('requestAnimationFrame', $js);
        self::assertStringNotContainsString('flushMo', $js);
        self::assertStringNotContainsString('onFlush:flushForms,mountAll', $js);
    }
}
