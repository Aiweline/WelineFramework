<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Context;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Observer\ControllerFetchFileAfter;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeResourceCatalog;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Ordinary source generation, Template execution and controller layout wrapping. */
final class PageSourceDependenciesTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public static function declaredLayouts(): array
    {
        return [
            'module cart' => ['app/code/Weline/Cart/view/theme/frontend/layouts/cart/default.phtml', 'cart', 'cart-summary-note', 'data-cart-content="1"'],
            'hanfu cart' => ['app/design/Weline/hanfu/frontend/layouts/cart/default.phtml', 'cart', 'cart-summary-note', 'data-cart-content="1"'],
            'module checkout' => ['app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/default.phtml', 'checkout', 'checkout-summary-note', 'data-checkout-form-host'],
            'hanfu checkout' => ['app/design/Weline/hanfu/frontend/layouts/checkout/default.phtml', 'checkout', 'checkout-summary-note', 'data-checkout-form-host'],
        ];
    }

    #[DataProvider('declaredLayouts')]
    public function testDeclaredLayoutHasAnExecutableBodyFallback(string $relative, string $type, string $slot, string $bodyMarker): void
    {
        $origin = BP . $relative;
        ObjectManager::setInstance(ThemeResourceCatalog::class, new class($origin, $type) extends ThemeResourceCatalog {
            public function __construct(private string $origin, private string $type) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            { return ['layouts/' . $this->type . '/default' => ['file_path' => $this->origin, 'layout_type' => $this->type, 'option' => 'default']]; }
        });
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $paths = new ThemeLayoutEntityPaths(sys_get_temp_dir() . '/weline-page-fallback-' . bin2hex(random_bytes(6)));
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        $materializer = new ThemeLayoutEntityMaterializer($paths, ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class), ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        $candidates = $materializer->candidatePage($identity, $type, '', [$this->node('a', $slot, 'BODY-INJECTION', 0)], [], $type);
        // The page's unrelated public head is captured too; only body discovery
        // and the actual layout/Template pipeline are under test here.
        $headMetadata = ['origin' => $origin . '.head', 'identity' => $identity->toArray(), 'resource_type' => 'partial', 'partial_type' => 'head', 'partial_option' => 'default'];
        $candidates[$paths->partialPhtml($identity, 'head')] = '<?php /* weline-source:' . base64_encode(json_encode($headMetadata, JSON_THROW_ON_ERROR)) . ' */ ?><meta name="fixture-head">';
        $page = $paths->pageLayoutPhtml($identity, $type);
        $template = Template::getInstance();
        ThemeLayoutSourceSnapshot::fromCandidates($identity, $page, $candidates)->install($template);
        $template->setData('meta', ['showHeader' => false, 'showFooter' => false]);
        $template->setData('checkout_page_subtitle', 'DYNAMIC-CONTROLLER-SUBTITLE');
        $html = $template->fetchHtml($page);
        self::assertStringContainsString($bodyMarker, $html);
        self::assertStringContainsString('<b>BODY-INJECTION</b>', $html);
        self::assertSame(1, substr_count($html, '<!DOCTYPE html>'));
        if ($type === 'checkout') { self::assertStringContainsString('DYNAMIC-CONTROLLER-SUBTITLE', $html); }
    }

    public function testDaoFooterHasOneVisibleRequiredContainer(): void
    {
        $origin = BP . 'app/design/Weline/daocharms/frontend/partials/footer/default.phtml';
        ObjectManager::setInstance(ThemeResourceCatalog::class, new class($origin) extends ThemeResourceCatalog {
            public function __construct(private string $origin) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            { return ['partials/footer/default' => ['file_path' => $this->origin, 'partial_type' => 'footer', 'option' => 'default']]; }
        });
        $this->registry->definitions['footer-container'] = $this->definition('footer-container', '<footer data-testid="footer-container">DaoCharms</footer>');
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $paths = new ThemeLayoutEntityPaths(sys_get_temp_dir() . '/weline-dao-footer-' . bin2hex(random_bytes(6)));
        $materializer = new ThemeLayoutEntityMaterializer($paths, ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class), ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        $version = new ThemeScopeVersion();
        $version->setData(['theme_id' => 4, 'scope' => 'default.default.default', 'store_mode' => 'normal', 'area' => 'frontend', 'version_id' => 31, 'lifecycle' => 'published', 'content_revision' => 2]);
        $version->setChromePayload([$this->node('f', 'footer', '', 0, 'footer-container')]);
        $candidates = $materializer->candidateChrome($version, [], ['footer' => 'default']);
        $identity = $version->toVersionIdentity();
        $template = Template::getInstance();
        ThemeLayoutSourceSnapshot::fromCandidates($identity, '', $candidates)->install($template);
        $html = $template->fetchHtml($paths->partialPhtml($identity, 'footer'));
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $wrappers = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " widget-wrapper ") and @data-widget-code="footer-container"]');
        self::assertCount(1, $wrappers);
        self::assertSame(0, $xpath->query('ancestor::*[@hidden or contains(translate(@style," ",""),"display:none")]', $wrappers->item(0))->length);
        self::assertStringContainsString('DaoCharms', $html);
    }

    public function testCartBodySlotsAreCompiledBeforeControllerHtmlAndTheLayoutWrapsOnce(): void
    {
        $root = sys_get_temp_dir() . '/weline-page-source-' . bin2hex(random_bytes(6));
        mkdir($root);
        $origin = $root . '/default.phtml';
        $bodyUri = 'Weline_Cart::templates/frontend/cart/index.phtml';
        file_put_contents($origin, '<!DOCTYPE html><html><body data-layout-shell="one"><?php if ($this->getData("meta")["content"] ?? ""): ?><?= $this->getData("meta")["content"] ?><?php elseif ($this->getData("show_body")): ?><?= $this->fetch("' . $bodyUri . '") ?><?php else: ?><i>BODY-OFF</i><?php endif; ?></body></html>');
        $catalog = new class($origin) extends ThemeResourceCatalog {
            public function __construct(private string $origin) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            {
                return ['layouts/cart/default' => ['file_path' => $this->origin, 'layout_type' => 'cart', 'option' => 'default']];
            }
        };
        ObjectManager::setInstance(ThemeResourceCatalog::class, $catalog);
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $paths = new ThemeLayoutEntityPaths($root . '/derived');
        $materializer = new ThemeLayoutEntityMaterializer($paths, ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class), ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
        $nodes = [$this->node('a', 'cart-summary-note', 'SAVED-NOTE', 0), $this->node('b', 'cart-summary-credit', 'SAVED-CREDIT', 10)];
        try {
            $candidates = $materializer->candidatePage($identity, 'cart', '', $nodes, [], 'cart');
            $page = $paths->pageLayoutPhtml($identity, 'cart');
            $snapshot = ThemeLayoutSourceSnapshot::fromCandidates($identity, $page, $candidates);
            $template = Template::getInstance();
            $snapshot->install($template);
            $body = $template->fetchHtml($bodyUri);
            self::assertStringContainsString('<b>SAVED-NOTE</b>', $body);
            self::assertStringContainsString('<b>SAVED-CREDIT</b>', $body);
            self::assertLessThan(strpos($body, '<b>SAVED-CREDIT</b>'), strpos($body, '<b>SAVED-NOTE</b>'));

            $template->setData('show_body', true);
            $data = new DataObject(['layoutType' => 'cart', 'layoutOption' => 'default', 'contentTemplate' => $bodyUri, 'layoutTemplate' => $page, 'fileName' => $bodyUri, 'content' => $body]);
            $event = new Event(['data' => $data]);
            (new ControllerFetchFileAfter())->execute($event);
            $html = (string)$data->getData('content');
            self::assertSame(1, substr_count($html, 'data-layout-shell="one"'));
            self::assertSame(1, substr_count($html, 'data-cart-content="1"'), 'Assigned controller content must suppress the literal fallback.');
            self::assertSame(1, substr_count($html, '<b>SAVED-NOTE</b>'));

            $template->setData('meta', [])->setData('show_body', false);
            $off = $template->fetchHtml($page);
            self::assertStringContainsString('BODY-OFF', $off);
            self::assertStringNotContainsString('SAVED-NOTE', $off);
            $template->setData('show_body', true);
            $fallback = $template->fetchHtml($page);
            self::assertSame(1, substr_count($fallback, 'data-cart-content="1"'));
            self::assertStringContainsString('<b>SAVED-NOTE</b>', $fallback);
            foreach (array_keys($candidates) as $path) { self::assertFileDoesNotExist($path); }

            $empty = $materializer->candidatePage($identity, 'cart', '', [['node_uid' => 'empty', 'widget_code' => '__no_widget_placements__']], [], 'cart');
            ThemeLayoutSourceSnapshot::fromCandidates($identity, $page, $empty)->install($template);
            $emptyBody = $template->fetchHtml($bodyUri);
            self::assertStringContainsString('data-cart-content="1"', $emptyBody);
            self::assertStringNotContainsString('SAVED-NOTE', $emptyBody);
            self::assertStringNotContainsString('SAVED-CREDIT', $emptyBody);
        } finally { @unlink($origin); @rmdir($root); }
    }

    public function testCapturedBodyAliasesRespectOptionTargetAndSuspendedFiber(): void
    {
        $root = sys_get_temp_dir() . '/weline-body-pins-' . bin2hex(random_bytes(6));
        mkdir($root);
        $origin = $root . '/default.phtml';
        $logical = 'Weline_Cart::templates/frontend/cart/index.phtml';
        file_put_contents($origin, '<?= $this->fetch("' . $logical . '") ?>');
        ObjectManager::setInstance(ThemeResourceCatalog::class, new class($origin) extends ThemeResourceCatalog {
            public function __construct(private string $origin) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            { return ['layouts/cart/default' => ['file_path' => $this->origin], 'layouts/cart/compact' => ['file_path' => $this->origin]]; }
        });
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $paths = new ThemeLayoutEntityPaths($root . '/derived');
        $materializer = new ThemeLayoutEntityMaterializer($paths, ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class), ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        $identity = new ThemeVersionIdentity(3, 'fixture.body.source', 'normal', 'frontend', 10, 'formal', 2);
        $one = $materializer->candidatePage($identity, 'cart', '', [$this->node('a', 'cart-summary-note', 'GLOBAL', 0)], [], 'cart');
        $two = $materializer->candidatePage($identity, 'cart', '', [$this->node('b', 'cart-summary-note', 'TARGET', 0)], [], 'cart', 'compact', 'product', 7);
        $all = array_replace($one, $two);
        foreach ($all as $path => $bytes) { @mkdir(dirname($path), 0775, true); file_put_contents($path, $bytes); }
        try {
            $global = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'cart');
            $target = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'cart', 'compact', 'product', 7);
            self::assertNotSame($global->fingerprint(), $target->fingerprint());
            self::assertNotSame($global->source($logical)['bytes'], $target->source($logical)['bytes']);
            // A later publication cannot alter either captured request's bytes.
            foreach (array_keys($all) as $path) { file_put_contents($path, '<b>LATER-PUBLICATION</b>'); }
            $renderer = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer::class);
            $render = static function (ThemeLayoutSourceSnapshot $snapshot, bool $pause) use ($logical, $renderer): string {
                Context::enter(new Context()); RequestContext::init();
                try {
                    ObjectManager::setInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer::class, $renderer);
                    $template = Template::getInstance();
                    $snapshot->install($template);
                    if ($pause) { \Fiber::suspend(); }
                    return $template->fetchHtml($logical);
                } finally { Context::leave(); }
            };
            $a = new \Fiber(fn(): string => $render($global, true));
            $b = new \Fiber(fn(): string => $render($target, false));
            $a->start(); $b->start(); $a->resume();
            self::assertStringContainsString('<b>GLOBAL</b>', $a->getReturn());
            self::assertStringNotContainsString('<b>TARGET</b>', $a->getReturn());
            self::assertStringContainsString('<b>TARGET</b>', $b->getReturn());
            self::assertStringNotContainsString('LATER-PUBLICATION', $b->getReturn());
        } finally {
            foreach (array_keys($all) as $path) { @unlink($path); for ($dir = dirname($path); str_starts_with($dir, $root . '/'); $dir = dirname($dir)) { @rmdir($dir); } }
            @unlink($origin); @rmdir($root);
        }
    }
}
