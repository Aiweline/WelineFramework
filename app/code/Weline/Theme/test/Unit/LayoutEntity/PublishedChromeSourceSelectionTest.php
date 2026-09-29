<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Block\Partials;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeResourceCatalog;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Actual Template/Block execution; only discovery inputs use isolated fixture resources. */
final class PublishedChromeSourceSelectionTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public function testNewPartialBlockUsesTheRequestCapturedBytes(): void
    {
        $origin = tempnam(sys_get_temp_dir(), 'weline-chrome-origin-') . '.phtml';
        file_put_contents($origin, '<b>ORIGINAL</b>');
        try {
            $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
            $this->snapshot($identity, $origin, '<b>SAVED-R2</b>')->install(Template::getInstance());
            $block = new Partials();
            $block->__init();
            self::assertStringContainsString('SAVED-R2', $block->fetchHtml($origin));
            self::assertStringNotContainsString('ORIGINAL', $block->fetchHtml($origin));
        } finally {
            @unlink($origin);
            @unlink(substr($origin, 0, -6));
        }
    }

    public function testCapturedPartialBytesRemainIsolatedAcrossSuspendedFibers(): void
    {
        $origin = tempnam(sys_get_temp_dir(), 'weline-chrome-fiber-') . '.phtml';
        file_put_contents($origin, '<b>UNPINNED</b>');
        $run = function (int $revision, bool $pause) use ($origin): string {
            Context::enter(new Context());
            RequestContext::init();
            try {
                $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'draft', $revision);
                $this->snapshot($identity, $origin, '<b>R' . $revision . '</b>')->install(Template::getInstance());
                $block = new Partials();
                $block->__init();
                if ($pause) { \Fiber::suspend(); }
                return $block->fetchHtml($origin);
            } finally { Context::leave(); }
        };
        try {
            $one = new \Fiber(fn(): string => $run(1, true));
            $two = new \Fiber(fn(): string => $run(2, false));
            $one->start();
            $two->start();
            $one->resume();
            self::assertStringContainsString('<b>R1</b>', $one->getReturn());
            self::assertStringContainsString('<b>R2</b>', $two->getReturn());
            self::assertNull(ThemeLayoutSourceSnapshot::current());
        } finally {
            @unlink($origin);
            @unlink(substr($origin, 0, -6));
        }
    }

    public function testPartialOutputCachePartitionsCapturedBytesEvenAtTheSameRevision(): void
    {
        $origin = tempnam(sys_get_temp_dir(), 'weline-chrome-cache-') . '.phtml';
        file_put_contents($origin, '<b>ORIGINAL</b>');
        Partials::clearAllCaches();
        $GLOBALS['chromeSourceSelectionRenders'] = 0;
        try {
            $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
            $counter = '<?php ++$GLOBALS["chromeSourceSelectionRenders"]; ?>';
            $block = new Partials();
            $block->__init();
            $render = new \ReflectionMethod(Partials::class, 'fetchCachedPartialHtml');
            $data = ['meta' => ['cache' => ['mode' => 'chrome', 'auth' => 'guest']]];
            $this->snapshot($identity, $origin, $counter . '<b>OLD</b>')->install(Template::getInstance());
            self::assertStringContainsString('<b>OLD</b>', $render->invoke($block, $origin, $data, 'frontend', 'breadcrumb', 'default'));
            self::assertStringContainsString('<b>OLD</b>', $render->invoke($block, $origin, $data, 'frontend', 'breadcrumb', 'default'));
            self::assertSame(1, $GLOBALS['chromeSourceSelectionRenders'], 'The second render must actually exercise the partial output cache.');
            $this->snapshot($identity, $origin, $counter . '<b>NEW</b>')->install(Template::getInstance());
            self::assertStringContainsString('<b>NEW</b>', $render->invoke($block, $origin, $data, 'frontend', 'breadcrumb', 'default'));
            self::assertSame(2, $GLOBALS['chromeSourceSelectionRenders']);
        } finally {
            unset($GLOBALS['chromeSourceSelectionRenders']);
            Partials::clearAllCaches();
            @unlink($origin); @unlink(substr($origin, 0, -6));
        }
    }

    public function testPublicPartialPathKeepsTheCapturedOptionAndParameters(): void
    {
        $origin = tempnam(sys_get_temp_dir(), 'weline-chrome-option-') . '.phtml';
        file_put_contents($origin, '<b>CURRENT-DEFAULT</b>');
        try {
            $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
            $snapshot = $this->snapshot($identity, $origin, '<?php $this->setData("meta", ["title" => "SAVED-TITLE"]); ?><b><?= $this->getData("meta")["title"] ?></b>', 'compact');
            $snapshot->install(Template::getInstance());
            $block = new Partials();
            $block->__init();
            self::assertSame($snapshot->partialPath('header', 'compact'), $block->getPartialsPath('frontend', 'header'));
            self::assertStringContainsString('<b>SAVED-TITLE</b>', $block->renderPartials('frontend', 'header'));
        } finally { @unlink($origin); @unlink(substr($origin, 0, -6)); }
    }

    public function testChromeCandidateCompilesFetchedPartialWithoutMovingItsPhpBranch(): void
    {
        $root = sys_get_temp_dir() . '/weline-chrome-dependency-' . bin2hex(random_bytes(6));
        mkdir($root);
        $parent = $root . '/default.phtml';
        $child = $root . '/storefront-shell.phtml';
        file_put_contents($parent, '<?php if ($this->getData("show_shell")): ?><?= $this->fetch("Weline_Theme::theme/frontend/partials/header/storefront-shell.phtml") ?><?php else: ?><i>BRANCH-OFF</i><?php endif; ?>');
        file_put_contents($child, '<nav data-wslot="header-nav-extensions"><i><?= $this->getData("runtime_label") ?></i></nav>');
        $catalog = new class($parent, $child) extends ThemeResourceCatalog {
            public function __construct(private string $parent, private string $child) {}
            public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
            {
                return [
                    'partials/header/default' => ['file_path' => $this->parent, 'layout_type' => 'header', 'option' => 'default'],
                    'partials/header/storefront-shell' => ['file_path' => $this->child, 'layout_type' => 'header', 'option' => 'storefront-shell'],
                ];
            }
        };
        ObjectManager::setInstance(ThemeResourceCatalog::class, $catalog);
        ObjectManager::setInstance(ThemePlaceableRegistry::class, $this->registry);
        $paths = new ThemeLayoutEntityPaths($root . '/derived');
        $materializer = new ThemeLayoutEntityMaterializer($paths, ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class), ObjectManager::getInstance(ThemeLayoutEntityConfigStore::class));
        $version = new ThemeScopeVersion();
        $version->setData(['theme_id' => 3, 'scope' => 'default.default.default', 'store_mode' => 'normal', 'area' => 'frontend', 'version_id' => 10, 'lifecycle' => 'published', 'content_revision' => 2]);
        $version->setChromePayload([$this->node('a', 'header-nav-extensions', 'FIRST', 0), $this->node('b', 'header-nav-extensions', 'SECOND', 10)]);
        try {
            $candidates = $materializer->candidateChrome($version, [], ['header' => 'default']);
            $identity = $version->toVersionIdentity();
            $header = $paths->partialPhtml($identity, 'header');
            $snapshot = ThemeLayoutSourceSnapshot::fromCandidates($identity, '', $candidates);
            $snapshot->install(Template::getInstance());
            $block = new Partials();
            $block->__init();
            // Source discovery is isolated, while fetch/Taglib/Block execution stays real.
            $block->pinSource('Weline_Theme::theme/frontend/partials/header/storefront-shell.phtml', (string)file_get_contents($child), $child);
            $snapshot->install($block);
            $html = $block->fetchHtml($header, ['show_shell' => true, 'runtime_label' => 'RUNTIME']);
            self::assertStringContainsString('<b>FIRST</b>', $html);
            self::assertStringContainsString('<b>SECOND</b>', $html);
            self::assertStringContainsString('<i>RUNTIME</i>', $html);
            self::assertLessThan(strpos($html, '<b>SECOND</b>'), strpos($html, '<b>FIRST</b>'));
            $off = $block->fetchHtml($header, ['show_shell' => false]);
            self::assertStringContainsString('BRANCH-OFF', $off);
            self::assertStringNotContainsString('FIRST', $off);
            foreach (array_keys($candidates) as $path) { self::assertFileDoesNotExist($path); }
        } finally {
            @unlink($parent); @unlink($child); @rmdir($root);
        }
    }

    private function snapshot(ThemeVersionIdentity $identity, string $origin, string $body, string $option = 'default'): ThemeLayoutSourceSnapshot
    {
        $path = $origin . '.derived.phtml';
        $metadata = ['origin' => $origin, 'identity' => $identity->toArray(), 'resource_type' => 'partial', 'partial_type' => 'header', 'partial_option' => $option];
        $bytes = '<?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>' . $body;
        return ThemeLayoutSourceSnapshot::fromCandidates($identity, '', [$path => $bytes]);
    }
}
