<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Template;
use Weline\Product\Service\PurchasePanelService;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost as SlotHost;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ProductLayoutResolveService;

final class PurchasePanelNestedSlotsTest extends TestCase
{
    public static function renderResults(): array { return [[false], [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('renderResults')]
    public function testPanelRendersPublishedNestedSlotsWithSelectedOfferAndRestoresHostContext(bool $renderFails): void
    {
        $instances = ObjectManager::getInstances();
        Context::enter(new Context());
        RequestContext::init();
        $hostIdentity = new LayoutIdentity('wide', 'default.__store__.__channel__');
        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, $hostIdentity);
        RequestContext::set(SlotHost::CTX_THEME_ID, 3);
        RequestContext::set(SlotHost::CTX_LAYOUT_TYPE, 'homepage');
        RequestContext::set(SlotHost::CTX_USE_REACTIVE, false);
        RequestContext::set('theme.layout_entity.rendered_chrome_binding', 'host-binding');
        $offer = ['product_id' => 123, 'global_offer_uuid' => 'selected-offer', 'sellable' => true, 'name' => 'Selected product'];
        $catalog = new class($offer) {
            public function __construct(private array $offer) {}
            public function publishedOffersForProduct(int $id): array { return [$this->offer]; }
        };
        $sessionFactory = new class { public function createFrontendSession(): object { return new class { public function isLoggedIn(): bool { return false; } public function getUserId(): int { return 0; } }; } };
        $labels = $this->createMock(StorefrontEavLabelResolver::class);
        $labels->method('forProduct')->willReturnSelf();
        $labels->method('canonicalizeAxisQuery')->willReturn([]);
        $selection = new StorefrontVariantSelectionService();
        $categoryLinks = new class { public function listByProductIds(...$args): array { return []; } };
        $layoutResolver = new class { public function resolveForProduct(...$args): array { return ['layout_option' => 'default', 'target_type' => 'product', 'target_id' => 123]; } };
        $template = $this->getMockBuilder(Template::class)->disableOriginalConstructor()->onlyMethods(['fetch'])->getMock();
        $template->method('fetch')->willReturn('<div data-wslot="product-purchase-actions"></div>');
        $renderer = $this->createMock(SlotRendererService::class);
        $renderer->expects(self::once())->method('processSlots')->with(self::anything(), 3, 'product', 'published', 'frontend')
            ->willReturnCallback(function () use ($template, $offer, $renderFails): string {
                self::assertSame($offer['global_offer_uuid'], $template->getData('storefront_offer')['global_offer_uuid']);
                self::assertSame('product', RequestContext::get(SlotHost::CTX_LAYOUT_TYPE));
                self::assertTrue(RequestContext::get(SlotHost::CTX_USE_REACTIVE));
                self::assertSame(123, RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY)->targetId);
                self::assertSame('default', RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY)->layoutOption);
                RequestContext::set('theme.layout_entity.rendered_chrome_binding', 'panel-binding');
                if ($renderFails) { throw new RuntimeException('fixture render failed'); }
                return '<button data-testid="product-add-to-cart"></button><button data-testid="product-share"></button>';
            });
        foreach ([StorefrontCatalogViewService::class => $catalog, StorefrontEavLabelResolver::class => $labels,
            StorefrontVariantSelectionService::class => $selection, Template::class => $template,
            SlotRendererService::class => $renderer, SessionFactory::class => $sessionFactory,
            CategoryLinkRepository::class => $categoryLinks, ProductLayoutResolveService::class => $layoutResolver] as $class => $service) {
            ObjectManager::setInstance($class, $service);
        }
        try {
            $result = (new PurchasePanelService())->render(['product_id' => 123]);
            self::assertSame(!$renderFails, $result['success'], $result['message'] ?? '');
            if (!$renderFails) {
                self::assertStringContainsString('product-add-to-cart', $result['html']);
                self::assertStringContainsString('product-share', $result['html']);
            }
            self::assertSame('homepage', RequestContext::get(SlotHost::CTX_LAYOUT_TYPE));
            self::assertFalse(RequestContext::get(SlotHost::CTX_USE_REACTIVE));
            self::assertSame($hostIdentity, RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY));
            self::assertSame('host-binding', RequestContext::get('theme.layout_entity.rendered_chrome_binding'));
        } finally {
            (new ReflectionMethod(ObjectManager::class, 'setScopedInstances'))->invoke(null, $instances);
            RequestContext::cleanup();
            Context::leave();
        }
    }
}
