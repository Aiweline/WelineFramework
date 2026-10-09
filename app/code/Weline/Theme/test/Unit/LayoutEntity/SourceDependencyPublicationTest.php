<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBatchPublisher;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class SourceDependencyPublicationTest extends TestCase
{
    use ResolvedPhtmlFixture { setUp as private fixtureSetUp; tearDown as private fixtureTearDown; }
    private string $root;
    private ThemeLayoutEntityPaths $paths;
    private ThemeVersionIdentity $identity;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $this->root = sys_get_temp_dir() . '/weline-source-publication-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->paths = new ThemeLayoutEntityPaths($this->root);
        $this->identity = new ThemeVersionIdentity(3991, 'fixture.dependencies.only', 'normal', 'frontend', 911, 'formal', 7);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($this->root);
        $this->fixtureTearDown();
    }

    public function testPageDependencyChangeAndPageRemovalReturnOldAliasesToOriginalSource(): void
    {
        $page = $this->paths->pageLayoutPhtml($this->identity, 'cart');
        $a = $this->paths->pageSourcePhtml($this->identity, 'cart', 'default', 'Fixture_Module::a.phtml');
        $b = $this->paths->pageSourcePhtml($this->identity, 'cart', 'default', 'Fixture_Module::b.phtml');
        $other = $this->paths->pageLayoutPhtml($this->identity, 'cart', 'compact', 'product', 8);
        $otherDependency = $this->paths->pageSourcePhtml($this->identity, 'cart', 'compact', 'Fixture_Module::a.phtml', 'product', 8);
        $publisher = new ThemeLayoutEntityBatchPublisher();
        // Fixture only asserts entity promote / dependency cleanup — not Taglib com_*.
        $publisher->publish($this->identity, [
            $page => $this->bytes('<main>A</main>', ['layout_type' => 'cart', 'layout_option' => 'default']),
            $a => $this->bytes('<b>SAVED-A</b>', ['resource_type' => 'page_dependency', 'logical_path' => 'Fixture_Module::a.phtml']),
            $other => $this->bytes('<main>OTHER</main>', ['layout_type' => 'cart', 'layout_option' => 'compact']),
            $otherDependency => $this->bytes('<b>KEEP-OTHER</b>', ['resource_type' => 'page_dependency', 'logical_path' => 'Fixture_Module::a.phtml']),
        ], ['compile' => false]);
        self::assertSame('<b>SAVED-A</b>', $this->render(ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart'), 'Fixture_Module::a.phtml'));
        $publisher->publish($this->identity, [
            $page => $this->bytes('<main>B</main>', ['layout_type' => 'cart', 'layout_option' => 'default']),
            $b => $this->bytes('<b>SAVED-B</b>', ['resource_type' => 'page_dependency', 'logical_path' => 'Fixture_Module::b.phtml']),
        ], ['compile' => false]);
        $snapshot = ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart');
        self::assertSame('<b>ORIGINAL</b>', $this->render($snapshot, 'Fixture_Module::a.phtml'));
        self::assertSame('<b>SAVED-B</b>', $this->render($snapshot, 'Fixture_Module::b.phtml'));
        self::assertFileDoesNotExist($a);
        $publisher->publish($this->identity, [$page => null], ['compile' => false]);
        $missing = ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart');
        self::assertNull($missing->pagePath());
        self::assertSame('<b>ORIGINAL</b>', $this->render($missing, 'Fixture_Module::b.phtml'));
        self::assertFileDoesNotExist($b);
        self::assertSame('<b>KEEP-OTHER</b>', $this->render(ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart', 'compact', 'product', 8), 'Fixture_Module::a.phtml'));
    }

    public function testPartialDependencySurvivesSharedUseAndIsRemovedAfterItsLastRoot(): void
    {
        $header = $this->paths->partialPhtml($this->identity, 'header');
        $footer = $this->paths->partialPhtml($this->identity, 'footer');
        $search = $this->paths->partialPhtml($this->identity, 'search', 'shared');
        $logical = 'Weline_Theme::theme/frontend/partials/search/shared.phtml';
        $fetch = '<?= $this->fetch("' . $logical . '") ?>';
        $publisher = new ThemeLayoutEntityBatchPublisher();
        $publisher->publish($this->identity, [
            $header => $this->bytes($fetch, ['resource_type' => 'partial', 'partial_type' => 'header', 'partial_option' => 'default']),
            $footer => $this->bytes($fetch, ['resource_type' => 'partial', 'partial_type' => 'footer', 'partial_option' => 'default']),
            $search => $this->bytes('<b>SHARED</b>', ['resource_type' => 'partial_dependency', 'partial_type' => 'search', 'partial_option' => 'shared']),
        ], ['compile' => false]);
        $publisher->publish($this->identity, [$header => $this->bytes('<header>NO-SEARCH</header>', ['resource_type' => 'partial', 'partial_type' => 'header', 'partial_option' => 'default'])], ['compile' => false]);
        self::assertSame('<b>SHARED</b>', $this->render(ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart'), $logical));
        $publisher->publish($this->identity, [$footer => null], ['compile' => false]);
        self::assertSame('<b>ORIGINAL</b>', $this->render(ThemeLayoutSourceSnapshot::capture($this->paths, $this->identity, 'cart'), $logical));
        self::assertFileDoesNotExist($search);
        self::assertFileExists($header);
    }

    private function bytes(string $body, array $metadata): string
    {
        $metadata = array_replace(['origin' => $this->root . '/original.phtml', 'identity' => $this->identity->toArray()], $metadata);
        return '<?php /* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)) . ' */ ?>' . $body;
    }

    private function render(ThemeLayoutSourceSnapshot $snapshot, string $logical): string
    {
        $template = new Template(); $template->init();
        $template->pinSource($logical, '<b>ORIGINAL</b>', $this->root . '/original.phtml');
        $snapshot->install($template);
        return $template->fetchHtml($logical);
    }
}
