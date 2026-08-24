<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\WidgetUiScope;

class WidgetUiScopeTest extends TestCase
{
    public function testFromCodeGeneratesStableClassAndNamespace(): void
    {
        $scope = WidgetUiScope::fromCode('theme.widget.promo_banner', 'test-uid-1');

        self::assertSame('theme.widget.promo_banner', $scope->code);
        self::assertSame('wc-theme_widget_promo_banner', $scope->cssClass);
        self::assertSame('theme_widget_promo_banner', $scope->jsNs);
        self::assertSame('test-uid-1', $scope->uid);
        self::assertSame('.wc-theme_widget_promo_banner', $scope->cssSelector());
    }

    public function testForWidgetAndComponentHelpers(): void
    {
        $widget = WidgetUiScope::forWidget('promo-banner');
        $component = WidgetUiScope::forComponent('button');

        self::assertSame('theme.widget.promo_banner', $widget->code);
        self::assertSame('theme.component.button', $component->code);
    }

    public function testEmptyCodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WidgetUiScope::fromCode('');
    }
}
