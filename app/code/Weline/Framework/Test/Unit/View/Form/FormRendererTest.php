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
}
