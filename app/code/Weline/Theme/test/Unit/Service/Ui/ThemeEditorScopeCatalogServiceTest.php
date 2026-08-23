<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Ui;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;
use Weline\Theme\Service\Ui\ThemeEditorScopeCatalogService;

final class ThemeEditorScopeCatalogServiceTest extends TestCase
{
    public function testCompatibilityAdapterDelegatesToSystemSelectorCatalog(): void
    {
        $expected = [
            'selected_scope' => 'shop.cn.default',
            'selected_identity' => ['scope_kind' => 'store'],
        ];
        $delegate = $this->createMock(ScopeSelectorCatalogInterface::class);
        $delegate->expects(self::once())
            ->method('build')
            ->with('shop.cn.default', null, ['scope_kind' => 'store'])
            ->willReturn($expected);

        $service = new ThemeEditorScopeCatalogService($delegate);

        self::assertSame($expected, $service->build(
            'shop.cn.default',
            null,
            ['scope_kind' => 'store'],
        ));
    }
}
