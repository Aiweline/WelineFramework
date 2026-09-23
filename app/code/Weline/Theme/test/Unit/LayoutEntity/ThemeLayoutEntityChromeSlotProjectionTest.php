<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryScanner;
require_once dirname(__DIR__, 7) . '/vendor/autoload.php';
final class ThemeLayoutEntityChromeSlotProjectionTest extends TestCase
{
    public function testNearestOverlayKeepsAncestorChromeAndNestedSlots(): void
    {
        $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
        $filler = $class->newInstanceWithoutConstructor();
        $class->getProperty('boundaryScanner')->setValue($filler, new SlotBoundaryScanner());
        $class->getProperty('sharedChrome')->setValue($filler, (new \ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor());
        $slot = static fn(string $id, string $inner): string => '<!--@weline-slot:'.$id.'--><div class="theme-layout-entity-slot" data-slot-id="'.$id.'">'.$inner.'</div><!--@/weline-slot:'.$id.'-->';
        $projection = $class->getMethod('projectRenderedChromeScopes')->invoke($filler, [
            'channel' => $slot('footer-extras', '<aside>Channel</aside>'),
            'website' => $slot('header', '<header>'.$slot('all-menu', '<button id="hamburger-menu">Menu</button>').'</header>')
                .$slot('all-menu', '<button id="hamburger-menu">Menu</button>')
                .$slot('footer', '<footer>Website</footer>'),
        ]);
        self::assertStringContainsString('hamburger-menu', $projection['header']);
        self::assertStringContainsString('hamburger-menu', $projection['all-menu']);
        self::assertStringContainsString('Channel', $projection['footer-extras']);
        self::assertStringContainsString('Website', $projection['footer']);
    }

    public function testExplicitlyEmptyLocalSlotDoesNotFallBackToAncestorHtml(): void
    {
        $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
        $filler = $class->newInstanceWithoutConstructor();
        $class->getProperty('boundaryScanner')->setValue($filler, new SlotBoundaryScanner());
        $class->getProperty('sharedChrome')->setValue($filler, (new \ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor());
        $old = '<!--@weline-slot:footer-help-links--><div data-slot-id="footer-help-links"><a>Retired help</a></div><!--@/weline-slot:footer-help-links-->';
        $projection = $class->getMethod('projectRenderedChromeScopes')->invoke($filler,
            ['channel' => '', 'website' => $old], ['channel' => ['footer-help-links']]);
        self::assertArrayHasKey('footer-help-links', $projection);
        self::assertSame('', $projection['footer-help-links']);
    }

    public function testSelectedChildIsComposedIntoInheritedParent(): void
    {
        $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
        $filler = $class->newInstanceWithoutConstructor();
        $class->getProperty('boundaryScanner')->setValue($filler, new SlotBoundaryScanner());
        $class->getProperty('sharedChrome')->setValue($filler, (new \ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor());
        $slot = static fn(string $id, string $inner): string => '<!--@weline-slot:'.$id.'--><div data-slot-id="'.$id.'">'.$inner.'</div><!--@/weline-slot:'.$id.'-->';
        foreach (['<a>Current FAQ</a>', ''] as $current) {
            $projection = $class->getMethod('projectRenderedChromeScopes')->invoke($filler, [
                'channel' => $slot('footer-help-links', $current),
                'website' => $slot('footer', '<footer>'.$slot('footer-help-links', '<a>Retired help</a>').'</footer>'),
            ], ['channel' => ['footer-help-links']]);
            self::assertStringNotContainsString('Retired help', $projection['footer']);
            self::assertSame($current, (new SlotBoundaryScanner())->extractSlotInner($projection['footer'], 'footer-help-links'));
        }
    }

    public function testChromeOwnedNestedSlotDoesNotRequireAHeaderNamePrefix(): void
    {
        $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
        $filler = $class->newInstanceWithoutConstructor();
        $class->getProperty('boundaryScanner')->setValue($filler, new SlotBoundaryScanner());
        $class->getProperty('sharedChrome')->setValue($filler, (new \ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor());
        foreach (['all-menu', 'merchant-drawer'] as $slot) {
            $html = '<!--@weline-slot:header--><div class="theme-layout-entity-slot" data-slot-id="header"><header><div class="theme-published-slot" data-slot-id="'.$slot.'"></div></header></div><!--@/weline-slot:header-->'
                .'<!--@weline-slot:'.$slot.'--><div class="theme-layout-entity-slot" data-slot-id="'.$slot.'"><button data-widget-code="drawer">Open</button></div><!--@/weline-slot:'.$slot.'-->';
            $result = $filler->finalizePublishedChromeRenderedHtml($html, 0, true);
            preg_match('#<header>(.*?)</header>#s', $result, $header);
            self::assertStringContainsString('data-widget-code="drawer"', $header[1] ?? '');
            $page = $result . '<!--@weline-slot:content--><div data-slot-id="content">Page only</div><!--@/weline-slot:content-->';
            $projection = $class->getMethod('extractChromeInnersFromBakedHtml')->invoke($filler, $page);
            self::assertArrayHasKey($slot, $projection);
            self::assertArrayNotHasKey('content', $projection);
        }
    }
}
