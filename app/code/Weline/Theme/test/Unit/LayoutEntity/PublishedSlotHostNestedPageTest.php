<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost as Host;

require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPublishedSlotHost.php';

final class PublishedSlotHostNestedPageTest extends TestCase
{
    private function slot(string $id, string $inner, bool $entity = false): string
    {
        return '<!--@weline-slot:' . $id . '--><div class="' . ($entity ? 'theme-layout-entity-slot' : 'theme-published-slot') . '" data-slot-id="' . $id . '">' . $inner . '</div><!--@/weline-slot:' . $id . '-->';
    }

    private function render(string $html, string $slot, string $default = ''): string
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        try {
            RequestContext::set(Host::CTX_FRAGMENTS, ['page_html' => $html, 'chrome_by_slot' => []]);
            return Host::publishedInner($slot, $default);
        } finally {
            if ($previous !== null) { Context::enter($previous); } else { Context::leave(); }
        }
    }

    public function testNestedPurchaseSlotsUseAlreadyRenderedFlatEntityChildren(): void
    {
        $info = $this->slot('product-main', '<section>Product' . $this->slot('product-purchase-actions', '') . $this->slot('product-express-payment', '') . '</section>', true);
        $actions = $this->slot('product-purchase-actions', '<button>Cart</button><button>Share</button><button>Quick buy</button><button>Help pay</button>', true);
        $payment = $this->slot('product-express-payment', '<button>PayPal</button>', true);
        $rendered = $this->render($info . $actions . $payment, 'product-main');
        foreach (['Cart', 'Share', 'Quick buy', 'Help pay', 'PayPal'] as $label) {
            self::assertSame(1, substr_count($rendered, '<button>' . $label . '</button>'));
        }
    }

    public function testMultipleNestedLevelsConsumeTheSameEntityFragments(): void
    {
        $html = $this->slot('outer', $this->slot('middle', ''), true)
            . $this->slot('middle', $this->slot('inner', ''), true)
            . $this->slot('inner', '<button>Deep action</button>', true);
        self::assertStringContainsString('<button>Deep action</button>', $this->render($html, 'outer'));
    }

    public function testExplicitEmptyFlatSlotWinsOverNestedOldDefault(): void
    {
        $html = $this->slot('outer', $this->slot('action', '<button>Old default</button>'), true)
            . $this->slot('action', "\n   ", true);
        self::assertStringNotContainsString('Old default', $this->render($html, 'outer'));
        self::assertSame('', $this->render($html, 'action', $this->slot('nested-default', '<button>Old default</button>')));
    }

    public function testExplicitEmptyParentDoesNotRestoreNestedDefaultMarkup(): void
    {
        $html = $this->slot('parent', "\n  ", true)
            . $this->slot('child', '<button>Published child</button>', true);
        $default = '<section>' . $this->slot('child', '<button>Old default</button>') . '</section>';
        self::assertSame('', $this->render($html, 'parent', $default));
    }

    public function testCycleDoesNotRepeatOrRecurseIndefinitely(): void
    {
        $html = $this->slot('outer', '<b>Outer</b>' . $this->slot('inner', ''), true)
            . $this->slot('inner', '<b>Inner</b>' . $this->slot('outer', ''), true);
        $rendered = $this->render($html, 'outer');
        self::assertSame(1, substr_count($rendered, '<b>Outer</b>'));
        self::assertSame(1, substr_count($rendered, '<b>Inner</b>'));
    }

    public function testPublishedInnerDoesNotConcatSameWidgetCodeBakeAndDefault(): void
    {
        $bake = $this->slot(
            'product-main',
            '<section data-widget-code="product-info" data-testid="storefront-product-detail">BAKE</section>',
            true,
        );
        $default = '<section data-widget-code="product-info" data-testid="storefront-product-detail">DEFAULT'
            . $this->slot('product-purchase-actions', '<button>Cart</button>')
            . '</section>';
        $rendered = $this->render($bake, 'product-main', $default);
        self::assertSame(1, substr_count($rendered, 'data-widget-code="product-info"'));
        self::assertStringContainsString('BAKE', $rendered);
        self::assertStringNotContainsString('DEFAULT', $rendered);
        self::assertTrue(Host::bakeAndDefaultShareWidgetCode(
            '<section data-widget-code="product-info">A</section>',
            '<section data-widget-code="product-info">B</section>',
        ));
    }

    /**
     * Regression 2026-09-26：content bake 仅 store-music 时不得冲掉 design 首页嵌套树。
     */
    public function testSparseStoreMusicBakeKeepsHomepageNestedDefaultTree(): void
    {
        $bake = $this->slot(
            'content',
            '<span hidden data-widget-code="store-music" data-testid="store-music"></span>',
            true,
        );
        $default = '<div class="theme-published-slot homepage-content-slot" data-slot-id="content">'
            . $this->slot('homepage-hero', '<section data-widget-code="hero-slider">HERO</section>')
            . $this->slot('homepage-featured', '<section data-widget-code="featured-products">FEAT</section>')
            . '</div>';
        $rendered = $this->render($bake, 'content', $default);
        self::assertStringContainsString('data-widget-code="store-music"', $rendered);
        self::assertStringContainsString('HERO', $rendered);
        self::assertStringContainsString('FEAT', $rendered);
        self::assertStringContainsString('homepage-hero', $rendered);
    }
}
